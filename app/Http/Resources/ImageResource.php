<?php

namespace App\Http\Resources;

use App\Models\Image;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Imagen para el visor: archivos, descargas y los datos de su generación.
 *
 * @mixin Image
 */
class ImageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $generation = $this->generation;

        return [
            'id' => $this->id,
            'generation_id' => $this->generation_id,
            'url' => route('images.show', $this->resource),
            'thumb_url' => route('images.thumb', $this->resource),
            'downloads' => collect(['png', 'jpg', 'webp'])
                ->mapWithKeys(fn (string $format) => [$format => route('images.download', [$this->resource, $format])])
                ->all(),
            'width' => $this->width,
            'height' => $this->height,
            'bytes' => $this->bytes,
            'favorite' => $this->favorite,
            'created_at' => $this->created_at?->toIso8601String(),
            'conversation_id' => $generation->conversation_id,
            'prompt' => $generation->prompt,
            'model' => $generation->model,
            'aspect' => $generation->aspect,
            'seed' => $generation->seed,
            'steps' => $generation->steps,
            'run_seconds' => $generation->run_seconds,
        ];
    }
}
