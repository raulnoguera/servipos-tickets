import { CheckCircle2, Clock3, Flame } from 'lucide-react';
import { formatDateTime } from '../lib/format';
import { Field, Select, cx } from './ui';

/** Semáforo del SLA: el color depende de la zona del cajero y de las horas hábiles transcurridas. */
export const SLA_LEVEL = {
    verde: { label: 'Verde', className: 'bg-emerald-600 text-white ring-emerald-600', bar: 'bg-emerald-500', icon: CheckCircle2 },
    naranja: { label: 'Naranja', className: 'bg-orange-500 text-white ring-orange-500', bar: 'bg-orange-500', icon: Clock3 },
    critica: { label: 'Crítica', className: 'bg-red-600 text-white ring-red-600', bar: 'bg-red-600', icon: Flame },
};

const ZONES = [{ value: 'urbana', label: 'Urbana' }, { value: 'extraurbana', label: 'Extraurbana' }];

/**
 * Tipo de SLA del ticket (urbana o extraurbana). Viene con la zona del cajero
 * y se puede cambiar solo para este ticket.
 */
export function SlaZoneField({ value, onChange, atmZone, zones, error, className }) {
    const list = zones?.length ? zones : ZONES;
    const current = list.find((z) => z.value === value);
    const atmLabel = list.find((z) => z.value === atmZone)?.label;
    const hint = [
        current?.rule && `${current.rule.charAt(0).toUpperCase()}${current.rule.slice(1)}.`,
        atmLabel && (value === atmZone ? `Es la zona del cajero (${atmLabel.toLowerCase()}).` : `Distinto de la zona del cajero (${atmLabel.toLowerCase()}): solo para este ticket.`),
    ].filter(Boolean).join(' ');
    return (
        <Field label="Tipo de SLA" hint={hint} error={error} className={className}>
            {(id) => (
                <Select id={id} value={value} onChange={(e) => onChange(e.target.value)}>
                    {list.map((z) => <option key={z.value} value={z.value}>{z.label}</option>)}
                </Select>
            )}
        </Field>
    );
}

/** Orden para listar: lo más urgente primero. */
export const LEVEL_WEIGHT = { critica: 3, naranja: 2, verde: 1 };

/** Pastilla del color; `level` es 'verde' | 'naranja' | 'critica' (o null si no se mide). */
export function LevelBadge({ level, size = 'md', title }) {
    const s = SLA_LEVEL[level];
    if (!s) {
        return <span className="text-xs text-faint">—</span>;
    }
    return (
        <span title={title} className={cx('inline-flex items-center gap-1 rounded-full font-semibold whitespace-nowrap ring-1 ring-inset', size === 'sm' ? 'px-2 py-0.5 text-[11px]' : 'px-2.5 py-1 text-xs', s.className)}>
            <s.icon className="size-3" aria-hidden /> {s.label}
        </span>
    );
}

export function SlaBadge({ sla, size = 'md' }) {
    return <LevelBadge level={sla?.level} size={size} title={sla?.zone_label ? `SLA zona ${sla.zone_label.toLowerCase()}` : undefined} />;
}

/** Tiempo hábil legible: «45 min», «3 h 20 min», «2 días hábiles y 3 h» (un día = una jornada bancaria). */
export function formatBusiness(minutes, dayMinutes) {
    if (minutes == null) {
        return '—';
    }
    if (minutes < 60) {
        return `${minutes} min`;
    }
    if (!dayMinutes || minutes < dayMinutes) {
        return `${Math.floor(minutes / 60)} h ${String(minutes % 60).padStart(2, '0')} min`;
    }
    const days = Math.floor(minutes / dayMinutes);
    const hours = Math.floor((minutes % dayMinutes) / 60);
    return `${days} ${days === 1 ? 'día hábil' : 'días hábiles'}${hours ? ` y ${hours} h` : ''}`;
}

const plural = (n, one, many) => `${String(n).replace('.', ',')} ${Number(n) === 1 ? one : many}`;

/** «Verde hasta 4 h hábiles · naranja hasta 8 h · después, crítica». */
export function slaRule(sla) {
    if (sla?.green == null) {
        return '';
    }
    const unit = sla.unit === 'days' ? (n) => plural(n, 'día hábil', 'días hábiles') : (n) => plural(n, 'hora hábil', 'horas hábiles');
    return `Verde hasta ${unit(sla.green)} · naranja hasta ${unit(sla.orange)} · después, crítica`;
}

/** Barra del semáforo: tramo verde, naranja y rojo, con la marca del tiempo hábil usado. */
export function SlaMeter({ sla }) {
    if (!sla?.level) {
        return null;
    }
    const scale = sla.orange_minutes * 1.25;
    const pct = (m) => `${Math.min(100, (m / scale) * 100)}%`;
    const s = SLA_LEVEL[sla.level];
    const used = formatBusiness(sla.elapsed_minutes, sla.day_minutes);
    const usedLabel = sla.elapsed_minutes < sla.day_minutes ? `${used} hábiles` : used;
    const detail = !sla.running
        ? `Cerró en ${s.label.toLowerCase()} con ${usedLabel}`
        : sla.level === 'verde'
          ? `Pasa a naranja: ${formatDateTime(sla.orange_at)}`
          : sla.level === 'naranja' ? `Pasa a crítica: ${formatDateTime(sla.critical_at)}` : `En crítica desde ${formatDateTime(sla.critical_at)}`;

    return (
        <div>
            <div className="flex items-center justify-between gap-2 text-sm">
                <span className="font-semibold text-ink">Zona {sla.zone_label.toLowerCase()}</span>
                <LevelBadge level={sla.level} size="sm" />
            </div>
            <div className="relative mt-2.5 h-2.5 overflow-hidden rounded-full bg-red-100" role="progressbar" aria-label="Tiempo hábil usado" aria-valuenow={sla.elapsed_minutes} aria-valuemax={sla.orange_minutes}>
                <div className="absolute inset-y-0 left-0 bg-emerald-100" style={{ width: pct(sla.green_minutes) }} />
                <div className="absolute inset-y-0 bg-orange-100" style={{ left: pct(sla.green_minutes), width: `calc(${pct(sla.orange_minutes)} - ${pct(sla.green_minutes)})` }} />
                <div className={cx('absolute inset-y-0 left-0 rounded-full', s.bar)} style={{ width: `max(6px, ${pct(sla.elapsed_minutes)})` }} />
            </div>
            <div className="mt-1.5 flex justify-between gap-3 text-xs text-muted">
                <span>{detail}</span>
                <span className="shrink-0 tabular">{usedLabel}</span>
            </div>
        </div>
    );
}
