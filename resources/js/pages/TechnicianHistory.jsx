import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { CheckCircle2, ChevronRight, ClipboardList, History, Package, Search } from 'lucide-react';
import { api } from '../lib/api';
import { useAuth } from '../lib/auth';
import { formatDate, formatDuration } from '../lib/format';
import { Alert, Button, Card, EmptyState, Input, LoadingBlock, StatusBadge, cx } from '../components/ui';

const FILTERS = [
    { value: '', label: 'Todos' },
    { value: 'concluido', label: 'Concluidos' },
    { value: 'pendiente_repuesto,pendiente_validacion,por_confirmar', label: 'Pendientes' },
];

function Stat({ icon: Icon, label, value }) {
    return (
        <Card className="p-3.5">
            <div className="flex items-center gap-2 text-xs font-semibold text-muted"><Icon className="size-4 text-brand-500" /> {label}</div>
            <p className="mt-1 font-display text-2xl font-extrabold text-ink tabular">{value ?? '—'}</p>
        </Card>
    );
}

/** Historial del técnico: todos los tickets que atendió, con búsqueda y resumen del mes. */
export default function TechnicianHistory() {
    const { user } = useAuth();
    const [q, setQ] = useState('');
    const [term, setTerm] = useState('');
    const [status, setStatus] = useState('');
    const [perPage, setPerPage] = useState(20);

    useEffect(() => {
        const t = setTimeout(() => setTerm(q.trim()), 300);
        return () => clearTimeout(t);
    }, [q]);

    const summary = useQuery({ queryKey: ['my-summary'], queryFn: () => api('me/summary') });
    const { data, isLoading, isFetching, error } = useQuery({
        queryKey: ['my-history', term, status, perPage],
        queryFn: () => api('tickets', { query: { history: 1, q: term, status, per_page: perPage } }),
        placeholderData: keepPreviousData,
    });
    const month = new Date().toLocaleDateString('es-VE', { month: 'long' });

    return (
        <div className="mx-auto max-w-2xl">
            <div className="mb-5">
                <h1 className="flex items-center gap-2 text-2xl font-extrabold"><History className="size-6 text-brand-500" /> Mi historial</h1>
                <p className="text-sm text-muted">Los tickets que atendió, aunque ya estén cerrados o se hayan reasignado.</p>
            </div>

            <p className="mb-2 text-xs font-bold tracking-wide text-muted uppercase">En {month}</p>
            <div className="mb-6 grid grid-cols-3 gap-3">
                <Stat icon={ClipboardList} label="Atenciones" value={summary.data?.month.visits} />
                <Stat icon={CheckCircle2} label="Concluidos" value={summary.data?.month.closed} />
                <Stat icon={Package} label="Repuestos" value={summary.data?.month.parts_units} />
            </div>

            <div className="relative mb-3">
                <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-faint" />
                <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Buscar por N° de ticket, ATM o agencia" className="h-11 pl-9" aria-label="Buscar en mi historial" />
            </div>
            <div className="mb-4 flex gap-1.5">
                {FILTERS.map((f) => (
                    <button
                        key={f.label}
                        type="button"
                        onClick={() => setStatus(f.value)}
                        className={cx('rounded-full px-3.5 py-1.5 text-sm font-semibold transition-colors', status === f.value ? 'bg-navy-900 text-white' : 'bg-white text-body ring-1 ring-line hover:bg-surface')}
                    >
                        {f.label}
                    </button>
                ))}
            </div>

            {isLoading ? (
                <LoadingBlock />
            ) : error ? (
                <Alert tone="warn">{error.offline ? 'Sin señal y el historial aún no estaba guardado en este teléfono.' : error.message}</Alert>
            ) : !data.data.length ? (
                <Card><EmptyState icon={History} title={term || status ? 'Sin resultados' : 'Aún no tiene atenciones registradas'} description={term || status ? 'Pruebe con otra búsqueda o filtro.' : 'Cuando registre atenciones aparecerán aquí.'} /></Card>
            ) : (
                <>
                    <p className="mb-2 text-xs text-muted">{data.meta.total} ticket(s)</p>
                    <ul className="space-y-2.5">
                        {data.data.map((t) => (
                            <li key={t.id}>
                                <Link to={`/tickets/${t.id}`} className="block rounded-xl border border-line bg-white p-3.5 active:bg-surface">
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="font-display font-extrabold text-ink tabular">{t.number}</span>
                                        <StatusBadge status={t.status} size="sm" />
                                    </div>
                                    <p className="mt-1 truncate text-sm font-semibold text-ink">{t.atm.agency_name}</p>
                                    <p className="truncate text-xs text-muted">ATM {t.atm.atm_number}{t.fault_type && ` · ${t.fault_type}`}</p>
                                    <div className="mt-2 flex items-center justify-between text-xs text-muted">
                                        <span>
                                            {t.closed_at ? `Cerrado ${formatDate(t.closed_at)}` : `Abierto ${formatDate(t.opened_at)}`}
                                            {t.duration_ms !== null && t.closed_at && ` · ${formatDuration(t.duration_ms)}`}
                                        </span>
                                        <span className="inline-flex items-center text-brand-600">
                                            {t.technician && t.technician.id !== user.id ? `Ahora a cargo de ${t.technician.name.split(' ')[0]}` : 'Ver'}
                                            <ChevronRight className="size-4" />
                                        </span>
                                    </div>
                                </Link>
                            </li>
                        ))}
                    </ul>
                    {data.meta.total > data.data.length && (
                        <Button variant="secondary" className="mt-4 w-full" loading={isFetching} onClick={() => setPerPage((n) => n + 20)}>
                            Ver más
                        </Button>
                    )}
                </>
            )}
        </div>
    );
}
