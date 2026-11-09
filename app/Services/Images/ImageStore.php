<?php

namespace App\Services\Images;

use App\Models\Generation;
use App\Models\Image;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use RuntimeException;

/**
 * Guarda el PNG que devuelve la placa en el disco privado, con su miniatura.
 *
 * storage/app/private/images/{user_id}/{yyyy}/{mm}/{generation_id}.png
 *                                                   {generation_id}_thumb.webp
 */
class ImageStore
{
    public function store(Generation $generation, string $png): Image
    {
        if (! PngMetadata::isPng($png)) {
            throw new RuntimeException('La placa no devolvió un PNG válido.');
        }

        $png = PngMetadata::replaceText($png, array_filter([
            'Title' => $generation->prompt,
            'Description' => $generation->prompt,
            'Software' => config('app.name', 'Lumora'),
            'Model' => $generation->model,
            'Seed' => $generation->seed !== null ? (string) $generation->seed : null,
            'Steps' => $generation->steps !== null ? (string) $generation->steps : null,
            'Creation Time' => now()->toRfc2822String(),
        ]));

        $manager = ImageManager::usingDriver(Driver::class);
        $decoded = $manager->decodeBinary($png);
        $width = $decoded->width();
        $height = $decoded->height();

        $thumb = $decoded
            ->scaleDown(width: (int) config('lumora.thumb_width', 384))
            ->encode(new WebpEncoder(quality: 80))
            ->toString();

        $date = ($generation->created_at ?? now())->format('Y/m');
        $base = "images/{$generation->user_id}/{$date}/{$generation->id}";
        $disk = Storage::disk('local');

        $disk->put("{$base}.png", $png);
        $disk->put("{$base}_thumb.webp", $thumb);

        return Image::query()->create([
            'generation_id' => $generation->id,
            'user_id' => $generation->user_id,
            'path' => "{$base}.png",
            'thumb_path' => "{$base}_thumb.webp",
            'width' => $width,
            'height' => $height,
            'bytes' => strlen($png),
        ]);
    }
}
