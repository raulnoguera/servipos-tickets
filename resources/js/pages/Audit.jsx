import { useEffect, useState } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { Search, ShieldCheck } from 'lucide-react';
import { api } from '../lib/api';
import { formatDateTime } from '../lib/format';
import { Badge, Card, EmptyState, Input, LoadingBlock, PageHeader, Pagination, Select, Table, Td, Th } from '../components/ui';

/** Grupos de acciones, según el prefijo de `action`. */
const GROUPS = {
    auth: { label: 'Accesos', tone: 'brand' },
    user: { label: 'Usuarios', tone: 'warn' },
    catalog: { label: 'Catálogos', tone: 'neutral' },
    atm: { label: 'Cajeros', tone: 'neutral' },
    sla: { label: 'SLA', tone: 'warn' },
    import: { label: 'Importación', tone: 'warn' },
    export: { label: 'Descargas', tone: 'ok' },
};

/** Acciones que conviene resaltar: posibles intentos de abuso. */
const ALERTS = { 'auth.lockout': 'Bloqueo', 'auth.denied': 'Denegado' };

function useDebounced(value, ms = 350) {
    const [debounced, setDebounced] = useState(value);
    useEffect(() => {
        const timer = setTimeout(() => setDebounced(value), ms);
        return () => clearTimeout(timer);
    }, [value, ms]);
    return debounced;
}

function Detail({ meta }) {
    if (!meta) {
        return null;
    }
    const lines = Object.entries(meta).map(([key, value]) => {
        if (value && typeof value === 'object' && 'antes' in value) {
            return `${key}: ${String(value.antes ?? '—')} → ${String(value.ahora ?? '—')}`;
        }
        return `${key}: ${typeof value === 'object' ? JSON.stringify(value) : String(value)}`;
    });
    return <p className="mt-0.5 max-w-xl truncate text-xs text-muted" title={lines.join('\n')}>{lines.join(' · ')}</p>;
}

/** Registro de auditoría: accesos, gestión de usuarios, catálogos, SLA, importaciones y descargas. */
export default function Audit() {
    const [group, setGroup] = useState('');
    const [q, setQ] = useState('');
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');
    const [page, setPage] = useState(1);
    const term = useDebounced(q);

    const { data, isLoading, error } = useQuery({
        queryKey: ['audit', group, term, from, to, page],
        queryFn: () => api('audit', { query: { group, q: term, from, to, page } }),
        placeholderData: keepPreviousData,
    });
    const reset = (setter) => (e) => { setter(e.target.value); setPage(1); };

    return (
        <>
            <PageHeader title="Auditoría" subtitle="Quién hizo qué y cuándo: accesos, usuarios, catálogos, metas de SLA, importaciones y descargas. El ciclo de cada ticket está en su propio historial." />
            <Card>
                <div className="flex flex-wrap items-end gap-3 border-b border-line p-4">
                    <div className="relative min-w-[220px] flex-1">
                        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-faint" />
                        <Input value={q} onChange={reset(setQ)} placeholder="Buscar por usuario, descripción o IP" className="pl-9" aria-label="Buscar" />
                    </div>
                    <div className="w-48">
                        <Select value={group} onChange={reset(setGroup)} aria-label="Tipo de acción">
                            <option value="">Todas las acciones</option>
                            {Object.entries(GROUPS).map(([key, g]) => <option key={key} value={key}>{g.label}</option>)}
                        </Select>
                    </div>
                    <label className="w-40 text-xs font-semibold text-muted">
                        Desde
                        <Input type="date" value={from} onChange={reset(setFrom)} className="mt-1" />
                    </label>
                    <label className="w-40 text-xs font-semibold text-muted">
                        Hasta
                        <Input type="date" value={to} onChange={reset(setTo)} className="mt-1" />
                    </label>
                </div>

                {isLoading ? (
                    <LoadingBlock />
                ) : error ? (
                    <EmptyState title="No se pudo cargar la auditoría" description={error.message} />
                ) : !data.data.length ? (
                    <EmptyState icon={ShieldCheck} title="Sin registros" description="No hay acciones registradas con estos filtros." />
                ) : (
                    <Table>
                        <thead>
                            <tr><Th>Fecha</Th><Th>Usuario</Th><Th>Tipo</Th><Th>Acción</Th><Th>IP</Th></tr>
                        </thead>
                        <tbody>
                            {data.data.map((row) => {
                                const g = GROUPS[row.action.split('.')[0]] ?? { label: row.action, tone: 'neutral' };
                                return (
                                    <tr key={row.id} className="align-top">
                                        <Td className="whitespace-nowrap tabular">{formatDateTime(row.at)}</Td>
                                        <Td className="whitespace-nowrap">{row.user ?? <span className="text-faint">—</span>}</Td>
                                        <Td>
                                            <Badge tone={ALERTS[row.action] ? 'danger' : g.tone}>{ALERTS[row.action] ?? g.label}</Badge>
                                        </Td>
                                        <Td>
                                            <span className="text-ink">{row.description}</span>
                                            <Detail meta={row.meta} />
                                        </Td>
                                        <Td className="whitespace-nowrap text-xs text-muted tabular">{row.ip ?? '—'}</Td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </Table>
                )}
                <Pagination meta={data?.meta} onPage={setPage} />
            </Card>
        </>
    );
}
