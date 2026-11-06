"""Cola de generaciones: procesa de a una, convive con escribelo en la misma placa."""

from __future__ import annotations

import asyncio
import contextlib
import logging
import os
import time
import uuid
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

from .comfy import ComfyClient, ComfyError, OllamaClient
from .config import Settings
from .workflows import GenerationRequest, build_workflow, validate

log = logging.getLogger("lumora.jobs")


class QueueFull(RuntimeError):
    pass


@dataclass
class Job:
    id: str
    request: GenerationRequest
    status: str = "queued"  # queued | running | done | failed
    seed: int | None = None
    prompt_id: str | None = None
    error: str | None = None
    image: dict[str, str] | None = None  # filename/subfolder/type en ComfyUI
    created_at: float = field(default_factory=time.time)
    started_at: float | None = None
    finished_at: float | None = None

    def public(self, position: int | None, avg_seconds: float | None) -> dict[str, Any]:
        now = time.time()
        progress = None
        if self.status == "done":
            progress = 1.0
        elif self.status == "running" and self.started_at and avg_seconds:
            # Estimación según lo que tardaron las últimas generaciones.
            progress = round(min(0.95, (now - self.started_at) / avg_seconds), 2)

        return {
            "id": self.id,
            "status": self.status,
            "model": self.request.model,
            "width": self.request.width,
            "height": self.request.height,
            "seed": self.seed,
            "steps": self.request.steps,
            "position": position,
            "progress": progress,
            "error": self.error,
            "queued_seconds": round((self.started_at or now) - self.created_at, 2),
            "run_seconds": round((self.finished_at or now) - self.started_at, 2) if self.started_at else None,
        }


class JobManager:
    def __init__(self, settings: Settings, comfy: ComfyClient, ollama: OllamaClient):
        self.settings = settings
        self.comfy = comfy
        self.ollama = ollama
        self.jobs: dict[str, Job] = {}
        self.queue: asyncio.Queue[str] = asyncio.Queue()
        self.current: str | None = None
        self.durations: list[float] = []
        self.last_activity = time.time()
        self.models_loaded = False
        self._tasks: list[asyncio.Task] = []

    # -- ciclo de vida ------------------------------------------------------

    def start(self) -> None:
        self._tasks = [
            asyncio.create_task(self._worker(), name="lumora-worker"),
            asyncio.create_task(self._housekeeping(), name="lumora-housekeeping"),
        ]

    async def stop(self) -> None:
        for t in self._tasks:
            t.cancel()
        for t in self._tasks:
            with contextlib.suppress(asyncio.CancelledError):
                await t

    # -- API ---------------------------------------------------------------

    def submit(self, req: GenerationRequest) -> Job:
        pending = sum(1 for j in self.jobs.values() if j.status in ("queued", "running"))
        if pending >= self.settings.max_queue:
            raise QueueFull("La cola está llena, probá en unos minutos.")

        job_id = uuid.uuid4().hex
        # Valida y arma el workflow ya, para rechazar pedidos inválidos con 422.
        spec = validate(req)
        _, seed = build_workflow(req, self.settings, job_id)
        req.seed = seed
        req.steps = req.steps or spec.default_steps
        job = Job(id=job_id, request=req, seed=seed)
        self.jobs[job_id] = job
        self.queue.put_nowait(job_id)
        return job

    def get(self, job_id: str) -> Job | None:
        return self.jobs.get(job_id)

    def position(self, job: Job) -> int | None:
        if job.status != "queued":
            return None
        queued = sorted(
            (j for j in self.jobs.values() if j.status == "queued"),
            key=lambda j: j.created_at,
        )
        return next((i + 1 for i, j in enumerate(queued) if j.id == job.id), None)

    def avg_seconds(self) -> float | None:
        return sum(self.durations) / len(self.durations) if self.durations else None

    def pending_count(self) -> int:
        return sum(1 for j in self.jobs.values() if j.status in ("queued", "running"))

    async def image_bytes(self, job: Job) -> bytes:
        assert job.image is not None
        return await self.comfy.view(job.image["filename"], job.image["subfolder"], job.image["type"])

    def forget(self, job: Job) -> None:
        """Lumora ya guardó la imagen: se borra el archivo y el registro."""
        self._delete_output(job)
        self.jobs.pop(job.id, None)

    # -- trabajo -----------------------------------------------------------

    async def _worker(self) -> None:
        while True:
            job_id = await self.queue.get()
            job = self.jobs.get(job_id)
            if job is None or job.status != "queued":
                continue
            self.current = job_id
            try:
                await self._run(job)
            except Exception as exc:  # noqa: BLE001 — cualquier falla se informa al cliente
                log.exception("Falló la generación %s", job_id)
                job.status = "failed"
                job.error = str(exc)[:500] or exc.__class__.__name__
            finally:
                job.finished_at = time.time()
                self.current = None
                self.last_activity = time.time()

    async def _run(self, job: Job) -> None:
        job.status = "running"
        job.started_at = time.time()

        await self._make_room()

        workflow, _ = build_workflow(job.request, self.settings, job.id)
        job.prompt_id = await self.comfy.submit(workflow)
        self.models_loaded = True

        deadline = time.time() + self.settings.job_timeout_seconds
        while True:
            entry = await self.comfy.history(job.prompt_id)
            if entry:
                status = entry.get("status") or {}
                if status.get("status_str") == "error":
                    raise ComfyError(_comfy_error_message(status))
                image = _first_image(entry.get("outputs") or {})
                if image:
                    job.image = image
                    job.status = "done"
                    self._remember_duration(time.time() - job.started_at)
                    return
                if status.get("completed"):
                    raise ComfyError("ComfyUI terminó pero no devolvió ninguna imagen.")
            if time.time() > deadline:
                with contextlib.suppress(Exception):
                    await self.comfy.interrupt()
                raise ComfyError("La generación tardó demasiado y se canceló.")
            await asyncio.sleep(self.settings.poll_interval)

    async def _make_room(self) -> None:
        """Si escribelo dejó modelos de Ollama en la VRAM, se le pide que los suelte."""
        if not self.settings.unload_ollama:
            return
        try:
            vram = await self.comfy.vram()
            if vram["vram_free_gb"] >= self.settings.min_free_vram_gb:
                return
            for model in await self.ollama.loaded_models():
                log.info("Pidiendo a Ollama que descargue %s para liberar VRAM", model)
                await self.ollama.unload(model)
        except Exception as exc:  # noqa: BLE001 — si Ollama no responde, se sigue igual
            log.warning("No se pudo liberar VRAM de Ollama: %s", exc)

    def _remember_duration(self, seconds: float) -> None:
        self.durations = (self.durations + [seconds])[-10:]

    # -- mantenimiento -----------------------------------------------------

    async def _housekeeping(self) -> None:
        while True:
            await asyncio.sleep(15)
            now = time.time()

            for job in list(self.jobs.values()):
                if job.finished_at and now - job.finished_at > self.settings.job_ttl_seconds:
                    self.forget(job)

            idle = self.settings.idle_free_seconds
            if (
                idle > 0
                and self.models_loaded
                and self.current is None
                and self.queue.empty()
                and now - self.last_activity > idle
            ):
                try:
                    await self.comfy.free()
                    self.models_loaded = False
                    log.info("VRAM liberada tras %ss sin uso", idle)
                except Exception as exc:  # noqa: BLE001
                    log.warning("No se pudo liberar la VRAM de ComfyUI: %s", exc)

    def _delete_output(self, job: Job) -> None:
        out = self.settings.comfy_output_dir
        if not out or not job.image:
            return
        path = Path(out) / job.image.get("subfolder", "") / job.image["filename"]
        with contextlib.suppress(OSError):
            if path.resolve().is_relative_to(Path(out).resolve()):
                os.remove(path)


def _first_image(outputs: dict[str, Any]) -> dict[str, str] | None:
    for node_output in outputs.values():
        for img in node_output.get("images") or []:
            if img.get("type", "output") == "output":
                return {
                    "filename": img["filename"],
                    "subfolder": img.get("subfolder", ""),
                    "type": img.get("type", "output"),
                }
    return None


def _comfy_error_message(status: dict[str, Any]) -> str:
    for kind, data in status.get("messages") or []:
        if kind == "execution_error":
            node = data.get("node_type", "?")
            return f"Error en ComfyUI ({node}): {data.get('exception_message', '').strip()[:400]}"
    return "ComfyUI informó un error al generar."
