import { ArrowDown, CheckCircle2, Clock3, Flame, Minus, TimerOff } from 'lucide-react';
import { formatDuration } from '../lib/format';
import { cx } from './ui';

export const PRIORITY = {
    critica: { label: 'Crítica', className: 'bg-red-600 text-white ring-red-600', icon: Flame },
    media: { label: 'Media', className: 'bg-yellow-100 text-yellow-900 ring-yellow-400', icon: Minus },
    baja: { label: 'Baja', className: 'bg-emerald-100 text-emerald-800 ring-emerald-400', icon: ArrowDown },
};

export function PriorityBadge({ priority = 'media', size = 'md' }) {
    const p = PRIORITY[priority] || PRIORITY.media;
    return (
        <span className={cx('inline-flex items-center gap-1 rounded-full font-semibold whitespace-nowrap ring-1 ring-inset', size === 'sm' ? 'px-2 py-0.5 text-[11px]' : 'px-2.5 py-1 text-xs', p.className)}>
            <p.icon className="size-3" aria-hidden /> {p.label}
        </span>
    );
}

export const SLA_STATUS = {
    met: { label: 'Cumplido', className: 'text-emerald-700 bg-emerald-50 ring-emerald-200', bar: 'bg-emerald-500', icon: CheckCircle2 },
    on_track: { label: 'En tiempo', className: 'text-brand-700 bg-brand-50 ring-brand-200', bar: 'bg-brand-500', icon: Clock3 },
    at_risk: { label: 'Por vencer', className: 'text-amber-800 bg-amber-50 ring-amber-200', bar: 'bg-amber-500', icon: Clock3 },
    breached: { label: 'Vencido', className: 'text-red-700 bg-red-50 ring-red-200', bar: 'bg-red-500', icon: TimerOff },
    none: { label: 'Sin meta', className: 'text-slate-500 bg-slate-50 ring-slate-200', bar: 'bg-slate-300', icon: Minus },
};

/** El peor estado de las dos metas del ticket (llegada y resolución). */
export function worstSla(sla) {
    const order = ['breached', 'at_risk', 'on_track', 'met', 'none'];
    return order.find((s) => sla?.response?.status === s || sla?.resolution?.status === s) || 'none';
}

export function SlaBadge({ sla, size = 'md' }) {
    const status = worstSla(sla);
    if (status === 'none') {
        return <span className="text-xs text-faint">—</span>;
    }
    const s = SLA_STATUS[status];
    return (
        <span className={cx('inline-flex items-center gap-1 rounded-full font-semibold whitespace-nowrap ring-1 ring-inset', size === 'sm' ? 'px-2 py-0.5 text-[11px]' : 'px-2.5 py-1 text-xs', s.className)}>
            <s.icon className="size-3" aria-hidden /> {s.label}
        </span>
    );
}

/** Barra de una meta de SLA: tiempo usado sobre la meta, con el texto de lo que queda o se excedió. */
export function SlaMeter({ label, clock }) {
    if (!clock?.target_minutes) {
        return (
            <div>
                <div className="flex justify-between text-sm"><span className="font-semibold text-ink">{label}</span><span className="text-faint">Sin meta</span></div>
            </div>
        );
    }
    const s = SLA_STATUS[clock.status] || SLA_STATUS.none;
    const ratio = Math.min(1, clock.elapsed_minutes / clock.target_minutes);
    const left = clock.target_minutes - clock.elapsed_minutes;
    const running = clock.status === 'on_track' || clock.status === 'at_risk';
    const detail = running
        ? `Quedan ${formatDuration(left * 60000)}`
        : clock.status === 'breached'
          ? `Excedido por ${formatDuration(Math.abs(left) * 60000)}`
          : clock.status === 'met' ? `En ${formatDuration(clock.elapsed_minutes * 60000)}` : '';

    return (
        <div>
            <div className="flex items-center justify-between gap-2 text-sm">
                <span className="font-semibold text-ink">{label}</span>
                <span className={cx('inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset', s.className)}>
                    <s.icon className="size-3" /> {s.label}
                </span>
            </div>
            <div className="mt-2 h-2 overflow-hidden rounded-full bg-surface ring-1 ring-line ring-inset" role="progressbar" aria-valuenow={Math.round(ratio * 100)} aria-valuemin={0} aria-valuemax={100} aria-label={label}>
                <div className={cx('h-full rounded-full transition-all', s.bar)} style={{ width: `${Math.max(3, ratio * 100)}%` }} />
            </div>
            <div className="mt-1 flex justify-between text-xs text-muted">
                <span>{detail}</span>
                <span>Meta {formatDuration(clock.target_minutes * 60000)}</span>
            </div>
        </div>
    );
}
