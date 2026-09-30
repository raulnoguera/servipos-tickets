import { api } from './api';

export const isIOS = () =>
    !/android/i.test(navigator.userAgent) &&
    (/iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1));

export const isStandalone = () => window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;

const supported = () => 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

function keyToBytes(base64) {
    const padded = (base64 + '='.repeat((4 - (base64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    return Uint8Array.from(atob(padded), (c) => c.charCodeAt(0));
}

async function currentSubscription() {
    const registration = await navigator.serviceWorker.getRegistration();
    return registration ? registration.pushManager.getSubscription() : null;
}

/**
 * Estado de las notificaciones en este dispositivo:
 * 'ios-install' (iPhone sin agregar a inicio), 'unsupported', 'disabled' (servidor sin claves),
 * 'denied', 'subscribed' o 'available'.
 */
export async function pushState() {
    if (isIOS() && !isStandalone()) {
        return 'ios-install';
    }
    if (!supported()) {
        return 'unsupported';
    }
    const config = await api('push/config').catch(() => ({ enabled: false }));
    if (!config.enabled) {
        return 'disabled';
    }
    if (Notification.permission === 'denied') {
        return 'denied';
    }
    const subscription = await currentSubscription();
    if (subscription && Notification.permission === 'granted') {
        // Se vuelve a registrar por si el servidor la perdió o el teléfono cambió de usuario.
        api('push/subscriptions', { method: 'POST', body: subscription.toJSON() }).catch(() => {});
        return 'subscribed';
    }
    return 'available';
}

/** El usuario apagó los avisos a propósito en este dispositivo: no reactivarlos solos. */
const OPT_OUT = 'servipos:push-optout';
const setOptOut = (value) => {
    try {
        value ? localStorage.setItem(OPT_OUT, '1') : localStorage.removeItem(OPT_OUT);
    } catch {
        // sin almacenamiento local
    }
};
const hasOptedOut = () => {
    try {
        return localStorage.getItem(OPT_OUT) === '1';
    } catch {
        return false;
    }
};

/**
 * Reactiva los avisos sin preguntar cuando este navegador ya dio el permiso
 * (p. ej. al volver a iniciar sesión en el mismo equipo). No muestra nada ni
 * pide permisos; si falta algo, simplemente no hace nada.
 * @returns {Promise<boolean>} true si el dispositivo quedó registrado
 */
export async function ensurePush() {
    try {
        if (!supported() || Notification.permission !== 'granted' || hasOptedOut() || (isIOS() && !isStandalone())) {
            return false;
        }
        const registration = await navigator.serviceWorker.getRegistration();
        if (!registration?.active) {
            return false;
        }
        const config = await api('push/config', { timeout: 10000 });
        if (!config.enabled) {
            return false;
        }
        const subscription =
            (await registration.pushManager.getSubscription()) ||
            (await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyToBytes(config.public_key) }));
        await api('push/subscriptions', { method: 'POST', body: subscription.toJSON(), timeout: 10000 });
        return true;
    } catch {
        return false;
    }
}

export async function enablePush() {
    setOptOut(false);
    const permission = await Notification.requestPermission();
    if (permission !== 'granted') {
        throw new Error(permission === 'denied' ? 'Las notificaciones están bloqueadas en este teléfono.' : 'No se otorgó el permiso.');
    }
    const registration = await navigator.serviceWorker.ready;
    const config = await api('push/config');
    const subscription =
        (await registration.pushManager.getSubscription()) ||
        (await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyToBytes(config.public_key) }));
    await api('push/subscriptions', { method: 'POST', body: subscription.toJSON() });
}

/**
 * Quita el registro de este dispositivo.
 * @param {{byUser?: boolean}} options byUser: lo apagó el usuario (no reactivar solo al volver a entrar)
 */
export async function disablePush({ byUser = false } = {}) {
    if (byUser) {
        setOptOut(true);
    }
    if (!supported()) {
        return;
    }
    const subscription = await currentSubscription().catch(() => null);
    if (subscription) {
        await api('push/subscriptions', { method: 'DELETE', body: { endpoint: subscription.endpoint }, timeout: 5000 }).catch(() => {});
        await subscription.unsubscribe().catch(() => {});
    }
}

export const sendTestPush = () => api('push/test', { method: 'POST' });
