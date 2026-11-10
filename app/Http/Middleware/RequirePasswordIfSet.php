<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pide reconfirmar la contraseña como RequirePassword, salvo a quien no tiene
 * ninguna (entró siempre con Google). Sin esto, esas cuentas quedan trabadas
 * en una pantalla que pide una contraseña que nunca eligieron, y no pueden
 * llegar a Seguridad ni para crear la primera.
 *
 * Reemplaza al alias `password.confirm`, que es el que usan las rutas de
 * Fortify para el segundo factor y las passkeys.
 */
class RequirePasswordIfSet extends RequirePassword
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle($request, Closure $next, $redirectToRoute = null, $passwordTimeoutSeconds = null)
    {
        if ($request->user() && blank($request->user()->getAuthPassword())) {
            return $next($request);
        }

        return parent::handle($request, $next, $redirectToRoute, $passwordTimeoutSeconds);
    }
}
