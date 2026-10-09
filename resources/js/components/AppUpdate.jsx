import { useEffect, useRef, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { RefreshCw } from 'lucide-react';
import { BUILD, alreadyTried, applyUpdate, checkForUpdate } from '../lib/update';
import { cx, toast } from './ui';

const CHECK_EVERY_MS = 15 * 60 * 1000;
const MIN_GAP_MS = 5 * 60 * 1000;

/**
 * Mantiene la app al día sin que el usuario haga nada: revisa si hay una versión
 * nueva al abrir, cada 15 minutos y al volver a la app, y la instala al cambiar de
 * pantalla (nunca en medio de un formulario).
 */
export function UpdateWatcher() {
    const location = useLocation();
    const [pending, setPending] = useState(null);
    const lastCheck = useRef(0);
    const firstPath = useRef(location.pathname);

    useEffect(() => {
        if (!BUILD) {
            return undefined; // en desarrollo no hay versiones publicadas
        }
        const check = async () => {
            if (navigator.onLine === false || Date.now() - lastCheck.current < MIN_GAP_MS) {
                return;
            }
            lastCheck.current = Date.now();
            const result = await checkForUpdate().catch(() => null);
            if (result?.available) {
                setPending(result.latest);
            }
        };
        const first = setTimeout(check, 10000);
        const timer = setInterval(check, CHECK_EVERY_MS);
        const onVisible = () => document.visibilityState === 'visible' && check();
        document.addEventListener('visibilitychange', onVisible);
        window.addEventListener('online', check);
        return () => {
            clearTimeout(first);
            clearInterval(timer);
            document.removeEventListener('visibilitychange', onVisible);
            window.removeEventListener('online', check);
        };
    }, []);

    // Aviso, una vez por versión.
    useEffect(() => {
        if (pending && !alreadyTried(pending)) {
            toast('Se instalará sola al cambiar de pantalla. Toque aquí para instalarla ahora.', 'info', {
                title: 'Hay una nueva versión de la aplicación',
                duration: 12000,
                onClick: () => applyUpdate(pending),
            });
        }
    }, [pending]);

    // Al cambiar de pantalla se instala: en ese momento no hay nada a medio escribir.
    useEffect(() => {
        if (pending && location.pathname !== firstPath.current && !alreadyTried(pending)) {
            applyUpdate(pending);
        }
        firstPath.current = location.pathname;
    }, [location.pathname, pending]);

    return null;
}

/** Versión instalada y botón para buscar una actualización a mano (menú lateral). */
export function UpdateButton() {
    const [busy, setBusy] = useState(false);

    async function search() {
        if (!BUILD) {
            toast('La búsqueda de actualizaciones funciona en la versión publicada.', 'info');
            return;
        }
        setBusy(true);
        try {
            const result = await checkForUpdate();
            if (result.available) {
                toast('Instalando la nueva versión…', 'info');
                await applyUpdate(result.latest);
                return;
            }
            toast('Ya tiene la última versión.');
        } catch {
            toast('Sin conexión: no se pudo buscar actualizaciones.', 'error');
        } finally {
            setBusy(false);
        }
    }

    return (
        <div className="mx-3 flex flex-wrap items-center justify-between gap-x-2 px-1 text-[11px] text-white/45">
            <span className="tabular whitespace-nowrap" title="Versión instalada en este dispositivo">Versión {BUILD ?? 'de desarrollo'}</span>
            <button type="button" onClick={search} disabled={busy} className="-ml-2 inline-flex shrink-0 items-center gap-1.5 rounded-lg px-2 py-1.5 font-semibold text-white/70 hover:bg-white/10 hover:text-white disabled:opacity-60">
                <RefreshCw className={cx('size-3.5', busy && 'animate-spin')} />
                {busy ? 'Buscando…' : 'Buscar actualización'}
            </button>
        </div>
    );
}
