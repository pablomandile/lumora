<?php

namespace App\Http\Resources;

use App\Models\Generation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Generación para el Studio: estado, progreso y la imagen cuando terminó.
 *
 * @mixin Generation
 */
class GenerationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $image = $this->image;
        $image?->setRelation('generation', $this->resource);

        return [
            'id' => $this->id,
            'prompt' => $this->prompt,
            'model' => $this->model,
            'width' => $this->width,
            'height' => $this->height,
            'aspect' => $this->aspect,
            'steps' => $this->steps,
            'seed' => $this->seed,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'progress' => $this->progress,
            'queue_position' => $this->queue_position,
            'error' => $this->error,
            'created_at' => $this->created_at?->toIso8601String(),
            'image' => $image ? (new ImageResource($image))->resolve($request) : null,
        ];
    }
}
