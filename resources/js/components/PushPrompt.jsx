import { useEffect, useState } from 'react';
import { BellOff, BellRing, Share, SquarePlus } from 'lucide-react';
import { enablePush, pushState, sendTestPush } from '../lib/push';
import { Button, Card, toast } from './ui';

/** Tarjeta para activar los avisos de nuevos tickets en el teléfono del técnico. */
export default function PushPrompt() {
    const [state, setState] = useState(null);
    const [busy, setBusy] = useState(false);

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

    if (!state || state === 'subscribed' || state === 'disabled' || state === 'unsupported') {
        return state === 'subscribed' ? (
            <p className="mb-5 flex items-center gap-2 text-xs text-muted">
                <BellRing className="size-4 text-emerald-600" /> Avisos de nuevos tickets activados en este teléfono.
            </p>
        ) : null;
    }

    return (
        <Card className="mb-6 border-brand-200 bg-brand-50/60 p-4">
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
                        <p className="mt-1 text-body">Actívelas en los ajustes del teléfono para ServiPos (o el navegador) y vuelva a abrir la app.</p>
                    </div>
                </div>
            ) : (
                <div className="flex flex-wrap items-center gap-3">
                    <BellRing className="size-6 shrink-0 text-brand-600" />
                    <div className="min-w-0 flex-1 text-sm">
                        <p className="font-semibold text-ink">Reciba un aviso cuando le asignen un ticket</p>
                        <p className="text-body">Aunque tenga la app cerrada.</p>
                    </div>
                    <Button onClick={enable} loading={busy} className="w-full sm:w-auto">Activar avisos</Button>
                </div>
            )}
        </Card>
    );
}
