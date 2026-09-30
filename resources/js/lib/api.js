/** Cliente de la API con sesión de Laravel Sanctum (cookie + XSRF). */

export class ApiError extends Error {
    constructor(status, body) {
        super(body?.message || `Error ${status}`);
        this.status = status;
        this.errors = body?.errors || {};
        /** Sin señal o el servidor no respondió a tiempo: el envío puede reintentarse. */
        this.offline = status === 0;
    }

    /** Primer mensaje de validación de un campo. */
    field(name) {
        return this.errors[name]?.[0];
    }
}

let csrfReady = false;

function xsrfToken() {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : null;
}

async function ensureCsrf() {
    if (csrfReady && xsrfToken()) {
        return;
    }
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 6000);
    try {
        const response = await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin', signal: controller.signal });
        csrfReady = response.ok;
    } catch {
        // Sin señal: si ya hay una cookie XSRF se intenta igual; el envío fallará y quedará en la bandeja.
        if (!xsrfToken()) {
            throw new ApiError(0, { message: 'Sin conexión con el servidor.' });
        }
    } finally {
        clearTimeout(timer);
    }
}

let onUnauthorized = () => {};
export function setUnauthorizedHandler(handler) {
    onUnauthorized = handler;
}

/**
 * @param {object} options
 * @param {number} [options.timeout] milisegundos antes de considerar que no hay señal
 */
export async function api(path, { method = 'GET', body, query, raw = false, timeout = 30000, retried = false } = {}) {
    const url = new URL(`/api/v1/${path.replace(/^\//, '')}`, window.location.origin);
    Object.entries(query || {}).forEach(([key, value]) => {
        if (value !== undefined && value !== null && value !== '') {
            url.searchParams.set(key, value);
        }
    });

    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    let payload = body;
    // El teléfono ya sabe que no hay conexión: no esperar, el envío va a la bandeja.
    // (Las consultas GET sí siguen, porque el service worker responde con lo guardado.)
    if (method !== 'GET' && navigator.onLine === false) {
        throw new ApiError(0, { message: 'Sin conexión con el servidor.' });
    }
    if (method !== 'GET') {
        await ensureCsrf();
        headers['X-XSRF-TOKEN'] = xsrfToken();
        if (body && !(body instanceof FormData)) {
            headers['Content-Type'] = 'application/json';
            payload = JSON.stringify(body);
        }
    }

    let response;
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeout);
    try {
        response = await fetch(url, { method, headers, body: payload, credentials: 'same-origin', signal: controller.signal });
    } catch {
        throw new ApiError(0, { message: 'Sin conexión con el servidor.' });
    } finally {
        clearTimeout(timer);
    }

    if (response.status === 419) {
        // La sesión CSRF venció (p. ej. el teléfono estuvo horas sin señal): renovar y reintentar una vez.
        csrfReady = false;
        if (!retried) {
            return api(path, { method, body, query, raw, timeout, retried: true });
        }
    }
    if (response.status === 401 && !path.startsWith('auth/login')) {
        onUnauthorized();
    }
    if (!response.ok) {
        const data = await response.json().catch(() => ({}));
        // El hosting responde 5xx/HTML cuando la red intermedia falla: se trata como sin señal.
        throw new ApiError(response.status >= 502 && response.status <= 504 ? 0 : response.status, data);
    }
    if (raw) {
        return response;
    }
    return response.status === 204 ? null : response.json();
}

/** Descarga un archivo de la API (p. ej. el consolidado .xlsx). */
export async function download(path, query) {
    const response = await api(path, { query, raw: true });
    const disposition = response.headers.get('Content-Disposition') || '';
    const name = decodeURIComponent(
        (disposition.match(/filename\*=UTF-8''([^;]+)/) || disposition.match(/filename="?([^";]+)"?/) || [])[1] || 'archivo.xlsx',
    );
    const blob = await response.blob();
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = name;
    link.click();
    setTimeout(() => URL.revokeObjectURL(link.href), 5000);
}
