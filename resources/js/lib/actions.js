import { api } from './api';
import { outbox } from './outbox';

/**
 * Cambia el estado del ticket; si no hay señal lo guarda en la bandeja con la
 * hora real en que el técnico tocó el botón.
 * @returns {Promise<{queued: boolean, data?: object}>}
 */
export async function changeStatus({ ticket, status, userId }) {
    const payload = { status, at: new Date().toISOString() };
    try {
        return { queued: false, data: await api(`tickets/${ticket.id}/status`, { method: 'POST', body: payload, timeout: 8000 }) };
    } catch (error) {
        if (!error.offline) {
            throw error;
        }
        await outbox.add({ type: 'status', userId, ticketId: ticket.id, ticketNumber: ticket.number, payload });
        return { queued: true };
    }
}
