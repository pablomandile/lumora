<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Las cuentas que el admin todavía no aprobó (o rechazó) solo ven la
 * pantalla de "cuenta pendiente".
 */
class EnsureUserIsApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->isApproved()) {
            return $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['message' => 'Tu cuenta todavía no fue aprobada.'], 403)
                : redirect()->route('account.pending');
        }

        return $next($request);
    }
}
