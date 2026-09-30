import { useEffect, useState } from 'react';
import { BellOff, BellRing, CheckCircle2, Send } from 'lucide-react';
import { api } from '../lib/api';
import { useAuth } from '../lib/auth';
import { disablePush, enablePush, isIOS, pushState, sendTestPush } from '../lib/push';
import { Alert, Button, Modal, cx, toast } from './ui';

const SCOPES = [
    { value: 'all', label: 'Todos los tickets', hint: 'Avances y cierres de cualquier ticket.' },
    { value: 'mine', label: 'Solo los que yo abrí', hint: 'Ideal cuando hay varios operadores por turno.' },
    { value: 'none', label: 'Ninguno', hint: 'No recibir avisos de seguimiento.' },
];

const DEVICE_MESSAGES = {
    'ios-install': 'En iPhone/iPad: Compartir → Agregar a pantalla de inicio, y abra ServiPos desde el ícono para activar los avisos.',
    unsupported: 'Este navegador no permite notificaciones. Use Chrome o Edge en la computadora, o Chrome en Android.',
    disabled: 'Las notificaciones no están configuradas en el servidor.',
    denied: 'Las notificaciones están bloqueadas para este sitio. Permítalas en la configuración del navegador (ícono del candado junto a la dirección) y vuelva a abrir esta ventana.',
};

/** Ajustes de notificaciones: activar en este dispositivo y, para personal, qué avisos recibir. */
export default function NotificationSettings({ open, onClose }) {
    const { user, updateUser, isTechnician } = useAuth();
    const [state, setState] = useState(null);
    const [busy, setBusy] = useState(null);

    useEffect(() => {
        if (open) {
            setState(null);
            pushState().then(setState).catch(() => setState('unsupported'));
        }
    }, [open]);

    async function run(action, fn) {
        setBusy(action);
        try {
            await fn();
        } catch (error) {
            toast(error.message, 'error');
        } finally {
            setBusy(null);
            setState(await pushState().catch(() => 'unsupported'));
        }
    }

    async function setScope(scope) {
        const r = await api('me/notifications', { method: 'PUT', body: { notify_scope: scope } });
        updateUser(r.data);
        toast('Preferencia guardada');
    }

    return (
        <Modal open={open} onClose={onClose} title="Notificaciones" size="sm">
            <section>
                <h3 className="text-sm font-bold text-ink">En este dispositivo</h3>
                {state === null ? (
                    <p className="mt-2 text-sm text-muted">Revisando…</p>
                ) : state === 'subscribed' ? (
                    <div className="mt-2 space-y-3">
                        <p className="flex items-center gap-2 text-sm text-emerald-700"><CheckCircle2 className="size-4" /> Avisos activados</p>
                        <div className="flex flex-wrap gap-2">
                            <Button size="sm" variant="secondary" icon={Send} loading={busy === 'test'} onClick={() => run('test', async () => {
                                const r = await sendTestPush();
                                toast(r.delivered ? 'Notificación de prueba enviada' : 'No se pudo entregar; revise los permisos', r.delivered ? 'ok' : 'error');
                            })}>
                                Enviar prueba
                            </Button>
                            <Button size="sm" variant="ghost" icon={BellOff} loading={busy === 'off'} onClick={() => run('off', () => disablePush({ byUser: true }))}>Desactivar aquí</Button>
                        </div>
                    </div>
                ) : state === 'available' ? (
                    <div className="mt-2 space-y-2">
                        <p className="text-sm text-body">
                            {isTechnician ? 'Reciba un aviso cuando le asignen o anulen un ticket, y la confirmación de cada cierre.' : 'Reciba avisos cuando los técnicos avancen o cierren tickets.'}
                        </p>
                        <Button icon={BellRing} loading={busy === 'on'} onClick={() => run('on', async () => {
                            await enablePush();
                            await sendTestPush().catch(() => null);
                            toast('Avisos activados');
                        })}>
                            Activar en este dispositivo
                        </Button>
                    </div>
                ) : (
                    <Alert tone="warn" className="mt-2">{DEVICE_MESSAGES[state]}</Alert>
                )}
                {!isIOS() && state !== 'unsupported' && (
                    <p className="mt-3 text-xs text-muted">Actívelo en cada computadora o teléfono donde quiera recibir avisos.</p>
                )}
            </section>

            {!isTechnician && (
                <section className="mt-6 border-t border-line pt-5">
                    <h3 className="text-sm font-bold text-ink">¿De qué tickets quiere avisos?</h3>
                    <div className="mt-3 space-y-2">
                        {SCOPES.map((s) => (
                            <label
                                key={s.value}
                                className={cx(
                                    'flex cursor-pointer items-start gap-3 rounded-xl border p-3 transition',
                                    user.notify_scope === s.value ? 'border-brand-500 bg-brand-50' : 'border-line hover:border-brand-200',
                                )}
                            >
                                <input type="radio" name="notify_scope" className="mt-0.5 size-4 accent-brand-500" checked={user.notify_scope === s.value} onChange={() => setScope(s.value).catch((e) => toast(e.message, 'error'))} />
                                <span>
                                    <span className="block text-sm font-semibold text-ink">{s.label}</span>
                                    <span className="text-xs text-muted">{s.hint}</span>
                                </span>
                            </label>
                        ))}
                    </div>
                </section>
            )}
        </Modal>
    );
}
