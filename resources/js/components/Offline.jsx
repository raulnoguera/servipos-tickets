import { useCallback, useEffect, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { AlertTriangle, CloudUpload, RefreshCw, Trash2, WifiOff } from 'lucide-react';
import { useAuth } from '../lib/auth';
import { outbox } from '../lib/outbox';
import { flushOutbox, useOnline, useOutbox } from '../lib/sync';
import { formatDateTime } from '../lib/format';
import { Button, Modal, StatusBadge, cx, toast } from './ui';

const SYNC_EVERY_MS = 30000;

/** Envía la bandeja al abrir la app, al volver la señal y cada 30 s. */
export function OutboxSync() {
    const { user } = useAuth();
    const queryClient = useQueryClient();

    const sync = useCallback(async () => {
        const sent = await flushOutbox(user.id);
        if (sent) {
            queryClient.invalidateQueries();
            toast(sent === 1 ? 'Se envió 1 cambio guardado sin señal' : `Se enviaron ${sent} cambios guardados sin señal`);
        }
    }, [user.id, queryClient]);

    useEffect(() => {
        sync();
        const timer = setInterval(sync, SYNC_EVERY_MS);
        const onOnline = () => sync();
        const onVisible = () => document.visibilityState === 'visible' && sync();
        window.addEventListener('online', onOnline);
        document.addEventListener('visibilitychange', onVisible);
        const off = outbox.onChange(() => setTimeout(sync, 500));
        return () => {
            clearInterval(timer);
            window.removeEventListener('online', onOnline);
            document.removeEventListener('visibilitychange', onVisible);
            off();
        };
    }, [sync]);

    return null;
}

function OutboxModal({ items, open, onClose }) {
    const { user } = useAuth();
    const [busy, setBusy] = useState(false);

    async function retry(item) {
        setBusy(true);
        await outbox.update(item.id, { state: 'pending', error: null });
        await flushOutbox(user.id);
        setBusy(false);
    }

    return (
        <Modal open={open} onClose={onClose} title="Envíos guardados en el teléfono" size="md">
            {items.length === 0 ? (
                <p className="text-sm text-muted">No hay envíos pendientes.</p>
            ) : (
                <ul className="space-y-3">
                    {items.map((item) => (
                        <li key={item.id} className={cx('rounded-xl border p-3.5', item.state === 'error' ? 'border-red-200 bg-red-50/50' : 'border-line')}>
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <span className="font-semibold text-ink tabular">{item.ticketNumber}</span>
                                <span className="text-xs text-muted">{formatDateTime(item.createdAt)}</span>
                            </div>
                            <div className="mt-1.5 flex items-center gap-2 text-sm text-body">
                                {item.type === 'status' ? (
                                    <>Cambio de estado a <StatusBadge status={item.payload.status} size="sm" /></>
                                ) : (
                                    <>Atención en sitio{item.payload.photo ? ' con foto de la constancia' : ''}</>
                                )}
                            </div>
                            {item.state === 'error' && (
                                <>
                                    <p className="mt-2 text-sm text-red-700">{item.error}</p>
                                    <div className="mt-3 flex gap-2">
                                        <Button size="sm" variant="secondary" icon={RefreshCw} loading={busy} onClick={() => retry(item)}>Reintentar</Button>
                                        <Button
                                            size="sm"
                                            variant="danger"
                                            icon={Trash2}
                                            onClick={() => window.confirm('¿Descartar este envío? Se perderá lo registrado.') && outbox.remove(item.id)}
                                        >
                                            Descartar
                                        </Button>
                                    </div>
                                </>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </Modal>
    );
}

/** Aviso fijo arriba del contenido: sin señal, envíos pendientes o con error. */
export function ConnectivityBar() {
    const { user } = useAuth();
    const online = useOnline();
    const items = useOutbox(user.id);
    const [open, setOpen] = useState(false);
    const errors = items.filter((i) => i.state === 'error').length;
    const pending = items.length - errors;

    if (online && items.length === 0) {
        return null;
    }

    let tone = 'bg-brand-50 text-brand-700 border-brand-200';
    let Icon = CloudUpload;
    let text = `${pending} envío(s) guardados en el teléfono · se enviarán en cuanto haya conexión`;
    if (!online) {
        tone = 'bg-amber-50 text-amber-900 border-amber-200';
        Icon = WifiOff;
        text = pending
            ? `Sin señal · ${pending} envío(s) guardados en el teléfono. Se enviarán solos al volver la conexión.`
            : 'Sin señal · Puede seguir trabajando: los cambios se guardan en el teléfono.';
    }
    if (errors) {
        tone = 'bg-red-50 text-red-800 border-red-200';
        Icon = AlertTriangle;
        text = `${errors} envío(s) no se pudieron registrar. Revíselos.`;
    }

    return (
        <>
            <button
                type="button"
                onClick={() => items.length && setOpen(true)}
                className={cx('mb-5 flex w-full items-center gap-3 rounded-xl border px-4 py-3 text-left text-sm font-medium', tone, !items.length && 'cursor-default')}
            >
                <Icon className="size-5 shrink-0" />
                <span className="flex-1">{text}</span>
                {items.length > 0 && <span className="shrink-0 text-xs font-semibold underline">Ver</span>}
            </button>
            <OutboxModal items={items} open={open} onClose={() => setOpen(false)} />
        </>
    );
}
