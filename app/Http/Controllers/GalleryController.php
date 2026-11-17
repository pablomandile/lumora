<?php

namespace App\Http\Controllers;

use App\Http\Resources\ImageResource;
use App\Models\AiModel;
use App\Models\Image;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Historial completo del usuario, con búsqueda por prompt y favoritas.
 */
class GalleryController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $search = trim((string) $request->string('q'));
        $favorites = $request->boolean('favoritas');

        $images = $user->images()
            ->with('generation.attachments')
            ->when($favorites, fn ($q) => $q->where('favorite', true))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search).'%';
                $q->whereHas('generation', fn ($g) => $g->where('prompt', 'like', $like));
            })
            ->latest()
            ->latest('id')
            ->cursorPaginate(30)
            ->withQueryString()
            ->through(fn (Image $image) => (new ImageResource($image))->resolve($request));

        return Inertia::render('Gallery', [
            'images' => Inertia::scroll($images),
            'filters' => ['q' => $search, 'favoritas' => $favorites],
            'total' => $user->images()->count(),
            // Para mostrar "Editar esta imagen" solo si tiene un modelo de edición.
            'can_edit' => AiModel::query()->availableFor($user)->get()->contains(fn (AiModel $m) => $m->editsImages()),
        ]);
    }
}
