import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { AlertTriangle, CheckCircle2, Clock3, PackageX, Plus, RefreshCw, Siren, Wrench } from 'lucide-react';
import { api } from '../lib/api';
import { formatDuration, formatNumber, initials } from '../lib/format';
import {
    Button, Card, CardHeader, Elapsed, EmptyState, LoadingBlock, PageHeader, Segmented, StatusBadge, Table, Td, Th, cx,
} from '../components/ui';
import { DailyChart, HBarChart } from '../components/Charts';
import PushPrompt from '../components/PushPrompt';

const PERIODS = [
    { value: 'day', label: 'Hoy' },
    { value: 'week', label: 'Semana' },
    { value: 'month', label: 'Mes' },
];
const PERIOD_TEXT = { day: 'hoy', week: 'esta semana', month: 'este mes' };

function Kpi({ icon: Icon, label, value, detail, tone, to }) {
    const tones = {
        brand: 'bg-brand-50 text-brand-600',
        warn: 'bg-amber-50 text-amber-700',
        orange: 'bg-orange-50 text-orange-700',
        ok: 'bg-emerald-50 text-emerald-700',
        navy: 'bg-navy-900 text-white',
    };
    const body = (
        <Card className={cx('h-full p-5 transition', to && 'hover:border-brand-200 hover:shadow-md')}>
            <div className="flex items-start justify-between gap-3">
                <p className="text-sm font-semibold text-muted">{label}</p>
                <div className={cx('grid size-9 place-items-center rounded-lg', tones[tone])}>
                    <Icon className="size-[18px]" />
                </div>
            </div>
            <p className="mt-3 font-display text-[34px] leading-none font-extrabold text-ink tabular">{value}</p>
            {detail && <p className="mt-2 text-xs text-muted">{detail}</p>}
        </Card>
    );
    return to ? <Link to={to}>{body}</Link> : body;
}

export default function Dashboard() {
    const [period, setPeriod] = useState('month');
    const navigate = useNavigate();
    const { data, isLoading, isFetching, dataUpdatedAt, refetch } = useQuery({
        queryKey: ['dashboard', period],
        queryFn: () => api('dashboard', { query: { period } }),
        refetchInterval: 30000,
    });

    const k = data?.kpis;
    return (
        <>
            <PageHeader
                title="Monitor de atenciones ATM"
                subtitle={
                    dataUpdatedAt ? (
                        <span className="inline-flex items-center gap-1.5">
                            <span className="relative flex size-2">
                                <span className="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400 opacity-60" />
                                <span className="relative inline-flex size-2 rounded-full bg-emerald-500" />
                            </span>
                            En vivo · actualizado {new Date(dataUpdatedAt).toLocaleTimeString('es-VE', { hour: '2-digit', minute: '2-digit' })}
                        </span>
                    ) : 'Cargando…'
                }
                actions={
                    <>
                        <Segmented options={PERIODS} value={period} onChange={setPeriod} />
                        <Button variant="secondary" icon={RefreshCw} onClick={() => refetch()} loading={isFetching} aria-label="Actualizar" className="px-3" />
                        <Button as={Link} to="/tickets/nuevo" icon={Plus}>Nuevo ticket</Button>
                    </>
                }
            />

            <PushPrompt audience="staff" />

            {isLoading || !k ? (
                <LoadingBlock />
            ) : (
                <div className="space-y-6">
                    <div className="grid grid-cols-2 gap-4 xl:grid-cols-5">
                        <Kpi icon={Siren} tone="brand" label="Tickets activos" value={k.active} detail={`${k.unassigned} sin asignar · ${k.in_field} en campo`} to="/tickets?filtro=en_curso" />
                        <Kpi icon={PackageX} tone="warn" label="Pend. por repuesto" value={k.pending_part} detail="Esperando pieza para culminar" to="/tickets?filtro=pendiente_repuesto" />
                        <Kpi icon={AlertTriangle} tone="orange" label="Pend. por validación" value={k.pending_validation} detail="En revisión técnica / red" to="/tickets?filtro=pendiente_validacion" />
                        <Kpi icon={CheckCircle2} tone="ok" label="Concluidos" value={k.closed} detail={`${k.opened} abiertos ${PERIOD_TEXT[period]}`} to="/tickets?filtro=concluido" />
                        <Kpi icon={Clock3} tone="navy" label="Tiempo promedio" value={k.avg_duration_ms ? formatDuration(k.avg_duration_ms) : '—'} detail={`De apertura a cierre, ${PERIOD_TEXT[period]}`} />
                    </div>

                    <div className="grid gap-6 xl:grid-cols-3">
                        <Card className="xl:col-span-2">
                            <CardHeader title="Tickets abiertos y concluidos" subtitle="Últimos 30 días" />
                            <div className="px-3 pt-3 pb-2">
                                <DailyChart data={data.daily} />
                            </div>
                        </Card>
                        <Card>
                            <CardHeader title="Carga por técnico" subtitle={`Activos, pendientes y concluidos ${PERIOD_TEXT[period]}`} />
                            {data.technicians.length === 0 ? (
                                <EmptyState icon={Wrench} title="Sin tickets asignados" />
                            ) : (
                                <ul className="max-h-[300px] divide-y divide-line overflow-y-auto">
                                    {data.technicians.map((t) => (
                                        <li key={t.id} className="flex items-center gap-3 px-5 py-2.5">
                                            <div className="grid size-8 shrink-0 place-items-center rounded-full bg-brand-50 text-[11px] font-bold text-brand-700">{initials(t.name)}</div>
                                            <span className="min-w-0 flex-1 truncate text-sm font-semibold text-ink">{t.name}</span>
                                            <span className="flex gap-3 text-xs tabular text-muted">
                                                <span title="Activos"><b className="text-brand-600">{t.active}</b> act.</span>
                                                <span title="Pendientes"><b className="text-amber-700">{t.pending}</b> pend.</span>
                                                <span title="Concluidos"><b className="text-emerald-700">{t.closed}</b> conc.</span>
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>
                    </div>

                    <div className="grid gap-6 lg:grid-cols-2">
                        <Card>
                            <CardHeader title="Repuestos más utilizados" subtitle={`Unidades ${PERIOD_TEXT[period]}`} actions={<Link to="/reportes" className="text-sm font-semibold text-brand-600 hover:underline">Ver reporte</Link>} />
                            <div className="px-4 py-3">
                                {data.top_parts.length ? <HBarChart data={data.top_parts} labelKey="name" valueKey="quantity" unit="Unidades" /> : <EmptyState icon={PackageX} title="Sin repuestos registrados" description={`No hay consumo ${PERIOD_TEXT[period]}.`} />}
                            </div>
                        </Card>
                        <Card>
                            <CardHeader title="Fallas reportadas" subtitle={`Tickets abiertos ${PERIOD_TEXT[period]} por tipo`} />
                            <div className="px-4 py-3">
                                {data.by_fault.length ? <HBarChart data={data.by_fault} labelKey="name" valueKey="total" unit="Tickets" /> : <EmptyState title="Sin tickets nuevos" description={`No se abrieron tickets ${PERIOD_TEXT[period]}.`} />}
                            </div>
                        </Card>
                    </div>

                    <Card>
                        <CardHeader title="Últimos tickets" actions={<Link to="/tickets" className="text-sm font-semibold text-brand-600 hover:underline">Ver todos</Link>} />
                        <Table>
                            <thead>
                                <tr>
                                    <Th>Ticket</Th><Th>ATM</Th><Th>Agencia</Th><Th>Técnico</Th><Th>Estado</Th><Th className="text-right">Tiempo</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.recent.map((t) => (
                                    <tr key={t.id} onClick={() => navigate(`/tickets/${t.id}`)} className="cursor-pointer hover:bg-brand-50/50">
                                        <Td className="font-semibold text-brand-600 tabular">{t.number}</Td>
                                        <Td className="font-medium text-ink tabular">{t.atm.atm_number}</Td>
                                        <Td className="max-w-[240px] truncate">{t.atm.agency_name}</Td>
                                        <Td>{t.technician?.name ?? <span className="text-faint">Sin asignar</span>}</Td>
                                        <Td><StatusBadge status={t.status} size="sm" /></Td>
                                        <Td className="text-right"><Elapsed ticket={t} /></Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                        {!data.recent.length && <EmptyState title="Aún no hay tickets" description="Abra el primero desde “Nuevo ticket”." />}
                    </Card>
                    <p className="text-center text-xs text-faint">Se actualiza automáticamente cada 30 segundos · {formatNumber(k.active + k.pending_part + k.pending_validation)} tickets sin cerrar</p>
                </div>
            )}
        </>
    );
}
