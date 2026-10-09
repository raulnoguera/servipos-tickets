/**
 * Actualización de la aplicación instalada (PWA).
 * Cada compilación lleva un identificador; el servidor publica el de la versión vigente.
 * Cuando difieren, se preparan los archivos nuevos y se recarga la app.
 */

/* global __APP_BUILD__ */

/** Versión que está corriendo en este dispositivo (null en desarrollo). */
export const BUILD = import.meta.env.PROD ? __APP_BUILD__ : null;

const TRIED = 'servipos:actualizacion-intentada';

function withTimeout(promise, ms, fallback) {
    return Promise.race([promise, new Promise((resolve) => setTimeout(() => resolve(fallback), ms))]);
}

/** Versión publicada en el servidor. Lanza un error si no hay conexión. */
async function latestBuild() {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 8000);
    try {
        const response = await fetch('/api/v1/version', { cache: 'no-store', headers: { Accept: 'application/json' }, signal: controller.signal });
        if (!response.ok) {
            throw new Error(`Error ${response.status}`);
        }
        return (await response.json()).build ?? null;
    } finally {
        clearTimeout(timer);
    }
}

/** @returns {Promise<{current: string|null, latest: string|null, available: boolean}>} */
export async function checkForUpdate() {
    const latest = await latestBuild();
    return { current: BUILD, latest, available: Boolean(BUILD && latest && latest !== BUILD) };
}

/**
 * Ya se intentó instalar esta versión en esta sesión y la app sigue en la anterior
 * (p. ej. una red que entrega la página vieja): no se reintenta sola para no recargar en bucle.
 */
export function alreadyTried(latest) {
    try {
        return sessionStorage.getItem(TRIED) === latest;
    } catch {
        return false;
    }
}

/** Pide al service worker que guarde la versión nueva y espera su respuesta. */
async function prepareOffline() {
    if (!('serviceWorker' in navigator)) {
        return;
    }
    const registration = await navigator.serviceWorker.getRegistration().catch(() => null);
    if (!registration) {
        return;
    }
    // Primero el propio service worker, por si también cambió.
    await withTimeout(registration.update().catch(() => null), 8000);
    const waiting = registration.installing || registration.waiting;
    if (waiting) {
        await withTimeout(new Promise((resolve) => {
            waiting.addEventListener('statechange', () => waiting.state === 'activated' && resolve());
        }), 8000);
    }
    const worker = registration.active;
    if (!worker) {
        return;
    }
    await withTimeout(new Promise((resolve) => {
        const channel = new MessageChannel();
        channel.port1.onmessage = () => resolve();
        worker.postMessage('update', [channel.port2]);
    }), 20000);
}

/** Instala la versión nueva: guarda sus archivos y recarga la app en la misma pantalla. */
export async function applyUpdate(latest) {
    try {
        sessionStorage.setItem(TRIED, latest ?? '');
    } catch {
        // Sin almacenamiento de sesión solo se pierde la protección contra recargas repetidas.
    }
    await prepareOffline();
    window.location.reload();
}
