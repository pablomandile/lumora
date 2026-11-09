"""Cliente mínimo de la API HTTP de ComfyUI y de Ollama."""

from __future__ import annotations

from typing import Any

import httpx


class ComfyError(RuntimeError):
    pass


class ComfyClient:
    def __init__(self, base_url: str, client: httpx.AsyncClient, client_id: str):
        self.base_url = base_url
        self.http = client
        self.client_id = client_id

    async def system_stats(self) -> dict[str, Any]:
        r = await self.http.get(f"{self.base_url}/system_stats", timeout=5)
        r.raise_for_status()
        return r.json()

    async def queue(self) -> dict[str, Any]:
        r = await self.http.get(f"{self.base_url}/queue", timeout=5)
        r.raise_for_status()
        return r.json()

    async def submit(self, workflow: dict[str, Any]) -> str:
        r = await self.http.post(
            f"{self.base_url}/prompt",
            json={"prompt": workflow, "client_id": self.client_id},
            timeout=30,
        )
        if r.status_code >= 400:
            # ComfyUI devuelve el detalle del error de validación en el JSON.
            raise ComfyError(f"ComfyUI rechazó el workflow ({r.status_code}): {r.text[:500]}")
        data = r.json()
        if data.get("node_errors"):
            raise ComfyError(f"Errores en nodos: {data['node_errors']}")
        return data["prompt_id"]

    async def history(self, prompt_id: str) -> dict[str, Any] | None:
        r = await self.http.get(f"{self.base_url}/history/{prompt_id}", timeout=10)
        r.raise_for_status()
        return r.json().get(prompt_id)

    async def view(self, filename: str, subfolder: str, folder_type: str = "output") -> bytes:
        r = await self.http.get(
            f"{self.base_url}/view",
            params={"filename": filename, "subfolder": subfolder, "type": folder_type},
            timeout=60,
        )
        r.raise_for_status()
        return r.content

    async def interrupt(self) -> None:
        await self.http.post(f"{self.base_url}/interrupt", timeout=5)

    async def free(self) -> None:
        """Descarga los modelos de la VRAM."""
        await self.http.post(
            f"{self.base_url}/free",
            json={"unload_models": True, "free_memory": True},
            timeout=15,
        )

    async def vram(self) -> dict[str, Any]:
        stats = await self.system_stats()
        devices = stats.get("devices") or []
        dev = devices[0] if devices else {}
        gb = 1024**3
        return {
            "device": dev.get("name"),
            "vram_total_gb": round((dev.get("vram_total") or 0) / gb, 2),
            "vram_free_gb": round((dev.get("vram_free") or 0) / gb, 2),
            "comfyui_version": (stats.get("system") or {}).get("comfyui_version"),
        }


class OllamaClient:
    def __init__(self, base_url: str, client: httpx.AsyncClient):
        self.base_url = base_url
        self.http = client

    async def loaded_models(self, timeout: float = 3) -> list[str]:
        r = await self.http.get(f"{self.base_url}/api/ps", timeout=timeout)
        r.raise_for_status()
        return [m.get("name") or m.get("model") for m in r.json().get("models", [])]

    async def unload(self, model: str) -> None:
        await self.http.post(
            f"{self.base_url}/api/generate",
            json={"model": model, "keep_alive": 0},
            timeout=30,
        )
