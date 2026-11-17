"""Modelos disponibles y armado del workflow de ComfyUI para cada pedido."""

from __future__ import annotations

import copy
import json
import random
import re
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

from .config import Settings

WORKFLOW_DIR = Path(__file__).resolve().parent / "templates"

MAX_SEED = 2**53 - 1

# Subcarpeta del "input" de ComfyUI donde quedan las imágenes que manda Lumora.
INPUT_SUBFOLDER = "lumora_inputs"

# Nombre de una imagen de entrada: hash del contenido + extensión (lo arma POST /inputs).
INPUT_NAME = re.compile(r"^[0-9a-f]{40}\.(png|jpg|webp)$")


class InvalidRequest(ValueError):
    """El pedido no se puede generar (tamaño, pasos, modelo...)."""


@dataclass(frozen=True)
class ModelSpec:
    key: str
    label: str
    template: str
    # Archivos de modelo: (nodo, input, atributo de Settings con el nombre del archivo).
    model_files: tuple[tuple[str, str, str], ...]
    # ids de nodo dentro del template
    prompt_node: str
    negative_node: str
    # Nodo con width/height: el latent vacío (texto→imagen) o el escalado de la imagen 1 (edición).
    size_node: str
    sampler_node: str
    save_node: str
    default_steps: int
    max_steps: int
    # Nombre del input de texto en los nodos de prompt.
    prompt_input: str = "text"
    min_side: int = 256
    max_side: int = 2048
    side_multiple: int = 16
    # Imágenes de entrada: nodos LoadImage en orden, y los nodos que las reciben
    # como image1..imageN (a esos se les quitan las que no se usan).
    image_nodes: tuple[str, ...] = ()
    image_consumers: tuple[str, ...] = ()
    min_images: int = 0
    # Se antepone al prompt (por ejemplo, para que la edición conserve el resto).
    prompt_prefix: str = ""
    # Segundos estimados antes de haber medido nada (para el progreso).
    avg_hint_seconds: float = 20.0

    @property
    def max_images(self) -> int:
        return len(self.image_nodes)


# Con un pedido corto ("cambiá la cara por la de la imagen 2") el modelo tiende a
# rehacer la imagen entera y a reescribir los textos; con esto queda como en las
# pruebas con pedidos detallados (docs/etapa2/resultados.md).
EDIT_PREFIX = (
    "Editá la imagen 1. Cambiá únicamente lo que se pide al final y nada más. "
    "Mantené exactamente igual todo lo demás: los textos letra por letra, los logos, "
    "los colores, el fondo y la composición. Si el pedido es cambiar una cara, cambiá "
    "solo la cara y conservá el cuerpo, la postura y la ropa de esa persona. Pedido: "
)

MODELS: dict[str, ModelSpec] = {
    "z-image-turbo": ModelSpec(
        key="z-image-turbo",
        label="Z-Image Turbo",
        template="z_image_turbo.json",
        model_files=(
            ("1", "unet_name", "zimage_unet"),
            ("2", "clip_name", "zimage_clip"),
            ("3", "vae_name", "zimage_vae"),
        ),
        prompt_node="5",
        negative_node="6",
        size_node="7",
        sampler_node="8",
        save_node="10",
        default_steps=9,
        max_steps=20,
        avg_hint_seconds=16.0,
    ),
    "qwen-image-edit": ModelSpec(
        key="qwen-image-edit",
        label="Qwen Image Edit",
        template="qwen_image_edit.json",
        model_files=(
            ("1", "unet_name", "qwen_edit_unet"),
            ("2", "clip_name", "qwen_clip"),
            ("3", "vae_name", "qwen_vae"),
            ("6", "lora_name", "qwen_edit_lora"),
        ),
        prompt_node="11",
        negative_node="12",
        prompt_input="prompt",
        size_node="10",
        sampler_node="16",
        save_node="18",
        default_steps=4,
        max_steps=8,
        image_nodes=("7", "8", "9"),
        image_consumers=("11", "12"),
        min_images=1,
        prompt_prefix=EDIT_PREFIX,
        avg_hint_seconds=90.0,
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
    # Nombres devueltos por POST /inputs, en orden (la 1 es la que se edita).
    images: list[str] = field(default_factory=list)


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

    if len(req.images) > spec.max_images:
        raise InvalidRequest(
            f"{spec.label} no acepta imágenes."
            if spec.max_images == 0
            else f"{spec.label} acepta hasta {spec.max_images} imágenes."
        )
    if len(req.images) < spec.min_images:
        raise InvalidRequest(f"{spec.label} necesita al menos {spec.min_images} imagen de entrada.")
    for name in req.images:
        if not INPUT_NAME.match(name):
            raise InvalidRequest("Nombre de imagen de entrada inválido.")

    return spec


def input_path(name: str) -> str:
    """Cómo la referencia LoadImage: relativa a la carpeta input de ComfyUI."""
    return f"{INPUT_SUBFOLDER}/{name}"


def build_workflow(req: GenerationRequest, settings: Settings, job_id: str) -> tuple[dict[str, Any], int]:
    """Devuelve (workflow en formato API de ComfyUI, seed usada)."""
    spec = validate(req)
    wf = copy.deepcopy(_load_template(spec.template))
    seed = req.seed if req.seed is not None else random.randint(0, MAX_SEED)

    for node, input_name, attr in spec.model_files:
        wf[node]["inputs"][input_name] = getattr(settings, attr)

    wf[spec.prompt_node]["inputs"][spec.prompt_input] = spec.prompt_prefix + req.prompt.strip()
    wf[spec.negative_node]["inputs"][spec.prompt_input] = (req.negative_prompt or "").strip()
    wf[spec.size_node]["inputs"].update({"width": req.width, "height": req.height})
    if "batch_size" in wf[spec.size_node]["inputs"]:
        wf[spec.size_node]["inputs"]["batch_size"] = 1
    wf[spec.sampler_node]["inputs"].update({"seed": seed, "steps": req.steps or spec.default_steps})
    wf[spec.save_node]["inputs"]["filename_prefix"] = f"lumora/{job_id}"

    for i, node in enumerate(spec.image_nodes):
        if i < len(req.images):
            wf[node]["inputs"]["image"] = input_path(req.images[i])
            continue
        # Una LoadImage sin archivo hace fallar todo el workflow: se poda, junto
        # con la entrada imageN que la recibía.
        del wf[node]
        for consumer in spec.image_consumers:
            wf[consumer]["inputs"].pop(f"image{i + 1}", None)

    return wf, seed
