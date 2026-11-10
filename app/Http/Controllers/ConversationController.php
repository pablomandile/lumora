<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Renombrar y borrar conversaciones desde el menú. Borrar una conversación no
 * borra sus imágenes: siguen en la galería.
 */
class ConversationController extends Controller
{
    public function update(Request $request, Conversation $conversation): RedirectResponse
    {
        Gate::authorize('update', $conversation);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:'.Conversation::TITLE_MAX],
        ]);

        // Sin tocar updated_at: renombrar no la sube en el menú.
        $conversation->timestamps = false;
        $conversation->update(['title' => trim($data['title'])]);

        return back();
    }

    public function destroy(Request $request, Conversation $conversation): RedirectResponse
    {
        Gate::authorize('delete', $conversation);

        $conversation->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Conversación eliminada. Las imágenes siguen en la galería.']);

        // Si se borró la que estaba abierta, se vuelve a una nueva.
        $current = $request->string('current')->toString();

        return $current === $conversation->id ? to_route('dashboard') : back();
    }
}
