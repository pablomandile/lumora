<?php

use App\Http\Middleware\HandleInertiaRequests;

/*
 * El CDN de Hostinger borra el Vary: el JSON de Inertia no se puede guardar,
 * o al restaurar una pestaña aparece crudo en pantalla.
 */

function versionDeInertia(): string
{
    return (string) app(HandleInertiaRequests::class)->version(request());
}

it('prohíbe guardar la respuesta XHR de Inertia', function () {
    $respuesta = $this->get('/login', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => versionDeInertia(),
    ]);

    $respuesta->assertOk();
    expect($respuesta->headers->get('Content-Type'))->toContain('application/json')
        ->and($respuesta->headers->get('Cache-Control'))->toContain('no-store')
        ->and($respuesta->headers->get('Vary'))->toContain('X-Inertia');
});

it('deja cacheable el documento HTML, para no perder el bfcache', function () {
    $respuesta = $this->get('/login');

    expect($respuesta->headers->get('Content-Type'))->toContain('text/html')
        ->and($respuesta->headers->get('Cache-Control'))->not->toContain('no-store');
});

it('el service worker pide el HTML si una navegación recibe el JSON de Inertia', function () {
    $sw = (string) file_get_contents(public_path('sw.js'));

    expect($sw)->toContain("response.headers.get('x-inertia')")
        ->toContain('again.redirected');
});
