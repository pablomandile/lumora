"""Tests del gateway con un ComfyUI y un Ollama falsos (no hace falta GPU)."""

from __future__ import annotations

import json
import os
import time
from dataclasses import replace
from pathlib import Path

import httpx
import pytest
from fastapi.testclient import TestClient

from lumora_gateway.app import create_app
from lumora_gateway.config import Settings
from lumora_gateway.workflows import EDIT_PREFIX, GenerationRequest, InvalidRequest, build_workflow

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

    def __init__(
        self,
        fail: bool = False,
        vram_free_gb: float = 10.0,
        finish_after: int = 2,
        comfy_down: bool = False,
        input_dir: Path | None = None,
    ):
        self.fail = fail
        self.input_dir = input_dir
        self.uploads: list[tuple[str, str]] = []
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
        if path == "/upload/image":
            # Multipart armado por httpx: alcanza con buscar el nombre y la subcarpeta.
            body = request.content
            name = body.split(b'filename="', 1)[1].split(b'"', 1)[0].decode()
            subfolder = body.split(b'name="subfolder"\r\n\r\n', 1)[1].split(b"\r\n", 1)[0].decode()
            self.uploads.append((subfolder, name))
            if self.input_dir is not None:
                (self.input_dir / subfolder).mkdir(parents=True, exist_ok=True)
                (self.input_dir / subfolder / name).write_bytes(b"x")
            return httpx.Response(200, json={"name": name, "subfolder": subfolder, "type": "input"})
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
            save = next(n for n in self.prompts[pid].values() if n["class_type"] == "SaveImage")
            prefix = save["inputs"]["filename_prefix"]
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
    assert data["models"] == ["z-image-turbo", "qwen-image-edit"]


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


# -- edición (Qwen Image Edit) ------------------------------------------------

A = "a" * 40 + ".jpg"
B = "b" * 40 + ".png"
C = "c" * 40 + ".webp"


def edit_request(images: list[str], **over) -> GenerationRequest:
    data = {"model": "qwen-image-edit", "prompt": " poné la cara en el flyer ", "width": 944, "height": 1184, "images": images}
    return GenerationRequest(**(data | over))


def test_edit_workflow_with_three_images():
    wf, _ = build_workflow(edit_request([A, B, C], seed=5), settings(qwen_edit_unet="otro.gguf"), "job")

    assert wf["1"]["inputs"]["unet_name"] == "otro.gguf"
    assert wf["6"]["inputs"]["lora_name"].startswith("Qwen-Image-Edit-2511-Lightning-4steps")
    assert [wf[n]["inputs"]["image"] for n in ("7", "8", "9")] == [
        f"lumora_inputs/{A}", f"lumora_inputs/{B}", f"lumora_inputs/{C}",
    ]
    # La imagen 1 se escala al tamaño pedido sin recortar.
    assert wf["10"]["class_type"] == "ImageScale"
    assert {k: wf["10"]["inputs"][k] for k in ("width", "height", "crop")} == {
        "width": 944, "height": 1184, "crop": "disabled",
    }
    # La instrucción va con el prefijo que conserva el resto de la imagen.
    assert wf["11"]["inputs"]["prompt"] == EDIT_PREFIX + "poné la cara en el flyer"
    assert wf["12"]["inputs"]["prompt"] == ""
    assert wf["16"]["inputs"]["steps"] == 4
    assert wf["16"]["inputs"]["seed"] == 5
    assert wf["18"]["inputs"]["filename_prefix"] == "lumora/job"


def test_edit_workflow_prunes_unused_image_slots():
    wf, _ = build_workflow(edit_request([A]), settings(), "job")

    assert "8" not in wf and "9" not in wf
    for enc in ("11", "12"):
        assert wf[enc]["inputs"]["image1"] == ["10", 0]
        assert "image2" not in wf[enc]["inputs"] and "image3" not in wf[enc]["inputs"]
    # Ningún nodo quedó apuntando a uno podado.
    refs = {v[0] for node in wf.values() for v in node["inputs"].values() if isinstance(v, list)}
    assert refs <= set(wf)


def test_edit_workflow_with_two_images_keeps_image2():
    wf, _ = build_workflow(edit_request([A, B]), settings(), "job")
    assert "8" in wf and "9" not in wf
    assert wf["11"]["inputs"]["image2"] == ["8", 0]
    assert "image3" not in wf["11"]["inputs"]


@pytest.mark.parametrize("over, msg", [
    ({"images": []}, "al menos 1"),
    ({"images": [A, B, C, A]}, "hasta 3"),
    ({"images": ["../../secreto.png"]}, "inválido"),
    ({"images": ["A" * 40 + ".jpg"]}, "inválido"),
    ({"steps": 9}, "steps"),
])
def test_invalid_edit_requests(over, msg):
    with pytest.raises(InvalidRequest, match=msg):
        build_workflow(edit_request(**({"images": [A]} | over)), settings(), "id")


def test_text_to_image_model_rejects_images():
    req = GenerationRequest(model="z-image-turbo", prompt="x", width=1024, height=1024, images=[A])
    with pytest.raises(InvalidRequest, match="no acepta"):
        build_workflow(req, settings(), "id")


def test_upload_input_is_named_by_content(tmp_path):
    fake = FakeBackends(input_dir=tmp_path)
    jpg = b"\xff\xd8\xff\xe0" + b"foto" * 10
    with client_for(fake, comfy_input_dir=str(tmp_path)) as c:
        r1 = c.post("/inputs", headers=AUTH, content=jpg)
        r2 = c.post("/inputs", headers=AUTH, content=jpg)

    assert r1.status_code == 201
    name = r1.json()["name"]
    assert name.endswith(".jpg") and len(name) == 44
    # Reintentar la subida no duplica nada.
    assert r2.json()["name"] == name
    assert fake.uploads == [("lumora_inputs", name)] * 2


@pytest.mark.parametrize("body, status", [
    (b"", 422),
    (b"<?php echo 1; ?>", 422),
    (b"\x89PNG\r\n\x1a\n" + b"0" * 2000, 413),
])
def test_upload_input_rejects_bad_files(body, status):
    with client_for(FakeBackends(), max_input_bytes=1000) as c:
        assert c.post("/inputs", headers=AUTH, content=body).status_code == status


def test_upload_input_requires_token():
    with client_for(FakeBackends()) as c:
        assert c.post("/inputs", content=PNG).status_code == 401


def test_full_edit_cycle_deletes_the_inputs(tmp_path):
    fake = FakeBackends(input_dir=tmp_path)
    with client_for(fake, comfy_input_dir=str(tmp_path)) as c:
        face = c.post("/inputs", headers=AUTH, content=b"\xff\xd8\xff" + b"cara").json()["name"]
        flyer = c.post("/inputs", headers=AUTH, content=PNG).json()["name"]
        assert (tmp_path / "lumora_inputs" / face).exists()

        r = c.post("/generate", headers=AUTH, json={
            "model": "qwen-image-edit", "prompt": "poné mi cara", "width": 1024, "height": 1024,
            "images": [flyer, face],
        })
        assert r.status_code == 202
        assert r.json()["steps"] == 4
        done = wait_for(c, r.json()["id"])

    assert done["status"] == "done"
    sent = next(iter(fake.prompts.values()))
    assert sent["7"]["inputs"]["image"] == f"lumora_inputs/{flyer}"
    assert sent["8"]["inputs"]["image"] == f"lumora_inputs/{face}"
    # Las fotos no quedan en la PC.
    assert not (tmp_path / "lumora_inputs" / face).exists()
    assert not (tmp_path / "lumora_inputs" / flyer).exists()


def test_inputs_shared_with_a_pending_job_are_kept(tmp_path):
    fake = FakeBackends(input_dir=tmp_path, finish_after=10**6)
    with client_for(fake, comfy_input_dir=str(tmp_path), job_timeout_seconds=5) as c:
        name = c.post("/inputs", headers=AUTH, content=PNG).json()["name"]
        body = {"model": "qwen-image-edit", "prompt": "x", "width": 1024, "height": 1024, "images": [name]}
        first = c.post("/generate", headers=AUTH, json=body).json()
        c.post("/generate", headers=AUTH, json=body)

        manager = c.app.state.manager
        manager._release_inputs(manager.get(first["id"]))
        assert (tmp_path / "lumora_inputs" / name).exists()


def test_sweep_removes_only_old_unused_inputs(tmp_path):
    folder = tmp_path / "lumora_inputs"
    folder.mkdir()
    old, new = folder / A, folder / B
    old.write_bytes(b"x")
    new.write_bytes(b"x")
    two_hours_ago = time.time() - 7200
    os.utime(old, (two_hours_ago, two_hours_ago))

    with client_for(FakeBackends(), comfy_input_dir=str(tmp_path)) as c:
        c.app.state.manager._sweep_inputs(time.time())

    assert not old.exists()
    assert new.exists()


def test_progress_uses_the_estimate_of_each_model():
    with client_for(FakeBackends()) as c:
        manager = c.app.state.manager
        assert manager.avg_seconds("qwen-image-edit") == 90.0
        assert manager.avg_seconds("z-image-turbo") == 16.0
        manager._remember_duration("qwen-image-edit", 70.0)
        manager._remember_duration("z-image-turbo", 14.0)
        assert manager.avg_seconds("qwen-image-edit") == 70.0
        assert c.get("/health", headers=AUTH).json()["queue"]["avg_seconds_by_model"] == {
            "qwen-image-edit": 70.0, "z-image-turbo": 14.0,
        }


def test_models_endpoint_reports_images():
    with client_for(FakeBackends()) as c:
        models = {m["key"]: m for m in c.get("/models", headers=AUTH).json()}
    assert models["qwen-image-edit"]["max_images"] == 3
    assert models["qwen-image-edit"]["min_images"] == 1
    assert models["z-image-turbo"]["max_images"] == 0
