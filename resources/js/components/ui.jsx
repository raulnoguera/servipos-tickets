import { useEffect, useId, useState } from 'react';
import {
    AlertTriangle, CheckCircle2, CircleDot, Clock3, Inbox, Loader2, MapPin, PackageX, UserCheck, X, XCircle, Truck,
} from 'lucide-react';
import { formatDuration } from '../lib/format';

export function cx(...classes) {
    return classes.filter(Boolean).join(' ');
}

/* ---------- Botones ---------- */

const buttonVariants = {
    primary: 'bg-brand-500 text-white hover:bg-brand-600 shadow-sm shadow-brand-500/20',
    secondary: 'bg-white text-ink border border-line hover:border-brand-200 hover:bg-brand-50',
    ghost: 'text-body hover:bg-brand-50 hover:text-ink',
    danger: 'bg-white text-red-600 border border-red-200 hover:bg-red-50',
    success: 'bg-ok text-white hover:brightness-95 shadow-sm',
    whatsapp: 'bg-[#1f9d55] text-white hover:bg-[#1a8a4a] shadow-sm',
};
const buttonSizes = {
    sm: 'h-8 px-3 text-sm gap-1.5',
    md: 'h-10 px-4 text-sm gap-2',
    lg: 'h-12 px-5 text-base gap-2',
};

export function Button({ variant = 'primary', size = 'md', loading, icon: Icon, className, children, as: Tag = 'button', ...props }) {
    return (
        <Tag
            className={cx(
                'inline-flex shrink-0 items-center justify-center rounded-lg font-semibold whitespace-nowrap transition-colors',
                'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500',
                'disabled:cursor-not-allowed disabled:opacity-50',
                buttonVariants[variant],
                buttonSizes[size],
                className,
            )}
            disabled={Tag === 'button' ? loading || props.disabled : undefined}
            {...props}
        >
            {loading ? <Loader2 className="size-4 animate-spin" /> : Icon && <Icon className="size-4" />}
            {children}
        </Tag>
    );
}

/* ---------- Tarjetas y encabezados ---------- */

export function Card({ className, children, ...props }) {
    return (
        <div className={cx('rounded-xl border border-line bg-white shadow-[0_1px_2px_rgba(0,25,76,0.04)]', className)} {...props}>
            {children}
        </div>
    );
}

export function CardHeader({ title, subtitle, actions, className }) {
    return (
        <div className={cx('flex flex-wrap items-start justify-between gap-3 border-b border-line px-5 py-4', className)}>
            <div className="min-w-0">
                <h2 className="text-base font-bold">{title}</h2>
                {subtitle && <p className="mt-0.5 text-sm text-muted">{subtitle}</p>}
            </div>
            {actions && <div className="flex items-center gap-2">{actions}</div>}
        </div>
    );
}

export function PageHeader({ title, subtitle, actions, back }) {
    return (
        <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div className="min-w-0">
                {back}
                <h1 className="text-2xl font-bold tracking-tight sm:text-[28px]">{title}</h1>
                {subtitle && <p className="mt-1 text-sm text-muted">{subtitle}</p>}
            </div>
            {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
        </div>
    );
}

/* ---------- Formularios ---------- */

export function Field({ label, hint, error, required, children, className }) {
    const id = useId();
    return (
        <div className={className}>
            {label && (
                <label htmlFor={id} className="mb-1.5 block text-sm font-semibold text-ink">
                    {label}
                    {required && <span className="ml-0.5 text-red-500">*</span>}
                </label>
            )}
            {typeof children === 'function' ? children(id) : children}
            {error ? <p className="mt-1.5 text-sm text-red-600">{error}</p> : hint && <p className="mt-1.5 text-xs text-muted">{hint}</p>}
        </div>
    );
}

const controlClass =
    'block w-full rounded-lg border border-line bg-white px-3 text-sm text-ink placeholder:text-faint transition ' +
    'focus:border-brand-500 focus:outline-none focus:ring-3 focus:ring-brand-500/15 disabled:bg-surface disabled:text-muted';

export function Input({ className, invalid, ...props }) {
    return <input className={cx(controlClass, 'h-10', invalid && 'border-red-400', className)} {...props} />;
}

export function Select({ className, invalid, children, ...props }) {
    return (
        <select className={cx(controlClass, 'h-10 pr-8', invalid && 'border-red-400', className)} {...props}>
            {children}
        </select>
    );
}

export function Textarea({ className, invalid, ...props }) {
    return <textarea className={cx(controlClass, 'min-h-24 py-2.5', invalid && 'border-red-400', className)} {...props} />;
}

/** Selector de opciones tipo "pastilla" (Sí/No, Hoy/Semana/Mes). */
export function Segmented({ options, value, onChange, size = 'md' }) {
    return (
        <div className="inline-flex rounded-lg border border-line bg-white p-0.5" role="radiogroup">
            {options.map((o) => (
                <button
                    key={o.value}
                    type="button"
                    role="radio"
                    aria-checked={value === o.value}
                    onClick={() => onChange(o.value)}
                    className={cx(
                        'rounded-md font-semibold transition-colors',
                        size === 'sm' ? 'px-3 py-1 text-xs' : 'px-4 py-1.5 text-sm',
                        value === o.value ? 'bg-navy-900 text-white' : 'text-muted hover:text-ink',
                    )}
                >
                    {o.label}
                </button>
            ))}
        </div>
    );
}

export function Toggle({ checked, onChange, label }) {
    return (
        <label className="inline-flex cursor-pointer items-center gap-2 text-sm font-medium text-ink">
            <button
                type="button"
                role="switch"
                aria-checked={checked}
                onClick={() => onChange(!checked)}
                className={cx('relative h-6 w-10 rounded-full transition-colors', checked ? 'bg-brand-500' : 'bg-line')}
            >
                <span className={cx('absolute top-0.5 size-5 rounded-full bg-white shadow transition-all', checked ? 'left-[18px]' : 'left-0.5')} />
            </button>
            {label}
        </label>
    );
}

/* ---------- Estados del ticket (color + ícono + texto, nunca solo color) ---------- */

export const STATUS = {
    abierto: { label: 'Abierto', icon: Inbox, className: 'bg-slate-100 text-slate-700 ring-slate-200' },
    asignado: { label: 'Asignado', icon: UserCheck, className: 'bg-brand-50 text-brand-700 ring-brand-200' },
    en_transito: { label: 'En tránsito', icon: Truck, className: 'bg-violet-50 text-violet-700 ring-violet-200' },
    en_sitio: { label: 'En sitio', icon: MapPin, className: 'bg-sky-50 text-sky-700 ring-sky-200' },
    concluido: { label: 'Concluido', icon: CheckCircle2, className: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
    pendiente_repuesto: { label: 'Pend. repuesto', icon: PackageX, className: 'bg-amber-50 text-amber-800 ring-amber-200' },
    pendiente_validacion: { label: 'Pend. validación', icon: AlertTriangle, className: 'bg-orange-50 text-orange-800 ring-orange-200' },
    anulado: { label: 'Anulado', icon: XCircle, className: 'bg-red-50 text-red-700 ring-red-200' },
};

export function StatusBadge({ status, size = 'md' }) {
    const s = STATUS[status] || { label: status, icon: CircleDot, className: 'bg-slate-100 text-slate-700 ring-slate-200' };
    const Icon = s.icon;
    return (
        <span
            className={cx(
                'inline-flex items-center gap-1 whitespace-nowrap rounded-full font-semibold ring-1 ring-inset',
                size === 'sm' ? 'px-2 py-0.5 text-[11px]' : 'px-2.5 py-1 text-xs',
                s.className,
            )}
        >
            <Icon className={size === 'sm' ? 'size-3' : 'size-3.5'} aria-hidden />
            {s.label}
        </span>
    );
}

export function Badge({ children, tone = 'neutral', className }) {
    const tones = {
        neutral: 'bg-surface text-body ring-line',
        brand: 'bg-brand-50 text-brand-700 ring-brand-200',
        ok: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        warn: 'bg-amber-50 text-amber-800 ring-amber-200',
        danger: 'bg-red-50 text-red-700 ring-red-200',
    };
    return (
        <span className={cx('inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-semibold ring-1 ring-inset', tones[tone], className)}>
            {children}
        </span>
    );
}

/** Tiempo transcurrido del ticket; si sigue abierto se actualiza cada minuto. */
export function Elapsed({ ticket, className }) {
    const [now, setNow] = useState(Date.now());
    useEffect(() => {
        if (!ticket.is_running) {
            return undefined;
        }
        const t = setInterval(() => setNow(Date.now()), 60000);
        return () => clearInterval(t);
    }, [ticket.is_running]);

    if (ticket.duration_ms === null) {
        return <span className={cx('text-faint', className)} title="Histórico importado sin horas">—</span>;
    }
    const ms = ticket.is_running ? now - new Date(ticket.opened_at).getTime() : ticket.duration_ms;
    return (
        <span className={cx('inline-flex items-center gap-1 tabular whitespace-nowrap', className)}>
            {ticket.is_running && <Clock3 className="size-3.5 text-muted" aria-label="En curso" />}
            {formatDuration(ms)}
        </span>
    );
}

/* ---------- Estados de carga y vacíos ---------- */

export function Spinner({ className }) {
    return <Loader2 className={cx('size-5 animate-spin text-brand-500', className)} aria-label="Cargando" />;
}

export function LoadingBlock({ label = 'Cargando…' }) {
    return (
        <div className="flex items-center justify-center gap-2 py-16 text-sm text-muted">
            <Spinner /> {label}
        </div>
    );
}

export function EmptyState({ icon: Icon = Inbox, title, description, action }) {
    return (
        <div className="flex flex-col items-center justify-center px-6 py-14 text-center">
            <div className="mb-3 grid size-12 place-items-center rounded-full bg-brand-50 text-brand-500">
                <Icon className="size-6" />
            </div>
            <p className="font-semibold text-ink">{title}</p>
            {description && <p className="mt-1 max-w-sm text-sm text-muted">{description}</p>}
            {action && <div className="mt-4">{action}</div>}
        </div>
    );
}

export function Alert({ tone = 'danger', children, className }) {
    const tones = {
        danger: 'border-red-200 bg-red-50 text-red-800',
        warn: 'border-amber-200 bg-amber-50 text-amber-900',
        info: 'border-brand-200 bg-brand-50 text-brand-700',
        ok: 'border-emerald-200 bg-emerald-50 text-emerald-800',
    };
    return <div className={cx('rounded-lg border px-4 py-3 text-sm', tones[tone], className)}>{children}</div>;
}

/* ---------- Modal ---------- */

export function Modal({ open, onClose, title, children, footer, size = 'md' }) {
    useEffect(() => {
        if (!open) {
            return undefined;
        }
        const onKey = (e) => e.key === 'Escape' && onClose();
        document.addEventListener('keydown', onKey);
        document.body.style.overflow = 'hidden';
        return () => {
            document.removeEventListener('keydown', onKey);
            document.body.style.overflow = '';
        };
    }, [open, onClose]);

    if (!open) {
        return null;
    }
    const widths = { sm: 'sm:max-w-md', md: 'sm:max-w-xl', lg: 'sm:max-w-3xl' };
    return (
        <div className="fixed inset-0 z-50 flex items-end justify-center bg-navy-900/50 backdrop-blur-[2px] sm:items-center sm:p-4" onMouseDown={onClose}>
            <div
                role="dialog"
                aria-modal="true"
                aria-label={title}
                onMouseDown={(e) => e.stopPropagation()}
                className={cx('flex max-h-[92dvh] w-full flex-col rounded-t-2xl bg-white shadow-2xl sm:rounded-2xl', widths[size])}
            >
                <div className="flex items-center justify-between border-b border-line px-5 py-4">
                    <h2 className="text-lg font-bold">{title}</h2>
                    <button type="button" onClick={onClose} className="rounded-lg p-1.5 text-muted hover:bg-surface hover:text-ink" aria-label="Cerrar">
                        <X className="size-5" />
                    </button>
                </div>
                <div className="overflow-y-auto px-5 py-5">{children}</div>
                {footer && <div className="flex justify-end gap-2 border-t border-line px-5 py-4">{footer}</div>}
            </div>
        </div>
    );
}

/* ---------- Tabla ---------- */

export function Table({ children, className }) {
    return (
        <div className={cx('overflow-x-auto', className)}>
            <table className="w-full min-w-[640px] text-left text-sm">{children}</table>
        </div>
    );
}

export function Th({ children, className }) {
    return (
        <th className={cx('border-b border-line bg-surface px-4 py-2.5 text-[11px] font-bold uppercase tracking-wider text-muted', className)}>
            {children}
        </th>
    );
}

export function Td({ children, className, ...props }) {
    return (
        <td className={cx('border-b border-line/70 px-4 py-3 align-middle', className)} {...props}>
            {children}
        </td>
    );
}

export function Pagination({ meta, onPage }) {
    if (!meta || meta.last_page <= 1) {
        return null;
    }
    return (
        <div className="flex items-center justify-between gap-3 px-4 py-3 text-sm text-muted">
            <span>
                {meta.from}–{meta.to} de {meta.total}
            </span>
            <div className="flex gap-2">
                <Button variant="secondary" size="sm" disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)}>
                    Anterior
                </Button>
                <Button variant="secondary" size="sm" disabled={meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)}>
                    Siguiente
                </Button>
            </div>
        </div>
    );
}

/* ---------- Avisos flotantes ---------- */

let pushToast = () => {};
export const toast = (message, tone = 'ok') => pushToast({ message, tone, id: Math.random() });

export function Toaster() {
    const [items, setItems] = useState([]);
    useEffect(() => {
        pushToast = (t) => {
            setItems((list) => [...list, t]);
            setTimeout(() => setItems((list) => list.filter((i) => i.id !== t.id)), 4000);
        };
    }, []);
    return (
        <div className="pointer-events-none fixed inset-x-0 bottom-4 z-[60] flex flex-col items-center gap-2 px-4 sm:bottom-6 sm:right-6 sm:left-auto sm:items-end">
            {items.map((t) => (
                <div
                    key={t.id}
                    role="status"
                    className={cx(
                        'pointer-events-auto flex max-w-sm items-center gap-2 rounded-xl px-4 py-3 text-sm font-medium text-white shadow-lg',
                        t.tone === 'ok' ? 'bg-navy-900' : 'bg-red-600',
                    )}
                >
                    {t.tone === 'ok' ? <CheckCircle2 className="size-4 text-emerald-300" /> : <AlertTriangle className="size-4" />}
                    {t.message}
                </div>
            ))}
        </div>
    );
}
