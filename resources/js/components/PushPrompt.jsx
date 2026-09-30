import { useEffect, useState } from 'react';
import { BellOff, BellRing, Share, SquarePlus, X } from 'lucide-react';
import { enablePush, pushState, sendTestPush } from '../lib/push';
import { Button, Card, toast } from './ui';

const TEXT = {
    technician: { title: 'Reciba un aviso cuando le asignen un ticket', detail: 'Aunque tenga la app cerrada.' },
    staff: { title: 'Active los avisos de seguimiento', detail: 'Le avisamos cuando un técnico va en camino, llega al sitio o cierra un ticket.' },
};

/** Tarjeta para activar las notificaciones en este dispositivo. */
const DISMISSED = 'servipos:push-prompt-dismissed';

function isDismissed() {
    try {
        return localStorage.getItem(DISMISSED) === '1';
    } catch {
        return false;
    }
}

export default function PushPrompt({ audience = 'technician' }) {
    const [state, setState] = useState(null);
    const [busy, setBusy] = useState(false);
    const [dismissed, setDismissed] = useState(isDismissed);

    function dismiss() {
        try {
            localStorage.setItem(DISMISSED, '1');
        } catch {
            // sin almacenamiento: solo se oculta en esta visita
        }
        setDismissed(true);
    }

    useEffect(() => {
        pushState().then(setState).catch(() => setState('unsupported'));
    }, []);

    async function enable() {
        setBusy(true);
        try {
            await enablePush();
            setState('subscribed');
            const r = await sendTestPush().catch(() => null);
            toast(r?.delivered ? 'Avisos activados. Le enviamos una notificación de prueba.' : 'Avisos activados');
        } catch (error) {
            toast(error.message, 'error');
            setState(await pushState().catch(() => 'unsupported'));
        } finally {
            setBusy(false);
        }
    }

    if (dismissed && state !== 'subscribed') {
        return null;
    }
    if (!state || state === 'subscribed' || state === 'disabled' || state === 'unsupported') {
        return state === 'subscribed' ? (
            audience === 'technician' && (
                <p className="mb-5 flex items-center gap-2 text-xs text-muted">
                    <BellRing className="size-4 text-emerald-600" /> Avisos de nuevos tickets activados en este teléfono.
                </p>
            )
        ) : null;
    }

    return (
        <Card className="relative mb-6 border-brand-200 bg-brand-50/60 p-4 pr-10">
            <button type="button" onClick={dismiss} className="absolute top-2.5 right-2.5 rounded-lg p-1.5 text-muted hover:bg-white hover:text-ink" aria-label="Ocultar" title="Ocultar (puede activarlos luego desde la campana del menú)">
                <X className="size-4" />
            </button>
            {state === 'ios-install' ? (
                <div className="flex gap-3">
                    <SquarePlus className="mt-0.5 size-6 shrink-0 text-brand-600" />
                    <div className="text-sm">
                        <p className="font-semibold text-ink">Instale la app para recibir avisos</p>
                        <p className="mt-1 text-body">
                            En iPhone, toque <Share className="inline size-4 align-text-bottom" /> <b>Compartir</b> → <b>Agregar a pantalla de inicio</b>,
                            abra ServiPos desde el ícono nuevo e inicie sesión. Ahí podrá activar los avisos.
                        </p>
                    </div>
                </div>
            ) : state === 'denied' ? (
                <div className="flex gap-3">
                    <BellOff className="mt-0.5 size-6 shrink-0 text-amber-600" />
                    <div className="text-sm">
                        <p className="font-semibold text-ink">Notificaciones bloqueadas</p>
                        <p className="mt-1 text-body">
                            {audience === 'staff'
                                ? 'Permítalas en el navegador (ícono del candado junto a la dirección → Notificaciones) y recargue la página.'
                                : 'Actívelas en los ajustes del teléfono para ServiPos (o el navegador) y vuelva a abrir la app.'}
                        </p>
                    </div>
                </div>
            ) : (
                <div className="flex flex-wrap items-center gap-3">
                    <BellRing className="size-6 shrink-0 text-brand-600" />
                    <div className="min-w-0 flex-1 text-sm">
                        <p className="font-semibold text-ink">{TEXT[audience].title}</p>
                        <p className="text-body">{TEXT[audience].detail}</p>
                    </div>
                    <Button onClick={enable} loading={busy} className="w-full sm:w-auto">Activar avisos</Button>
                </div>
            )}
        </Card>
    );
}
