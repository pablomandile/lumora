"""Modelos disponibles y armado del workflow de ComfyUI para cada pedido."""

from __future__ import annotations

import copy
import json
import random
from dataclasses import dataclass
from pathlib import Path
from typing import Any

from .config import Settings

WORKFLOW_DIR = Path(__file__).resolve().parent / "templates"

MAX_SEED = 2**53 - 1


class InvalidRequest(ValueError):
    """El pedido no se puede generar (tamaño, pasos, modelo...)."""


@dataclass(frozen=True)
class ModelSpec:
    key: str
    label: str
    template: str
    # ids de nodo dentro del template
    unet_node: str
    clip_node: str
    vae_node: str
    prompt_node: str
    negative_node: str
    latent_node: str
    sampler_node: str
    save_node: str
    default_steps: int
    max_steps: int
    min_side: int = 256
    max_side: int = 2048
    side_multiple: int = 16


MODELS: dict[str, ModelSpec] = {
    "z-image-turbo": ModelSpec(
        key="z-image-turbo",
        label="Z-Image Turbo",
        template="z_image_turbo.json",
        unet_node="1",
        clip_node="2",
        vae_node="3",
        prompt_node="5",
        negative_node="6",
        latent_node="7",
        sampler_node="8",
        save_node="10",
        default_steps=9,
        max_steps=20,
    ),
}


@dataclass
class GenerationRequest:
    model: str
    prompt: str
    width: int
    height: int
    seed: int | None = None
    steps: int | None = None
    negative_prompt: str = ""


def _load_template(name: str) -> dict[str, Any]:
    with open(WORKFLOW_DIR / name, encoding="utf-8") as fh:
        return json.load(fh)


def validate(req: GenerationRequest) -> ModelSpec:
    spec = MODELS.get(req.model)
    if spec is None:
        raise InvalidRequest(f"Modelo desconocido: {req.model}")

    if not req.prompt or not req.prompt.strip():
        raise InvalidRequest("El prompt está vacío.")
    if len(req.prompt) > 4000:
        raise InvalidRequest("El prompt es demasiado largo (máximo 4000 caracteres).")

    for name, side in (("width", req.width), ("height", req.height)):
        if not spec.min_side <= side <= spec.max_side:
            raise InvalidRequest(f"{name} debe estar entre {spec.min_side} y {spec.max_side}.")
        if side % spec.side_multiple:
            raise InvalidRequest(f"{name} debe ser múltiplo de {spec.side_multiple}.")

    if req.steps is not None and not 1 <= req.steps <= spec.max_steps:
        raise InvalidRequest(f"steps debe estar entre 1 y {spec.max_steps}.")

    if req.seed is not None and not 0 <= req.seed <= MAX_SEED:
        raise InvalidRequest("seed fuera de rango.")

    return spec


def build_workflow(req: GenerationRequest, settings: Settings, job_id: str) -> tuple[dict[str, Any], int]:
    """Devuelve (workflow en formato API de ComfyUI, seed usada)."""
    spec = validate(req)
    wf = copy.deepcopy(_load_template(spec.template))
    seed = req.seed if req.seed is not None else random.randint(0, MAX_SEED)

    if spec.key == "z-image-turbo":
        wf[spec.unet_node]["inputs"]["unet_name"] = settings.zimage_unet
        wf[spec.clip_node]["inputs"]["clip_name"] = settings.zimage_clip
        wf[spec.vae_node]["inputs"]["vae_name"] = settings.zimage_vae

    wf[spec.prompt_node]["inputs"]["text"] = req.prompt.strip()
    wf[spec.negative_node]["inputs"]["text"] = (req.negative_prompt or "").strip()
    wf[spec.latent_node]["inputs"].update({"width": req.width, "height": req.height, "batch_size": 1})
    wf[spec.sampler_node]["inputs"].update({"seed": seed, "steps": req.steps or spec.default_steps})
    wf[spec.save_node]["inputs"]["filename_prefix"] = f"lumora/{job_id}"

    return wf, seed
