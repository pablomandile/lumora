<?php

namespace App\Http\Middleware;

use App\Enums\TamanioTexto;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Inertia\Middleware;
use Inertia\Support\Header;
use Symfony\Component\HttpFoundation\Response;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * El CDN de Hostinger borra el `Vary: X-Inertia` al comprimir con brotli:
     * el navegador guarda el JSON de un XHR bajo la misma URL que el HTML y, al
     * restaurar una pestaña descartada, lo muestra crudo en pantalla.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = parent::handle($request, $next);

        $response->headers->set('Vary', Header::INERTIA.', Accept-Encoding');

        // no-store (no-cache permite guardar) y solo en el XHR: en el HTML
        // desactivaría el back/forward cache.
        if ($request->header(Header::INERTIA)) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                'is_admin' => (bool) $request->user()?->isAdmin(),
                'is_approved' => (bool) $request->user()?->isApproved(),
            ],
            'tamanioTexto' => fn () => View::shared('tamanioTexto', TamanioTexto::porDefecto())->value,
            'tamaniosTexto' => fn () => TamanioTexto::opciones(),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
