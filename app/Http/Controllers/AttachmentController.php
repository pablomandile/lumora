<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sirve las imágenes de entrada (privadas: el dueño o el admin).
 */
class AttachmentController extends Controller
{
    // El nombre es el id y el archivo no cambia: se cachea solo en el navegador.
    private const CACHE = 'private, max-age=31536000, immutable';

    public function show(Attachment $attachment): StreamedResponse
    {
        Gate::authorize('view', $attachment);

        return $this->file($attachment->path, 'image/jpeg');
    }

    public function thumb(Attachment $attachment): StreamedResponse
    {
        Gate::authorize('view', $attachment);

        return $attachment->thumb_path
            ? $this->file($attachment->thumb_path, 'image/webp')
            : $this->file($attachment->path, 'image/jpeg');
    }

    private function file(string $path, string $mime): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Content-Type' => $mime,
            'Cache-Control' => self::CACHE,
        ]);
    }
}
