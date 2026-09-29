/** Cliente de la API con sesión de Laravel Sanctum (cookie + XSRF). */

export class ApiError extends Error {
    constructor(status, body) {
        super(body?.message || `Error ${status}`);
        this.status = status;
        this.errors = body?.errors || {};
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
    if (!csrfReady || !xsrfToken()) {
        await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' });
        csrfReady = true;
    }
}

let onUnauthorized = () => {};
export function setUnauthorizedHandler(handler) {
    onUnauthorized = handler;
}

export async function api(path, { method = 'GET', body, query, raw = false } = {}) {
    const url = new URL(`/api/v1/${path.replace(/^\//, '')}`, window.location.origin);
    Object.entries(query || {}).forEach(([key, value]) => {
        if (value !== undefined && value !== null && value !== '') {
            url.searchParams.set(key, value);
        }
    });

    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    let payload = body;
    if (method !== 'GET') {
        await ensureCsrf();
        headers['X-XSRF-TOKEN'] = xsrfToken();
        if (body && !(body instanceof FormData)) {
            headers['Content-Type'] = 'application/json';
            payload = JSON.stringify(body);
        }
    }

    const response = await fetch(url, { method, headers, body: payload, credentials: 'same-origin' });

    if (response.status === 419) {
        csrfReady = false;
    }
    if (response.status === 401 && !path.startsWith('auth/login')) {
        onUnauthorized();
    }
    if (!response.ok) {
        const data = await response.json().catch(() => ({}));
        throw new ApiError(response.status, data);
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
