import { useEffect, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { Download, Plus, Search } from 'lucide-react';
import { api } from '../lib/api';
import { PriorityBadge, SlaBadge } from '../components/Sla';
import { useCatalogs } from '../lib/queries';
import { formatDateTime } from '../lib/format';
import {
    Button, Card, Elapsed, EmptyState, Input, LoadingBlock, PageHeader, Pagination, Select, StatusBadge, Table, Td, Th, cx,
} from '../components/ui';

const TABS = [
    { value: 'sin_cerrar', label: 'Sin cerrar', query: { open: 1 } },
    { value: 'en_curso', label: 'En curso', query: { status: 'abierto,asignado,en_transito,en_sitio' } },
    { value: 'pendiente_repuesto', label: 'Pend. repuesto', query: { status: 'pendiente_repuesto' } },
    { value: 'pendiente_validacion', label: 'Pend. validación', query: { status: 'pendiente_validacion' } },
    { value: 'por_confirmar', label: 'Por confirmar', query: { status: 'por_confirmar' } },
    { value: 'concluido', label: 'Concluidos', query: { status: 'concluido' } },
    { value: 'vencidos', label: 'SLA vencido', query: { sla: 'breached', open: 1 } },
    { value: 'por_vencer', label: 'Por vencer', query: { sla: 'at_risk', open: 1 } },
    { value: 'anulado', label: 'Anulados', query: { status: 'anulado' } },
    { value: 'todos', label: 'Todos', query: {} },
];

export default function Tickets() {
    const [params, setParams] = useSearchParams();
    const navigate = useNavigate();
    const { data: catalogs } = useCatalogs();
    const tab = TABS.find((t) => t.value === params.get('filtro')) || TABS[0];
    const [q, setQ] = useState(params.get('q') || '');
    const [debounced, setDebounced] = useState(q);
    const technician = params.get('tecnico') || '';
    const bank = params.get('banco') || '';
    const priority = params.get('prioridad') || '';
    const slaParam = params.get('sla') || '';
    const page = Number(params.get('pagina') || 1);

    useEffect(() => {
        const t = setTimeout(() => setDebounced(q), 300);
        return () => clearTimeout(t);
    }, [q]);

    const set = (key, value) => {
        const next = new URLSearchParams(params);
        value ? next.set(key, value) : next.delete(key);
        if (key !== 'pagina') {
            next.delete('pagina');
        }
        setParams(next, { replace: true });
    };

    const { data, isLoading } = useQuery({
        queryKey: ['tickets', tab.value, debounced, technician, bank, priority, slaParam, page],
        queryFn: () => api('tickets', { query: { ...tab.query, ...(slaParam ? { sla: slaParam } : {}), q: debounced, technician_id: technician, bank_id: bank, priority, sort: priority ? undefined : 'priority', page } }),
        placeholderData: keepPreviousData,
        refetchInterval: 30000,
    });

    return (
        <>
            <PageHeader
                title="Tickets"
                subtitle="Seguimiento de todas las incidencias reportadas por los bancos"
                actions={
                    <>
                        <Button as={Link} to="/reportes" variant="secondary" icon={Download}>Exportar Excel</Button>
                        <Button as={Link} to="/tickets/nuevo" icon={Plus}>Nuevo ticket</Button>
                    </>
                }
            />

            <div className="-mx-4 mb-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
                <div className="flex w-max gap-1 rounded-xl border border-line bg-white p-1">
                    {TABS.map((t) => (
                        <button
                            key={t.value}
                            type="button"
                            onClick={() => set('filtro', t.value === 'sin_cerrar' ? '' : t.value)}
                            className={cx(
                                'rounded-lg px-3.5 py-2 text-sm font-semibold whitespace-nowrap transition-colors',
                                tab.value === t.value ? 'bg-navy-900 text-white' : 'text-muted hover:bg-surface hover:text-ink',
                            )}
                        >
                            {t.label}
                        </button>
                    ))}
                </div>
            </div>

            <Card>
                <div className="grid gap-3 border-b border-line p-4 sm:grid-cols-2 lg:grid-cols-[1fr_200px_200px_170px]">
                    <div className="relative">
                        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-faint" />
                        <Input value={q} onChange={(e) => { setQ(e.target.value); set('q', e.target.value); }} placeholder="Buscar por N° de ticket, ATM, agencia o caso del banco" className="pl-9" />
                    </div>
                    <Select value={technician} onChange={(e) => set('tecnico', e.target.value)} aria-label="Técnico">
                        <option value="">Todos los técnicos</option>
                        {catalogs?.technicians.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                    </Select>
                    <Select value={bank} onChange={(e) => set('banco', e.target.value)} aria-label="Banco">
                        <option value="">Todos los bancos</option>
                        {catalogs?.banks.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                    </Select>
                    <Select value={priority} onChange={(e) => set('prioridad', e.target.value)} aria-label="Prioridad">
                        <option value="">Toda prioridad</option>
                        {catalogs?.priorities?.map((p) => <option key={p.value} value={p.value}>{p.label}</option>)}
                    </Select>
                </div>

                {isLoading ? (
                    <LoadingBlock />
                ) : !data?.data.length ? (
                    <EmptyState title="No hay tickets con estos filtros" description="Pruebe con otra pestaña o limpie la búsqueda." />
                ) : (
                    <>
                        {/* Tabla (escritorio) */}
                        <Table className="hidden md:block">
                            <thead>
                                <tr>
                                    <Th>Ticket</Th><Th>ATM</Th><Th>Agencia</Th><Th>Falla</Th><Th>Técnico</Th><Th>Estado</Th><Th>SLA</Th><Th className="text-right">Tiempo</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.data.map((t) => (
                                    <tr key={t.id} onClick={() => navigate(`/tickets/${t.id}`)} className="cursor-pointer hover:bg-brand-50/50">
                                        <Td className="whitespace-nowrap">
                                            <div className="flex items-center gap-1.5"><span className="font-semibold text-brand-600 tabular">{t.number}</span>{['alta', 'critica'].includes(t.priority) && <PriorityBadge priority={t.priority} size="sm" />}</div>
                                            <div className="text-xs text-muted">{formatDateTime(t.opened_at)}</div>
                                        </Td>
                                        <Td className="font-medium text-ink tabular">{t.atm.atm_number}</Td>
                                        <Td className="max-w-[220px]">
                                            <div className="truncate text-ink">{t.atm.agency_name}</div>
                                            <div className="truncate text-xs text-muted">{t.atm.state}</div>
                                        </Td>
                                        <Td className="max-w-[160px] truncate">{t.fault_type ?? '—'}</Td>
                                        <Td className="whitespace-nowrap">{t.technician?.name ?? <span className="text-faint">Sin asignar</span>}</Td>
                                        <Td><StatusBadge status={t.status} size="sm" /></Td>
                                        <Td><SlaBadge sla={t.sla} size="sm" /></Td>
                                        <Td className="text-right"><Elapsed ticket={t} /></Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>

                        {/* Tarjetas (teléfono) */}
                        <ul className="divide-y divide-line md:hidden">
                            {data.data.map((t) => (
                                <li key={t.id}>
                                    <Link to={`/tickets/${t.id}`} className="block px-4 py-3.5 active:bg-surface">
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="flex items-center gap-1.5"><span className="font-semibold text-brand-600 tabular">{t.number}</span><PriorityBadge priority={t.priority} size="sm" /></span>
                                            <StatusBadge status={t.status} size="sm" />
                                        </div>
                                        <p className="mt-1 truncate text-sm font-medium text-ink">{t.atm.atm_number} · {t.atm.agency_name}</p>
                                        <div className="mt-1 flex justify-between text-xs text-muted">
                                            <span className="truncate">{t.technician?.name ?? 'Sin asignar'}</span>
                                            <Elapsed ticket={t} />
                                        </div>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                        <Pagination meta={data.meta} onPage={(p) => set('pagina', String(p))} />
                    </>
                )}
            </Card>
        </>
    );
}
