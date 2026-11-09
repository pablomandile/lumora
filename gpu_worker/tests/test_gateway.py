"""Tests del gateway con un ComfyUI y un Ollama falsos (no hace falta GPU)."""

from __future__ import annotations

import json
import time
from dataclasses import replace

import httpx
import pytest
from fastapi.testclient import TestClient

from lumora_gateway.app import create_app
from lumora_gateway.config import Settings
from lumora_gateway.workflows import GenerationRequest, InvalidRequest, build_workflow

TOKEN = "secreto-de-prueba"
PNG = b"\x89PNG\r\n\x1a\nfake"
AUTH = {"Authorization": f"Bearer {TOKEN}"}


def settings(**over) -> Settings:
    base = Settings(
        token=TOKEN,
        comfy_url="http://comfy",
        ollama_url="http://ollama",
        poll_interval=0.01,
        idle_free_seconds=0,
    )
    return replace(base, **over)


class FakeBackends:
    """Simula las rutas de ComfyUI y de Ollama que usa el gateway."""

    def __init__(self, fail: bool = False, vram_free_gb: float = 10.0, finish_after: int = 2, comfy_down: bool = False):
        self.fail = fail
        self.comfy_down = comfy_down
        self.finish_after = finish_after
        self.vram_free = vram_free_gb * 1024**3
        self.prompts: dict[str, dict] = {}
        self.history_calls = 0
        self.unloaded: list[str] = []

    def handler(self, request: httpx.Request) -> httpx.Response:
        host, path = request.url.host, request.url.path
        if host == "ollama":
            if path == "/api/ps":
                return httpx.Response(200, json={"models": [{"name": "qwen3:8b"}]})
            if path == "/api/generate":
                self.unloaded.append(json.loads(request.content)["model"])
                return httpx.Response(200, json={})
        if host != "ollama" and self.comfy_down:
            raise httpx.ConnectError("ComfyUI cerrado")
        if path == "/system_stats":
            return httpx.Response(200, json={
                "system": {"comfyui_version": "0.30.0"},
                "devices": [{"name": "cuda:0 NVIDIA GeForce RTX 3060", "vram_total": 12 * 1024**3, "vram_free": self.vram_free}],
            })
        if path == "/prompt":
            body = json.loads(request.content)
            pid = f"p{len(self.prompts) + 1}"
            self.prompts[pid] = body["prompt"]
            return httpx.Response(200, json={"prompt_id": pid, "number": 1, "node_errors": {}})
        if path.startswith("/history/"):
            pid = path.rsplit("/", 1)[1]
            self.history_calls += 1
            if self.history_calls < self.finish_after:
                return httpx.Response(200, json={})  # todavía corriendo
            if self.fail:
                return httpx.Response(200, json={pid: {"outputs": {}, "status": {
                    "status_str": "error", "completed": False,
                    "messages": [["execution_error", {"node_type": "KSampler", "exception_message": "CUDA out of memory"}]],
                }}})
            prefix = self.prompts[pid]["10"]["inputs"]["filename_prefix"]
            return httpx.Response(200, json={pid: {
                "outputs": {"10": {"images": [{"filename": prefix.split("/")[1] + "_00001_.png", "subfolder": "lumora", "type": "output"}]}},
                "status": {"status_str": "success", "completed": True, "messages": []},
            }})
        if path == "/view":
            return httpx.Response(200, content=PNG, headers={"content-type": "image/png"})
        if path in ("/free", "/interrupt"):
            return httpx.Response(200, json={})
        return httpx.Response(404)


def client_for(fake: FakeBackends, **over) -> TestClient:
    app = create_app(settings(**over), transport=httpx.MockTransport(fake.handler))
    return TestClient(app)


def wait_for(client: TestClient, job_id: str, timeout: float = 5.0) -> dict:
    end = time.time() + timeout
    while time.time() < end:
        data = client.get(f"/jobs/{job_id}", headers=AUTH).json()
        if data["status"] in ("done", "failed"):
            return data
        time.sleep(0.02)
    raise AssertionError("el trabajo no terminó")


# -- workflow ---------------------------------------------------------------

def test_workflow_uses_request_values_and_configured_files():
    req = GenerationRequest(model="z-image-turbo", prompt="  un faro al atardecer ", width=1344, height=768, seed=42)
    wf, seed = build_workflow(req, settings(zimage_unet="z_bf16.safetensors"), "abc")

    assert seed == 42
    assert wf["1"]["inputs"]["unet_name"] == "z_bf16.safetensors"
    assert wf["2"]["inputs"]["type"] == "lumina2"
    assert wf["5"]["inputs"]["text"] == "un faro al atardecer"
    assert wf["7"]["inputs"] == {"width": 1344, "height": 768, "batch_size": 1}
    assert wf["8"]["inputs"]["steps"] == 9
    assert wf["8"]["inputs"]["cfg"] == 1
    assert wf["10"]["inputs"]["filename_prefix"] == "lumora/abc"


def test_random_seed_when_missing():
    req = GenerationRequest(model="z-image-turbo", prompt="x", width=1024, height=1024)
    _, s1 = build_workflow(req, settings(), "a")
    _, s2 = build_workflow(req, settings(), "b")
    assert s1 != s2


@pytest.mark.parametrize("over, msg", [
    ({"width": 1000}, "múltiplo"),
    ({"height": 4096}, "entre"),
    ({"model": "flux"}, "desconocido"),
    ({"prompt": "   "}, "vacío"),
    ({"steps": 99}, "steps"),
])
def test_invalid_requests(over, msg):
    data = {"model": "z-image-turbo", "prompt": "x", "width": 1024, "height": 1024} | over
    with pytest.raises(InvalidRequest, match=msg):
        build_workflow(GenerationRequest(**data), settings(), "id")


# -- API --------------------------------------------------------------------

def test_requires_token():
    with client_for(FakeBackends()) as c:
        assert c.get("/health").status_code == 401
        assert c.get("/health", headers={"Authorization": "Bearer otro"}).status_code == 401


def test_refuses_everything_without_configured_token():
    with client_for(FakeBackends(), token="") as c:
        assert c.get("/health", headers=AUTH).status_code == 500


def test_health_reports_gpu_and_ollama():
    with client_for(FakeBackends()) as c:
        data = c.get("/health", headers=AUTH).json()
    assert data["status"] == "ok"
    assert data["gpu"]["vram_total_gb"] == 12.0
    assert data["ollama_models"] == ["qwen3:8b"]
    assert data["models"] == ["z-image-turbo"]


def test_health_degraded_when_comfy_is_down():
    def handler(request: httpx.Request) -> httpx.Response:
        raise httpx.ConnectError("apagado")

    app = create_app(settings(), transport=httpx.MockTransport(handler))
    with TestClient(app) as c:
        data = c.get("/health", headers=AUTH).json()
    assert data["status"] == "degraded"
    assert data["gpu"] is None


def test_full_generation_cycle():
    fake = FakeBackends()
    with client_for(fake) as c:
        r = c.post("/generate", headers=AUTH, json={"prompt": "un gato", "width": 1024, "height": 1024, "seed": 7})
        assert r.status_code == 202
        job = r.json()
        assert job["seed"] == 7

        assert c.get(f"/jobs/{job['id']}/image", headers=AUTH).status_code in (200, 409)

        done = wait_for(c, job["id"])
        assert done["status"] == "done"
        assert done["progress"] == 1.0

        img = c.get(f"/jobs/{job['id']}/image", headers=AUTH)
        assert img.status_code == 200
        assert img.content == PNG
        assert img.headers["x-seed"] == "7"

        assert c.delete(f"/jobs/{job['id']}", headers=AUTH).status_code == 204
        assert c.get(f"/jobs/{job['id']}", headers=AUTH).status_code == 404

    # Había VRAM de sobra: no se tocó Ollama.
    assert fake.unloaded == []


def test_unloads_ollama_when_vram_is_short():
    fake = FakeBackends(vram_free_gb=2)
    with client_for(fake) as c:
        job = c.post("/generate", headers=AUTH, json={"prompt": "x"}).json()
        wait_for(c, job["id"])
    assert fake.unloaded == ["qwen3:8b"]


def test_comfy_errors_are_reported():
    with client_for(FakeBackends(fail=True)) as c:
        job = c.post("/generate", headers=AUTH, json={"prompt": "x"}).json()
        done = wait_for(c, job["id"])
    assert done["status"] == "failed"
    assert "CUDA out of memory" in done["error"]


def test_invalid_generate_returns_422():
    with client_for(FakeBackends()) as c:
        r = c.post("/generate", headers=AUTH, json={"prompt": "x", "width": 1000})
    assert r.status_code == 422
    assert "múltiplo" in r.json()["detail"]


def test_generate_returns_503_when_comfyui_is_closed():
    with client_for(FakeBackends(comfy_down=True)) as c:
        r = c.post("/generate", headers=AUTH, json={"prompt": "x"})
    assert r.status_code == 503
    assert "ComfyUI" in r.json()["detail"]


def test_queue_limit():
    with client_for(FakeBackends(finish_after=10**6), max_queue=1, job_timeout_seconds=5) as c:
        c.post("/generate", headers=AUTH, json={"prompt": "uno"})
        r = c.post("/generate", headers=AUTH, json={"prompt": "dos"})
    assert r.status_code == 429


def test_unknown_job_is_404():
    with client_for(FakeBackends()) as c:
        assert c.get("/jobs/nada", headers=AUTH).status_code == 404


def test_no_public_docs():
    with client_for(FakeBackends()) as c:
        assert c.get("/docs").status_code == 404
        assert c.get("/openapi.json").status_code == 404


def test_job_reports_effective_steps():
    with client_for(FakeBackends()) as c:
        job = c.post("/generate", headers=AUTH, json={"prompt": "x"}).json()
    assert job["steps"] == 9
