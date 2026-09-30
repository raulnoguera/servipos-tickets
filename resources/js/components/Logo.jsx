import { useRef } from 'react';
import { toast } from './ui';
import { SIGNATURE } from '../lib/signature';

/** Cinco toques seguidos sobre el logo muestran la autoría del sistema. */
function useSignatureTaps() {
    const taps = useRef([]);
    return () => {
        const now = Date.now();
        taps.current = [...taps.current.filter((t) => now - t < 2500), now];
        if (taps.current.length >= 5) {
            taps.current = [];
            toast(SIGNATURE);
        }
    };
}

/** Símbolo "SP" del logo corporativo de ServiPos 2019 (sobre fondo claro). */
export function LogoMark({ className = 'h-10' }) {
    return <img src="/brand/mark.png" alt="ServiPos 2019" className={`w-auto select-none ${className}`} draggable="false" />;
}

/**
 * Logo corporativo. Sobre fondos oscuros (light) el símbolo va en una placa
 * blanca y el nombre en blanco; sobre fondos claros se usa el logo completo.
 */
export function Logo({ light = false, subtitle = true, compact = false, large = false }) {
    const tap = useSignatureTaps();
    if (!light) {
        return <img onClick={tap} src="/brand/logo.png" alt="ServiPos 2019" className={`w-auto select-none ${compact ? 'h-9' : large ? 'h-20' : 'h-12'}`} draggable="false" />;
    }
    return (
        <div onClick={tap} className="flex items-center gap-2.5">
            <span className={`grid shrink-0 place-items-center rounded-lg bg-white shadow-sm ${compact ? 'size-9' : large ? 'size-16 rounded-xl' : 'size-11'}`}>
                <img src="/brand/mark.png" alt="" className={`w-auto select-none ${compact ? 'h-7' : large ? 'h-13' : 'h-9'}`} draggable="false" />
            </span>
            <div className="leading-none">
                <img src="/brand/wordmark-white.png" alt="ServiPos 2019" className={`w-auto select-none ${compact ? 'h-7' : large ? 'h-14' : 'h-9'}`} draggable="false" />
                {subtitle && !compact && (
                    <div className="mt-1.5 text-[9px] font-semibold tracking-[0.16em] whitespace-nowrap text-white/55 uppercase">Monitor de tickets ATM</div>
                )}
            </div>
        </div>
    );
}
