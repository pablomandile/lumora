/*
 * Service worker de Lumora.
 *
 * - /build/ (nombres con hash): cache-first.
 * - Imágenes privadas, estado de la placa y respuestas de Inertia: siempre a la
 *   red, nunca se guardan (son del usuario y cambian).
 * - Navegaciones: a la red; sin conexión se muestra un aviso simple.
 * - Íconos y manifest: network-first (URL fija: si fueran cache-first quedarían
 *   congelados para siempre).
 *
 * Al cambiar íconos o este archivo, subí CACHE (activate borra las demás).
 */
const CACHE = 'lumora-v1';

const OFFLINE_HTML = `<!doctype html><html lang="es"><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sin conexión · Lumora</title>
<body style="margin:0;min-height:100dvh;display:grid;place-items:center;font-family:system-ui,sans-serif;background:#0a0a0a;color:#eee;text-align:center;padding:24px">
<div><img src="/icons/icon-192.png?v=1" width="72" height="72" alt="" style="border-radius:16px">
<h1 style="font-size:20px;margin:16px 0 8px">Sin conexión</h1>
<p style="color:#aaa;margin:0 0 20px">Lumora necesita internet para generar y mostrar tus imágenes.</p>
<button onclick="location.reload()" style="font:inherit;padding:10px 18px;border-radius:999px;border:0;background:#fff;color:#000">Reintentar</button></div>`;

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(CACHE)
            .then((cache) =>
                cache.addAll([
                    '/manifest.webmanifest',
                    '/icons/icon-192.png?v=1',
                ]),
            )
            .catch(() => {}),
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) =>
                Promise.all(
                    keys
                        .filter((k) => k !== CACHE)
                        .map((k) => caches.delete(k)),
                ),
            ),
    );
    self.clients.claim();
});

function isPrivate(url, request) {
    return (
        request.headers.has('X-Inertia') ||
        url.pathname.startsWith('/imagenes/') ||
        url.pathname.startsWith('/placa/') ||
        url.pathname.startsWith('/admin/')
    );
}

self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    if (request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    if (url.pathname.startsWith('/build/')) {
        event.respondWith(
            caches.match(request).then(
                (hit) =>
                    hit ||
                    fetch(request).then((response) => {
                        if (response.ok) {
                            const copy = response.clone();
                            caches
                                .open(CACHE)
                                .then((cache) => cache.put(request, copy));
                        }

                        return response;
                    }),
            ),
        );

        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    // Red de seguridad: si el navegador tenía guardado el JSON de
                    // Inertia bajo esta URL (CDN sin Vary), se pide el HTML.
                    if (!response.headers.get('x-inertia')) {
                        return response;
                    }

                    return fetch(request.url, { cache: 'reload', headers: { Accept: 'text/html' } }).then((again) =>
                        // Una respuesta redirigida (sesión vencida) no se le puede
                        // entregar a una navegación: se le pasa el redirect.
                        again.redirected ? Response.redirect(again.url, 302) : again,
                    );
                })
                .catch(
                () =>
                    new Response(OFFLINE_HTML, {
                        headers: { 'Content-Type': 'text/html; charset=utf-8' },
                    }),
            ),
        );

        return;
    }

    if (isPrivate(url, request)) {
        return;
    }

    if (
        url.pathname.startsWith('/icons/') ||
        url.pathname === '/manifest.webmanifest'
    ) {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    if (response.ok) {
                        const copy = response.clone();
                        caches
                            .open(CACHE)
                            .then((cache) => cache.put(request, copy));
                    }

                    return response;
                })
                .catch(() =>
                    caches
                        .match(request)
                        .then((hit) => hit || Response.error()),
                ),
        );
    }
});
