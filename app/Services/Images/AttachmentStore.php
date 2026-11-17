<?php

namespace App\Services\Images;

use App\Models\Attachment;
use App\Models\Image;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use RuntimeException;
use Throwable;

/**
 * Guarda las imágenes de entrada para editar en el disco privado:
 *
 * storage/app/private/attachments/{user_id}/{yyyy}/{mm}/{id}.jpg
 *                                                       {id}_thumb.webp
 *
 * Nunca se guarda el archivo original: se gira según el EXIF, se reduce a
 * {@see self::MAX_SIDE} y se vuelve a codificar, así que no queda ningún
 * metadato (la ubicación GPS de una foto de celular, por ejemplo).
 */
class AttachmentStore
{
    /** Lado máximo: el modelo trabaja a ~1 MP, más no aporta y pesa en el túnel. */
    public const MAX_SIDE = 2048;

    public function storeUpload(User $user, UploadedFile $file): Attachment
    {
        $bytes = (string) file_get_contents($file->getRealPath());

        return $this->store($user, $bytes);
    }

    /** Copia una imagen generada para usarla como punto de partida de una edición. */
    public function fromImage(Image $image): Attachment
    {
        $bytes = Storage::disk('local')->get($image->path);

        if ($bytes === null) {
            throw new RuntimeException('La imagen ya no está disponible.');
        }

        return $this->store($image->user, $bytes);
    }

    private function store(User $user, string $bytes): Attachment
    {
        $sha = hash('sha256', $bytes);
        $disk = Storage::disk('local');

        // La misma foto otra vez (otra variante, otro pedido): se reusa.
        $existing = $user->attachments()->where('sha256', $sha)->latest()->first();
        if ($existing && $disk->exists($existing->path)) {
            return $existing;
        }

        try {
            $image = ImageManager::usingDriver(Driver::class)->decodeBinary($bytes);
        } catch (Throwable $e) {
            throw new RuntimeException('No se pudo leer la imagen.', previous: $e);
        }

        // decodeBinary ya la giró según el EXIF (autoOrientation).
        $image->scaleDown(self::MAX_SIDE, self::MAX_SIDE);
        $width = $image->width();
        $height = $image->height();

        $jpg = $image->encode(new JpegEncoder(quality: 90, strip: true))->toString();
        $thumb = $image
            ->scaleDown(width: (int) config('lumora.thumb_width', 384))
            ->encode(new WebpEncoder(quality: 80, strip: true))
            ->toString();

        $id = Str::lower((string) Str::ulid());
        $base = "attachments/{$user->id}/".now()->format('Y/m')."/{$id}";

        $disk->put("{$base}.jpg", $jpg);
        $disk->put("{$base}_thumb.webp", $thumb);

        return $user->attachments()->forceCreate([
            'id' => $id,
            'path' => "{$base}.jpg",
            'thumb_path' => "{$base}_thumb.webp",
            'width' => $width,
            'height' => $height,
            'bytes' => strlen($jpg),
            'sha256' => $sha,
        ]);
    }
}
