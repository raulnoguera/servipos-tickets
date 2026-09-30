import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Ban, Bell, CheckCheck, CheckCircle2, ClipboardList, MapPin, PackageX, Settings, UserCheck } from 'lucide-react';
import { api } from '../lib/api';
import { playChime } from '../lib/sound';
import NotificationSettings from './NotificationSettings';
import { cx, toast } from './ui';

const KIND = {
    assigned: { icon: UserCheck, className: 'bg-brand-50 text-brand-600' },
    moved: { icon: MapPin, className: 'bg-sky-50 text-sky-600' },
    pending: { icon: PackageX, className: 'bg-amber-50 text-amber-700' },
    closed: { icon: CheckCircle2, className: 'bg-emerald-50 text-emerald-600' },
    cancelled: { icon: Ban, className: 'bg-red-50 text-red-600' },
};

const relative = new Intl.RelativeTimeFormat('es', { numeric: 'auto' });
function ago(iso) {
    const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
    const abs = Math.abs(seconds);
    if (abs < 60) {
        return 'ahora';
    }
    if (abs < 3600) {
        return relative.format(Math.round(seconds / 60), 'minute');
    }
    if (abs < 86400) {
        return relative.format(Math.round(seconds / 3600), 'hour');
    }
    return relative.format(Math.round(seconds / 86400), 'day');
}

/**
 * Escucha los avisos que llegan con la app abierta: actualiza datos, suena y
 * muestra el mensaje. Se monta una sola vez (en Layout), aunque haya dos campanas.
 */
export function PushListener() {
    const navigate = useNavigate();
    const queryClient = useQueryClient();

    useEffect(() => {
        const onPush = (event) => {
            const data = event.detail || {};
            queryClient.invalidateQueries({ queryKey: ['notifications-unread'] });
            queryClient.invalidateQueries({ queryKey: ['notifications'] });
            if (data.url?.startsWith('/tickets/')) {
                queryClient.invalidateQueries({ queryKey: ['ticket', data.url.split('/')[2]] });
                queryClient.invalidateQueries({ queryKey: ['tickets'] });
                queryClient.invalidateQueries({ queryKey: ['my-tickets'] });
                queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            }
            if (document.visibilityState === 'visible' && data.title && data.tag !== 'prueba') {
                playChime();
                toast(data.body, 'info', { title: data.title, duration: 7000, onClick: data.url ? () => navigate(data.url) : undefined });
            }
        };
        window.addEventListener('servipos:push', onPush);
        return () => window.removeEventListener('servipos:push', onPush);
    }, [queryClient, navigate]);

    return null;
}

/**
 * Campana con el historial de avisos. `variant` ajusta el botón al fondo
 * donde se muestra (barra lateral oscura o barra superior del teléfono).
 */
export default function NotificationBell({ variant = 'dark' }) {
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const [open, setOpen] = useState(false);
    const [settings, setSettings] = useState(false);
    const panel = useRef(null);

    const { data: counter } = useQuery({
        queryKey: ['notifications-unread'],
        queryFn: () => api('notifications/unread'),
        refetchInterval: 30000,
    });
    const { data: list, isLoading } = useQuery({
        queryKey: ['notifications'],
        queryFn: () => api('notifications'),
        enabled: open,
    });
    const unread = counter?.unread ?? 0;

    useEffect(() => {
        if (!open) {
            return undefined;
        }
        const onDown = (e) => panel.current && !panel.current.contains(e.target) && setOpen(false);
        const onKey = (e) => e.key === 'Escape' && setOpen(false);
        document.addEventListener('mousedown', onDown);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onDown);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    async function openItem(item) {
        setOpen(false);
        if (!item.read) {
            api(`notifications/${item.id}/read`, { method: 'POST' })
                .then((r) => {
                    queryClient.setQueryData(['notifications-unread'], r);
                    queryClient.invalidateQueries({ queryKey: ['notifications'] });
                })
                .catch(() => {});
        }
        if (item.url) {
            navigate(item.url);
        }
    }

    async function readAll() {
        const r = await api('notifications/read-all', { method: 'POST' }).catch(() => null);
        if (r) {
            queryClient.setQueryData(['notifications-unread'], r);
            queryClient.invalidateQueries({ queryKey: ['notifications'] });
        }
    }

    return (
        <div className="relative" ref={panel}>
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                aria-label={unread ? `Notificaciones: ${unread} sin leer` : 'Notificaciones'}
                title="Notificaciones"
                className={cx(
                    'relative rounded-lg p-2 transition-colors',
                    variant === 'dark' ? 'text-white/75 hover:bg-white/10 hover:text-white' : 'text-body hover:bg-brand-50',
                )}
            >
                <Bell className="size-5" />
                {unread > 0 && (
                    <span className="absolute -top-0.5 -right-0.5 grid min-w-[18px] place-items-center rounded-full bg-danger px-1 text-[10px] leading-[18px] font-bold text-white ring-2 ring-navy-900">
                        {unread > 99 ? '99+' : unread}
                    </span>
                )}
            </button>

            {open && (
                <div className="fixed inset-x-3 top-16 z-50 flex max-h-[75dvh] flex-col overflow-hidden rounded-2xl border border-line bg-white shadow-2xl sm:absolute sm:inset-x-auto sm:top-11 sm:right-0 sm:w-[380px] lg:right-auto lg:left-0">
                    <div className="flex items-center justify-between border-b border-line px-4 py-3">
                        <div>
                            <p className="font-display text-base font-bold text-ink">Notificaciones</p>
                            <p className="text-xs text-muted">{unread ? `${unread} sin leer` : 'Todo al día'}</p>
                        </div>
                        <div className="flex gap-1">
                            {unread > 0 && (
                                <button type="button" onClick={readAll} className="inline-flex items-center gap-1 rounded-lg px-2 py-1.5 text-xs font-semibold text-brand-600 hover:bg-brand-50">
                                    <CheckCheck className="size-4" /> Marcar leídas
                                </button>
                            )}
                            <button type="button" onClick={() => { setOpen(false); setSettings(true); }} className="rounded-lg p-1.5 text-muted hover:bg-surface hover:text-ink" title="Ajustes de notificaciones" aria-label="Ajustes de notificaciones">
                                <Settings className="size-4" />
                            </button>
                        </div>
                    </div>
                    <div className="overflow-y-auto">
                        {isLoading ? (
                            <p className="px-4 py-8 text-center text-sm text-muted">Cargando…</p>
                        ) : !list?.data.length ? (
                            <div className="px-6 py-10 text-center">
                                <ClipboardList className="mx-auto size-8 text-brand-200" />
                                <p className="mt-2 text-sm font-semibold text-ink">Sin notificaciones</p>
                                <p className="text-xs text-muted">Aquí verá los avances y cierres de los tickets.</p>
                            </div>
                        ) : (
                            <ul className="divide-y divide-line">
                                {list.data.map((item) => {
                                    const kind = KIND[item.kind] || KIND.moved;
                                    return (
                                        <li key={item.id}>
                                            <button type="button" onClick={() => openItem(item)} className={cx('flex w-full gap-3 px-4 py-3 text-left transition-colors hover:bg-surface', !item.read && 'bg-brand-50/50')}>
                                                <span className={cx('grid size-9 shrink-0 place-items-center rounded-full', kind.className)}>
                                                    <kind.icon className="size-4" />
                                                </span>
                                                <span className="min-w-0 flex-1">
                                                    <span className={cx('block text-sm text-ink', !item.read ? 'font-bold' : 'font-semibold')}>{item.title.replace(/^✅\s*/, '')}</span>
                                                    <span className="mt-0.5 block text-xs leading-snug text-body">{item.body}</span>
                                                    <span className="mt-1 block text-[11px] text-muted">{ago(item.at)}</span>
                                                </span>
                                                {!item.read && <span className="mt-1.5 size-2 shrink-0 rounded-full bg-brand-500" aria-label="Sin leer" />}
                                            </button>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </div>
                </div>
            )}
            <NotificationSettings open={settings} onClose={() => setSettings(false)} />
        </div>
    );
}
