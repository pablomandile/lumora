<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Frena a quien no aceptó la versión vigente de los términos y la privacidad.
 *
 * Cubre los tres casos que no pasan por la casilla del registro: las cuentas
 * de antes de que existieran los textos, el alta con Google, y cualquier
 * cambio futuro de versión (`config('legales.version')`).
 *
 * Va en todo el grupo `web` y no ruta por ruta: así una ruta nueva nace
 * cubierta, y lo que se exceptúa está a la vista en una sola lista.
 */
class AceptoTerminos
{
    /**
     * Lo que se puede hacer sin aceptar: leer los textos, salir, cambiar el
     * tamaño de letra, y los dos derechos que no pueden depender de aceptar
     * nada: llevarse los datos (y las imágenes) y borrar la cuenta.
     *
     * @var list<string>
     */
    public const EXENTAS = [
        'terminos',
        'terminos.*',
        'privacidad',
        'home',
        'logout',
        'tamanio-texto.update',
        'profile.destroy',
        'exportacion.datos',
        'images.download',
        'verification.*',
        'storage.*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if (! $usuario instanceof User || $usuario->aceptoTerminosVigentes() || $this->exenta($request)) {
            return $next($request);
        }

        // Los fetch de fondo (estado de la placa) reciben 409 y no pisan
        // `url.intended`, que es a donde vuelve después de aceptar.
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'message' => 'Antes de seguir tenés que aceptar los términos y la política de privacidad.',
            ], 409);
        }

        // `guest()` guarda la URL pedida, si era un GET, para volver ahí al aceptar.
        return redirect()->guest(route('terminos.aceptar'));
    }

    private function exenta(Request $request): bool
    {
        $nombre = $request->route()?->getName();

        // Sin nombre: rutas de paquetes (devtools, .well-known), no pantallas.
        return $nombre === null || Str::is(self::EXENTAS, $nombre);
    }
}
