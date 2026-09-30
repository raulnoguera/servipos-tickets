import { useEffect, useState } from 'react';
import { api } from './api';
import { outbox } from './outbox';

let running = false;

async function send(item) {
    if (item.type === 'status') {
        return api(`tickets/${item.ticketId}/status`, { method: 'POST', body: item.payload, timeout: 20000 });
    }
    const body = new FormData();
    Object.entries(item.payload.fields).forEach(([key, value]) => body.append(key, value));
    if (item.payload.photo) {
        body.append('photo', item.payload.photo, item.payload.photoName || 'constancia.jpg');
    }
    return api(`tickets/${item.ticketId}/visits`, { method: 'POST', body, timeout: 120000 });
}

/**
 * Envía la bandeja en orden. Se detiene si no hay señal; los errores de
 * validación quedan marcados para que el técnico los revise.
 * @returns {Promise<number>} envíos completados
 */
export async function flushOutbox(userId) {
    if (running || !navigator.onLine) {
        return 0;
    }
    running = true;
    let sent = 0;
    try {
        const items = (await outbox.all()).filter((i) => i.userId === userId && i.state === 'pending');
        for (const item of items) {
            try {
                await send(item);
                await outbox.remove(item.id);
                sent++;
            } catch (error) {
                if (error.offline || error.status === 401 || error.status === 419) {
                    break; // se reintenta más tarde
                }
                if (item.type === 'status' && error.status === 422) {
                    // El cambio ya no aplica (se registró antes o el ticket avanzó): se descarta.
                    await outbox.remove(item.id);
                    continue;
                }
                const detail = Object.values(error.errors || {})[0]?.[0] || error.message;
                await outbox.update(item.id, { state: 'error', error: detail });
            }
        }
    } finally {
        running = false;
    }
    return sent;
}

export function useOnline() {
    const [online, setOnline] = useState(navigator.onLine);
    useEffect(() => {
        const on = () => setOnline(true);
        const off = () => setOnline(false);
        window.addEventListener('online', on);
        window.addEventListener('offline', off);
        return () => {
            window.removeEventListener('online', on);
            window.removeEventListener('offline', off);
        };
    }, []);
    return online;
}

/** Elementos de la bandeja del usuario, actualizados en vivo. */
export function useOutbox(userId) {
    const [items, setItems] = useState([]);
    useEffect(() => {
        let alive = true;
        const load = () => outbox.all().then((all) => alive && setItems(all.filter((i) => i.userId === userId))).catch(() => {});
        load();
        const off = outbox.onChange(load);
        return () => {
            alive = false;
            off();
        };
    }, [userId]);
    return items;
}

/**
 * Estado "visible" de un ticket teniendo en cuenta lo que está en la bandeja:
 * el último cambio de estado pendiente y si hay una atención por enviar.
 */
export function queuedState(items, ticketId) {
    const mine = items.filter((i) => i.ticketId === ticketId);
    const lastStatus = [...mine].reverse().find((i) => i.type === 'status')?.payload.status;
    return {
        status: lastStatus,
        visitQueued: mine.some((i) => i.type === 'visit'),
        hasError: mine.some((i) => i.state === 'error'),
        count: mine.length,
    };
}
