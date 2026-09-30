/*
 * Service worker de ServiPos Tickets.
 * - Guarda la app (HTML, JS, CSS, fuentes, íconos) para que abra sin señal.
 * - Guarda las últimas respuestas de la API que usa el técnico (sus tickets,
 *   detalle y catálogos) y las entrega cuando no hay conexión.
 * - Muestra las notificaciones push y abre el ticket al tocarlas.
 * Los envíos hechos sin señal los maneja la app (bandeja en IndexedDB).
 */
const SHELL = 'servipos-shell-v2';
const ASSETS = 'servipos-assets-v2';
const API = 'servipos-api-v1';
const KEEP = [SHELL, ASSETS, API];

// Respuestas de la API que se pueden leer sin conexión.
const CACHEABLE_API = [
    /^\/api\/v1\/auth\/me$/,
    /^\/api\/v1\/catalogs$/,
    /^\/api\/v1\/tickets$/,
    /^\/api\/v1\/tickets\/\d+$/,
    /^\/api\/v1\/push\/config$/,
];

/**
 * Guarda todos los archivos de la versión publicada (según el manifiesto de Vite)
 * y borra los de versiones anteriores. Se ejecuta al instalar y cada vez que la
 * app abre con señal, para que ninguna pantalla falte luego sin conexión.
 */
async function precacheBuild() {
    let manifest;
    try {
        manifest = await (await fetch('/build/manifest.json', { cache: 'no-store' })).json();
    } catch {
        return; // sin señal o modo desarrollo
    }
    const files = new Set();
    Object.values(manifest).forEach((entry) => {
        files.add(`/build/${entry.file}`);
        (entry.css || []).forEach((f) => files.add(`/build/${f}`));
        (entry.assets || []).forEach((f) => files.add(`/build/${f}`));
    });
    const assets = await caches.open(ASSETS);
    const cached = new Set((await assets.keys()).map((request) => new URL(request.url).pathname));
    await Promise.all([...files].filter((url) => !cached.has(url)).map((url) => assets.add(url).catch(() => {})));
    await Promise.all(
        [...cached].filter((url) => url.startsWith('/build/') && !files.has(url)).map((url) => assets.delete(url)),
    );
}

self.addEventListener('install', (event) => {
    event.waitUntil(
        (async () => {
            const shell = await caches.open(SHELL);
            await shell.addAll(['/', '/manifest.webmanifest', '/icons/icon-192.png?v=2', '/icons/icon-512.png?v=2', '/brand/mark.png', '/brand/wordmark-white.png', '/brand/logo.png']).catch(() => {});
            await precacheBuild();
            await self.skipWaiting();
        })(),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            const names = await caches.keys();
            await Promise.all(names.filter((n) => n.startsWith('servipos-') && !KEEP.includes(n)).map((n) => caches.delete(n)));
            await self.clients.claim();
        })(),
    );
});

/** Red primero; si falla o tarda demasiado, lo último guardado. */
async function networkFirst(request, cacheName, cacheKey = request, timeoutMs = 6000) {
    const cache = await caches.open(cacheName);
    const cached = await cache.match(cacheKey);
    const network = fetch(request).then((response) => {
        if (response.ok) {
            cache.put(cacheKey, response.clone());
        }
        return response;
    });

    if (!cached) {
        return network;
    }
    const timeout = new Promise((resolve) => setTimeout(() => resolve(null), timeoutMs));
    try {
        const response = await Promise.race([network, timeout]);
        if (response) {
            return response;
        }
    } catch {
        // Sin red.
    }
    const headers = new Headers(cached.headers);
    headers.set('X-Servipos-Offline', '1');
    return new Response(await cached.blob(), { status: cached.status, statusText: cached.statusText, headers });
}

/** Caché primero (archivos con hash en el nombre: nunca cambian). */
async function cacheFirst(request) {
    const cache = await caches.open(ASSETS);
    const cached = await cache.match(request);
    if (cached) {
        return cached;
    }
    const response = await fetch(request);
    if (response.ok) {
        cache.put(request, response.clone());
    }
    return response;
}

self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);
    if (request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    if (request.mode === 'navigate') {
        // Todas las pantallas son la misma página (SPA): se guarda como "/".
        event.respondWith(networkFirst(request, SHELL, '/', 4000));
        return;
    }
    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/icons/') || url.pathname.startsWith('/brand/')) {
        event.respondWith(cacheFirst(request));
        return;
    }
    if (CACHEABLE_API.some((pattern) => pattern.test(url.pathname))) {
        event.respondWith(networkFirst(request, API));
    }
});

self.addEventListener('message', (event) => {
    if (event.data === 'clear-api-cache') {
        event.waitUntil(caches.delete(API));
    }
    if (event.data === 'precache') {
        event.waitUntil(precacheBuild());
    }
});

/* ---------- Notificaciones push ---------- */

self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch {
        data = { title: 'ServiPos', body: event.data?.text() };
    }
    event.waitUntil(
        (async () => {
            const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
            // Toda ventana abierta actualiza la campana.
            windows.forEach((client) => client.postMessage({ type: 'push', data }));

            // Con la app en primer plano el aviso se muestra dentro de ella (con su sonido);
            // si no, notificación del sistema con sonido y vibración del dispositivo.
            if (windows.some((client) => client.focused && client.visibilityState === 'visible')) {
                return;
            }
            await self.registration.showNotification(data.title || 'ServiPos Tickets', {
                body: data.body || '',
                tag: data.tag,
                renotify: Boolean(data.tag),
                silent: false,
                vibrate: [200, 100, 200],
                icon: '/icons/icon-192.png?v=2',
                badge: '/icons/icon-192.png?v=2',
                data: { url: data.url || '/' },
            });
        })(),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = new URL(event.notification.data?.url || '/', self.location.origin).href;
    event.waitUntil(
        (async () => {
            const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
            const client = windows.find((c) => c.url.startsWith(self.location.origin));
            if (client) {
                await client.focus();
                return client.navigate(url).catch(() => client.postMessage({ type: 'navigate', url }));
            }
            return self.clients.openWindow(url);
        })(),
    );
});
