"""Configuración del gateway, leída de variables de entorno (o de gpu_worker/.env)."""

from __future__ import annotations

import os
from dataclasses import dataclass, field
from pathlib import Path

from dotenv import load_dotenv

BASE_DIR = Path(__file__).resolve().parent.parent
load_dotenv(BASE_DIR / ".env")


def _bool(name: str, default: bool) -> bool:
    value = os.environ.get(name)
    if value is None or value == "":
        return default
    return value.strip().lower() in {"1", "true", "yes", "si", "sí", "on"}


def _int(name: str, default: int) -> int:
    value = os.environ.get(name)
    return int(value) if value not in (None, "") else default


def _float(name: str, default: float) -> float:
    value = os.environ.get(name)
    return float(value) if value not in (None, "") else default


@dataclass(frozen=True)
class Settings:
    # Token compartido con Laravel (LUMORA_GPU_TOKEN en el .env de Lumora).
    token: str = field(default_factory=lambda: os.environ.get("LUMORA_GPU_TOKEN", ""))

    comfy_url: str = field(default_factory=lambda: os.environ.get("COMFY_URL", "http://127.0.0.1:8188").rstrip("/"))
    # Carpeta "output" de ComfyUI. Si está definida, el gateway borra las
    # imágenes una vez que Lumora las descargó.
    comfy_output_dir: str = field(default_factory=lambda: os.environ.get("COMFY_OUTPUT_DIR", ""))
    # Carpeta "input" de ComfyUI. Si está definida, el gateway borra las
    # imágenes de entrada (fotos de los usuarios) cuando ya no hacen falta.
    comfy_input_dir: str = field(default_factory=lambda: os.environ.get("COMFY_INPUT_DIR", ""))
    # Tamaño máximo de una imagen de entrada (Lumora ya las manda reducidas).
    max_input_bytes: int = field(default_factory=lambda: _int("MAX_INPUT_BYTES", 20 * 1024 * 1024))
    # Las entradas que ningún trabajo usa se borran pasado este tiempo.
    input_ttl_seconds: int = field(default_factory=lambda: _int("INPUT_TTL_SECONDS", 3600))

    ollama_url: str = field(default_factory=lambda: os.environ.get("OLLAMA_URL", "http://127.0.0.1:11434").rstrip("/"))
    # Si la VRAM libre es menor a esto (GB) antes de generar, se le pide a
    # Ollama que descargue los modelos que tiene en memoria.
    min_free_vram_gb: float = field(default_factory=lambda: _float("MIN_FREE_VRAM_GB", 9.0))
    unload_ollama: bool = field(default_factory=lambda: _bool("UNLOAD_OLLAMA", True))

    # Segundos sin trabajos después de los cuales se libera la VRAM de ComfyUI
    # (para que escribelo tenga la placa libre). 0 = nunca.
    idle_free_seconds: int = field(default_factory=lambda: _int("IDLE_FREE_SECONDS", 300))

    # Tiempo máximo de una generación antes de darla por fallida.
    job_timeout_seconds: int = field(default_factory=lambda: _int("JOB_TIMEOUT_SECONDS", 600))
    # Cuánto se recuerdan los trabajos terminados (para que Lumora los descargue).
    job_ttl_seconds: int = field(default_factory=lambda: _int("JOB_TTL_SECONDS", 3600))
    max_queue: int = field(default_factory=lambda: _int("MAX_QUEUE", 20))
    poll_interval: float = field(default_factory=lambda: _float("POLL_INTERVAL", 1.0))

    # Archivos de Z-Image Turbo dentro de ComfyUI/models/...
    zimage_unet: str = field(default_factory=lambda: os.environ.get("ZIMAGE_UNET", "z_image_turbo_int8_convrot.safetensors"))
    zimage_clip: str = field(default_factory=lambda: os.environ.get("ZIMAGE_CLIP", "qwen_3_4b_fp8_mixed.safetensors"))
    zimage_vae: str = field(default_factory=lambda: os.environ.get("ZIMAGE_VAE", "ae.safetensors"))

    # Qwen-Image-Edit-2511 (GGUF, necesita el custom node ComfyUI-GGUF). La LoRA
    # Lightning está apareada con esta versión del unet: cambiar uno obliga a cambiar la otra.
    qwen_edit_unet: str = field(default_factory=lambda: os.environ.get("QWEN_EDIT_UNET", "qwen-image-edit-2511-Q4_K_M.gguf"))
    qwen_edit_lora: str = field(
        default_factory=lambda: os.environ.get("QWEN_EDIT_LORA", "Qwen-Image-Edit-2511-Lightning-4steps-V1.0-bf16.safetensors")
    )
    qwen_clip: str = field(default_factory=lambda: os.environ.get("QWEN_CLIP", "qwen_2.5_vl_7b_fp8_scaled.safetensors"))
    qwen_vae: str = field(default_factory=lambda: os.environ.get("QWEN_VAE", "qwen_image_vae.safetensors"))


def get_settings() -> Settings:
    return Settings()
