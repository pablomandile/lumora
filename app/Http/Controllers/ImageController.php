<?php

namespace App\Http\Controllers;

use App\Models\Image;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sirve las imágenes privadas (solo al dueño o al admin) y sus descargas.
 */
class ImageController extends Controller
{
    // Los archivos no cambian nunca (el nombre es el id): se cachean en el
    // navegador, pero no en proxies ni en el CDN.
    private const CACHE = 'private, max-age=31536000, immutable';

    public function show(Image $image): StreamedResponse
    {
        Gate::authorize('view', $image);

        return $this->file($image->path, 'image/png');
    }

    public function thumb(Image $image): StreamedResponse
    {
        Gate::authorize('view', $image);

        return $image->thumb_path
            ? $this->file($image->thumb_path, 'image/webp')
            : $this->file($image->path, 'image/png');
    }

    public function download(Image $image, string $format): Response
    {
        Gate::authorize('view', $image);
        abort_unless(Storage::disk('local')->exists($image->path), 404);

        $generation = $image->generation;
        $slug = Str::slug(Str::limit($generation->prompt, 48, '')) ?: 'imagen';
        $filename = "lumora-{$slug}-".($generation->seed ?? Str::lower(Str::substr($image->id, -6))).".{$format}";

        if ($format === 'png') {
            return Storage::disk('local')->download($image->path, $filename, ['Content-Type' => 'image/png']);
        }

        [$encoder, $mime] = $format === 'jpg'
            ? [new JpegEncoder(quality: 92), 'image/jpeg']
            : [new WebpEncoder(quality: 90), 'image/webp'];

        $bytes = ImageManager::usingDriver(Driver::class)
            ->decodeBinary((string) Storage::disk('local')->get($image->path))
            ->encode($encoder)
            ->toString();

        return response($bytes, 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function favorite(Request $request, Image $image): RedirectResponse
    {
        Gate::authorize('update', $image);

        $image->forceFill(['favorite' => $request->boolean('favorite')])->save();

        return back();
    }

    public function destroy(Image $image): RedirectResponse
    {
        Gate::authorize('delete', $image);

        // Queda en la papelera y images:prune la borra del todo a los 30 días.
        $image->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Imagen eliminada.']);

        return back();
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
