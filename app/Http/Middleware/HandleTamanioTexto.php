<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\TamanioTexto;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resuelve el tamaño de letra antes de renderizar, para que el atributo ya
 * viaje en el HTML de arranque.
 *
 * A diferencia del modo oscuro, acá **no hace falta un script inline**: no hay
 * opción "según el sistema", así que el servidor sabe la respuesta y la escribe
 * directo en `<html>`. Cero parpadeo y cero JavaScript.
 */
class HandleTamanioTexto
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        View::share('tamanioTexto', $this->resolver($request));

        return $next($request);
    }

    /**
     * **Manda la cuenta, después la cookie.**
     *
     * La cuenta viaja entre dispositivos (quien estrena teléfono no tiene que
     * volver a elegir). La cookie cubre a quien todavía no inició sesión.
     */
    private function resolver(Request $request): TamanioTexto
    {
        $usuario = $request->user();

        if ($usuario !== null) {
            return $usuario->tamanio_texto;
        }

        // Una cookie puede llegar como array si alguien la manda con corchetes.
        $cookie = $request->cookie('tamanio_texto');

        return TamanioTexto::desde(is_string($cookie) ? $cookie : null);
    }
}
