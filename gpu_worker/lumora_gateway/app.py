"""
Gateway de Lumora: expone ComfyUI (Z-Image Turbo) a Lumora por el túnel de Cloudflare.

Todas las rutas piden  Authorization: Bearer <LUMORA_GPU_TOKEN>.

    GET    /health              estado de ComfyUI, VRAM, cola y Ollama
    GET    /models              modelos que sabe generar
    POST   /generate            encola una imagen → {id, status, seed, ...} (202)
    GET    /jobs/{id}           estado / progreso estimado
    GET    /jobs/{id}/image     PNG terminado
    DELETE /jobs/{id}           Lumora ya la guardó: se borra de la PC

/generate responde enseguida y Lumora consulta /jobs/{id}: así ningún request
queda abierto más de ~100 s, que es donde Cloudflare corta.

Correr con:
    uvicorn lumora_gateway.app:app --host 127.0.0.1 --port 8766 --timeout-keep-alive 120
"""

import asyncio
import contextlib
import hmac
import logging
import time
import uuid
from typing import Annotated

import httpx
from fastapi import Depends, FastAPI, Header, HTTPException, Request
from fastapi.responses import Response
from pydantic import BaseModel, Field

from . import __version__
from .comfy import ComfyClient, OllamaClient
from .config import Settings, get_settings
from .jobs import JobManager, QueueFull
from .workflows import MODELS, GenerationRequest, InvalidRequest

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s: %(message)s")


def create_app(settings: Settings | None = None, transport: httpx.AsyncBaseTransport | None = None) -> FastAPI:
    settings = settings or get_settings()

    @contextlib.asynccontextmanager
    async def lifespan(app: FastAPI):
        http = httpx.AsyncClient(transport=transport)
        comfy = ComfyClient(settings.comfy_url, http, client_id=f"lumora-{uuid.uuid4().hex[:8]}")
        ollama = OllamaClient(settings.ollama_url, http)
        manager = JobManager(settings, comfy, ollama)
        manager.start()
        app.state.manager = manager
        app.state.started_at = time.time()
        try:
            yield
        finally:
            await manager.stop()
            await http.aclose()

    # Sin /docs ni /openapi.json: el gateway queda expuesto a internet por el túnel.
    app = FastAPI(
        title="Lumora GPU Gateway",
        version=__version__,
        lifespan=lifespan,
        docs_url=None,
        redoc_url=None,
        openapi_url=None,
    )

    def auth(authorization: Annotated[str | None, Header()] = None) -> None:
        if not settings.token:
            # Sin token configurado no se atiende a nadie.
            raise HTTPException(500, "LUMORA_GPU_TOKEN no está configurado en la PC.")
        if not authorization or not authorization.lower().startswith("bearer "):
            raise HTTPException(401, "Falta el token.")
        if not hmac.compare_digest(authorization[7:].strip(), settings.token):
            raise HTTPException(401, "Token inválido.")

    def manager_of(request: Request) -> JobManager:
        return request.app.state.manager

    Manager = Annotated[JobManager, Depends(manager_of)]

    class GenerateBody(BaseModel):
        model: str = "z-image-turbo"
        prompt: str = Field(min_length=1, max_length=4000)
        negative_prompt: str = Field(default="", max_length=2000)
        width: int = 1024
        height: int = 1024
        seed: int | None = None
        steps: int | None = None

    @app.get("/health", dependencies=[Depends(auth)])
    async def health(manager: Manager, request: Request):
        info: dict = {
            "status": "ok",
            "gateway_version": __version__,
            "uptime_seconds": round(time.time() - request.app.state.started_at),
            "queue": {
                "pending": manager.pending_count(),
                "running": manager.current is not None,
                "avg_seconds": round(manager.avg_seconds(), 1) if manager.avg_seconds() else None,
            },
            "models": list(MODELS),
            "models_in_vram": manager.models_loaded,
        }
        # En paralelo y con poco margen para Ollama: en Windows, una conexión
        # rechazada (Ollama apagado) tarda ~2 s en fallar.
        gpu, ollama = await asyncio.gather(
            manager.comfy.vram(),
            manager.ollama.loaded_models(timeout=0.5),
            return_exceptions=True,
        )
        if isinstance(gpu, BaseException):
            info["status"] = "degraded"
            info["comfyui"] = f"sin respuesta: {gpu.__class__.__name__}"
            info["gpu"] = None
        else:
            info["gpu"] = gpu
            info["comfyui"] = "ok"
        # Ollama es opcional para Lumora.
        info["ollama_models"] = None if isinstance(ollama, BaseException) else ollama
        return info

    @app.get("/models", dependencies=[Depends(auth)])
    async def models():
        return [
            {
                "key": m.key,
                "label": m.label,
                "default_steps": m.default_steps,
                "max_steps": m.max_steps,
                "min_side": m.min_side,
                "max_side": m.max_side,
                "side_multiple": m.side_multiple,
            }
            for m in MODELS.values()
        ]

    @app.post("/generate", status_code=202, dependencies=[Depends(auth)])
    async def generate(body: GenerateBody, manager: Manager):
        # Con ComfyUI cerrado se responde 503: Lumora lo toma como "placa no
        # disponible" y reintenta más tarde, en vez de dar la imagen por fallida.
        try:
            await manager.comfy.system_stats()
        except Exception as exc:  # noqa: BLE001
            raise HTTPException(503, "ComfyUI no está abierto en la PC.") from exc
        try:
            job = manager.submit(GenerationRequest(**body.model_dump()))
        except InvalidRequest as exc:
            raise HTTPException(422, str(exc)) from exc
        except QueueFull as exc:
            raise HTTPException(429, str(exc)) from exc
        return job.public(manager.position(job), manager.avg_seconds())

    def find(manager: JobManager, job_id: str):
        job = manager.get(job_id)
        if job is None:
            raise HTTPException(404, "No existe ese trabajo (¿se reinició la PC?).")
        return job

    @app.get("/jobs/{job_id}", dependencies=[Depends(auth)])
    async def job_status(job_id: str, manager: Manager):
        job = find(manager, job_id)
        return job.public(manager.position(job), manager.avg_seconds())

    @app.get("/jobs/{job_id}/image", dependencies=[Depends(auth)])
    async def job_image(job_id: str, manager: Manager):
        job = find(manager, job_id)
        if job.status != "done":
            raise HTTPException(409, f"La imagen todavía no está lista ({job.status}).")
        data = await manager.image_bytes(job)
        return Response(
            data,
            media_type="image/png",
            headers={"X-Seed": str(job.seed), "Cache-Control": "no-store"},
        )

    @app.delete("/jobs/{job_id}", status_code=204, dependencies=[Depends(auth)])
    async def job_forget(job_id: str, manager: Manager):
        job = find(manager, job_id)
        if job.status in ("queued", "running"):
            raise HTTPException(409, "El trabajo todavía está en curso.")
        manager.forget(job)
        return Response(status_code=204)

    return app


app = create_app()
