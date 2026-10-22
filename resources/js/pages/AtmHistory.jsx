import { Link, useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeft, Building2, MapPin, Plus } from 'lucide-react';
import { api } from '../lib/api';
import { formatDateTime, formatDuration, mapsUrl } from '../lib/format';
import { Button, Card, CardHeader, EmptyState, LoadingBlock, StatusBadge, Table, Td, Th } from '../components/ui';
import { HBarChart } from '../components/Charts';
import { LevelBadge, PriorityBadge } from '../components/Sla';

function Stat({ label, value }) {
    return (
        <div className="rounded-xl bg-surface p-4">
            <p className="text-xs text-muted">{label}</p>
            <p className="mt-1 font-display text-2xl font-extrabold text-ink tabular">{value}</p>
        </div>
    );
}

/** Hoja de vida del cajero: datos, indicadores, fallas, repuestos e historial de tickets. */
export default function AtmHistory() {
    const { id } = useParams();
    const { data, isLoading, error } = useQuery({ queryKey: ['atm-history', id], queryFn: () => api(`reports/atms/${id}`) });

    if (isLoading) {
        return <LoadingBlock />;
    }
    if (error) {
        return <EmptyState title="No se encontró el cajero" action={<Button as={Link} to="/cajeros" variant="secondary">Volver</Button>} />;
    }
    const { atm, stats } = data;

    return (
        <>
            <Link to="/cajeros" className="mb-3 inline-flex items-center gap-1.5 text-sm font-semibold text-muted hover:text-ink">
                <ArrowLeft className="size-4" /> Cajeros
            </Link>
            <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
                <div className="flex items-start gap-4">
                    <span className="grid size-12 shrink-0 place-items-center rounded-xl bg-brand-500 text-white"><Building2 className="size-6" /></span>
                    <div>
                        <p className="text-xs font-semibold tracking-wide text-brand-600 uppercase">Hoja de vida · ATM {atm.atm_number}</p>
                        <h1 className="font-display text-2xl font-extrabold">{atm.agency_name}</h1>
                        <p className="text-sm text-muted">
                            {[atm.agency_code && `Cód. ${atm.agency_code}`, atm.state?.name, [atm.brand, atm.model].filter(Boolean).join(' '), atm.serial && `Serial ${atm.serial}`, atm.bank?.name].filter(Boolean).join(' · ')}
                        </p>
                        {atm.address && <p className="mt-1 text-sm text-body">{atm.address}</p>}
                    </div>
                </div>
                <div className="flex gap-2">
                    <Button as="a" href={mapsUrl({ ...atm, state: atm.state?.name })} target="_blank" rel="noreferrer" variant="secondary" icon={MapPin}>Mapa</Button>
                    <Button as={Link} to="/tickets/nuevo" icon={Plus}>Nuevo ticket</Button>
                </div>
            </div>

            <div className="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <Stat label="Tickets totales" value={stats.tickets} />
                <Stat label="Abiertos ahora" value={stats.open} />
                <Stat label="Últimos 90 días" value={stats.last_90_days} />
                <Stat label="Visitas" value={stats.visits} />
                <Stat label="Repuestos (unid.)" value={stats.parts_units} />
                <Stat label="Tiempo prom. resolución" value={stats.mttr_ms ? formatDuration(stats.mttr_ms) : '—'} />
            </div>

            <div className="mb-6 grid gap-6 lg:grid-cols-2">
                <Card>
                    <CardHeader title="Fallas reportadas" />
                    <div className="px-4 py-3">{data.faults.length ? <HBarChart data={data.faults} labelKey="name" valueKey="total" unit="Tickets" /> : <EmptyState title="Sin fallas registradas" />}</div>
                </Card>
                <Card>
                    <CardHeader title="Repuestos cambiados" />
                    <div className="px-4 py-3">{data.parts.length ? <HBarChart data={data.parts} labelKey="name" valueKey="quantity" unit="Unidades" /> : <EmptyState title="Sin repuestos cambiados" />}</div>
                </Card>
            </div>

            <Card>
                <CardHeader title="Historial de tickets" subtitle={`${data.tickets.length} más recientes`} />
                {!data.tickets.length ? (
                    <EmptyState title="Este cajero no tiene tickets" />
                ) : (
                    <Table>
                        <thead><tr><Th>Ticket</Th><Th>Falla</Th><Th>Prioridad</Th><Th>SLA</Th><Th>Técnico</Th><Th>Repuestos</Th><Th>Estado</Th><Th className="text-right">Duración</Th></tr></thead>
                        <tbody>
                            {data.tickets.map((t) => (
                                <tr key={t.id} className="hover:bg-brand-50/40">
                                    <Td className="whitespace-nowrap">
                                        <Link to={`/tickets/${t.id}`} className="font-semibold text-brand-600 tabular hover:underline">{t.number}</Link>
                                        <div className="text-xs text-muted">{formatDateTime(t.opened_at)}</div>
                                    </Td>
                                    <Td className="max-w-[180px] truncate">{t.fault_type ?? '—'}</Td>
                                    <Td><PriorityBadge priority={t.priority} size="sm" /></Td><Td><LevelBadge level={t.sla} size="sm" /></Td>
                                    <Td className="whitespace-nowrap">{t.technician ?? '—'}</Td>
                                    <Td className="max-w-[220px] text-xs">{t.parts || <span className="text-faint">—</span>}</Td>
                                    <Td><StatusBadge status={t.status} size="sm" /></Td>
                                    <Td className="text-right tabular">{t.duration_ms !== null ? formatDuration(t.duration_ms) : '—'}</Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}
            </Card>
        </>
    );
}
