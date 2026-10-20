import { useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import {
    AlertTriangle, BarChart3, Building2, CheckCircle2, ChevronRight, Clock3, Download, FileSpreadsheet, Gauge, Package, PieChart, Printer, Repeat2, Timer, Wrench,
} from 'lucide-react';
import { api, download } from '../lib/api';
import { useCatalogs } from '../lib/queries';
import { formatDate, formatDuration, formatNumber, formatPct, monthToDate } from '../lib/format';
import {
    Alert, Button, Card, CardHeader, EmptyState, Field, Input, LoadingBlock, PageHeader, Select, Table, Td, Th, cx, toast,
} from '../components/ui';
import { CATEGORICAL, ColumnChart, DonutChart, HBarChart, TrendChart, WeeklyChart, donutColor } from '../components/Charts';
import { detailUrl } from './ReportDetail';

const TABS = [
    { key: 'resumen', label: 'Resumen', icon: Gauge },
    { key: 'visual', label: 'Informe visual', icon: PieChart },
    { key: 'cajeros', label: 'Cajeros', icon: Building2 },
    { key: 'tecnicos', label: 'Técnicos', icon: Wrench },
    { key: 'repuestos', label: 'Repuestos', icon: Package },
    { key: 'fallas', label: 'Fallas', icon: AlertTriangle },
    { key: 'consolidado', label: 'Consolidado Excel', icon: FileSpreadsheet },
];

const pad = (n) => String(n).padStart(2, '0');
const iso = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
function quickRanges() {
    const now = new Date();
    const firstThis = new Date(now.getFullYear(), now.getMonth(), 1);
    const firstPrev = new Date(now.getFullYear(), now.getMonth() - 1, 1);
    const lastPrev = new Date(now.getFullYear(), now.getMonth(), 0);
    const minus30 = new Date(now);
    minus30.setDate(now.getDate() - 29);
    return [
        { label: 'Este mes', from: iso(firstThis), to: iso(now) },
        { label: 'Mes anterior', from: iso(firstPrev), to: iso(lastPrev) },
        { label: 'Últimos 30 días', from: iso(minus30), to: iso(now) },
        { label: 'Este año', from: `${now.getFullYear()}-01-01`, to: iso(now) },
    ];
}

/** Filtros compartidos por todas las pestañas, guardados en la dirección (se pueden compartir). */
function useReportFilters() {
    const [params, setParams] = useSearchParams();
    const [defFrom, defTo] = monthToDate();
    const filters = {
        from: params.get('desde') || defFrom,
        to: params.get('hasta') || defTo,
        bank_id: params.get('banco') || '',
        state_id: params.get('estado') || '',
        technician_id: params.get('tecnico') || '',
    };
    const keys = { from: 'desde', to: 'hasta', bank_id: 'banco', state_id: 'estado', technician_id: 'tecnico' };
    const set = (changes) => {
        const next = new URLSearchParams(params);
        Object.entries(changes).forEach(([k, v]) => (v ? next.set(keys[k] || k, v) : next.delete(keys[k] || k)));
        setParams(next, { replace: true });
    };
    return { filters, set, tab: params.get('tab') || 'resumen', setTab: (tab) => set({ tab: tab === 'resumen' ? '' : tab }) };
}

function Filters({ filters, set, showTechnician = true }) {
    const { data: catalogs } = useCatalogs();
    return (
        <Card className="mb-6 p-4">
            <div className="flex flex-wrap gap-1.5">
                {quickRanges().map((r) => (
                    <button
                        key={r.label}
                        type="button"
                        onClick={() => set({ from: r.from, to: r.to })}
                        className={cx(
                            'rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors',
                            filters.from === r.from && filters.to === r.to ? 'bg-navy-900 text-white' : 'bg-surface text-body hover:bg-brand-50',
                        )}
                    >
                        {r.label}
                    </button>
                ))}
            </div>
            <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <Field label="Desde">{(id) => <Input id={id} type="date" value={filters.from} onChange={(e) => e.target.value && set({ from: e.target.value })} />}</Field>
                <Field label="Hasta">{(id) => <Input id={id} type="date" value={filters.to} onChange={(e) => e.target.value && set({ to: e.target.value })} />}</Field>
                <Field label="Banco">
                    {(id) => (
                        <Select id={id} value={filters.bank_id} onChange={(e) => set({ bank_id: e.target.value })}>
                            <option value="">Todos</option>
                            {catalogs?.banks.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                        </Select>
                    )}
                </Field>
                <Field label="Estado">
                    {(id) => (
                        <Select id={id} value={filters.state_id} onChange={(e) => set({ state_id: e.target.value })}>
                            <option value="">Todos</option>
                            {catalogs?.states.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                        </Select>
                    )}
                </Field>
                {showTechnician && (
                    <Field label="Técnico">
                        {(id) => (
                            <Select id={id} value={filters.technician_id} onChange={(e) => set({ technician_id: e.target.value })}>
                                <option value="">Todos</option>
                                {catalogs?.technicians.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                            </Select>
                        )}
                    </Field>
                )}
            </div>
        </Card>
    );
}

function useReport(path, filters) {
    return useQuery({
        queryKey: ['report', path, filters],
        queryFn: () => api(`reports/${path}`, { query: filters }),
        placeholderData: keepPreviousData,
    });
}

function ExportButton({ path, filters }) {
    const [busy, setBusy] = useState(false);
    return (
        <Button
            variant="success"
            size="sm"
            icon={Download}
            loading={busy}
            onClick={async () => {
                setBusy(true);
                try {
                    await download(`reports/${path}`, { ...filters, format: 'xlsx' });
                    toast('Reporte descargado');
                } catch (e) {
                    toast(e.message, 'error');
                } finally {
                    setBusy(false);
                }
            }}
        >
            Excel
        </Button>
    );
}

function Kpi({ icon: Icon, label, value, detail, tone = 'brand', to }) {
    const tones = { brand: 'bg-brand-50 text-brand-600', ok: 'bg-emerald-50 text-emerald-700', warn: 'bg-amber-50 text-amber-700', danger: 'bg-red-50 text-red-600', navy: 'bg-navy-900 text-white' };
    const body = (
        <Card className={cx('group h-full p-4 transition', to && 'hover:border-brand-300 hover:shadow-md')}>
            <div className="flex items-start justify-between gap-2">
                <p className="text-xs font-semibold text-muted">{label}</p>
                <span className={cx('grid size-8 place-items-center rounded-lg', tones[tone])}><Icon className="size-4" /></span>
            </div>
            <p className="mt-2 font-display text-2xl font-extrabold text-ink tabular">{value}</p>
            {detail && <p className="mt-1 text-xs text-muted">{detail}</p>}
            {to && <p className="mt-2 flex items-center text-xs font-semibold text-brand-600 opacity-70 group-hover:opacity-100">Ver detalle <ChevronRight className="size-3.5" /></p>}
        </Card>
    );
    return to ? <Link to={to} className="block h-full">{body}</Link> : body;
}

/** Barra horizontal dentro de una fila de tabla (valor relativo al máximo). */
function InlineBar({ value, max }) {
    return (
        <div className="h-2 w-full min-w-[80px] overflow-hidden rounded-full bg-surface ring-1 ring-line ring-inset">
            <div className="h-full rounded-full bg-brand-500" style={{ width: `${max ? Math.max(3, (value / max) * 100) : 0}%` }} />
        </div>
    );
}

/* ---------------------------------------------------------------- Resumen */

function SummaryTab({ filters }) {
    const { data, isLoading } = useReport('summary', filters);
    const link = (params) => detailUrl(filters, { tipo: 'tickets', ...params }, 'resumen');
    if (isLoading || !data) {
        return <LoadingBlock />;
    }
    const k = data.kpis;
    return (
        <div className="space-y-6">
            <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <Kpi icon={BarChart3} label="Tickets abiertos en el período" value={formatNumber(k.opened)} detail={`${formatNumber(k.closed)} concluidos · ${formatNumber(k.backlog)} pendientes hoy`} to={link({ metrica: 'opened' })} />
                <Kpi icon={Timer} tone="navy" label="Tiempo promedio de resolución" value={k.mttr_ms ? formatDuration(k.mttr_ms) : '—'} detail="De la apertura al cierre" to={link({ metrica: 'mttr' })} />
                <Kpi icon={Clock3} label="Tiempo promedio de llegada" value={k.mtta_ms ? formatDuration(k.mtta_ms) : '—'} detail="De la apertura a la llegada al sitio" to={link({ metrica: 'mtta' })} />
                <Kpi icon={CheckCircle2} tone="ok" label="Resueltos en la 1.ª visita" value={formatPct(k.first_visit_rate)} detail="Sobre los concluidos del período" to={link({ metrica: 'first_visit' })} />
                <Kpi icon={Gauge} tone={k.sla_resolution_rate !== null && k.sla_resolution_rate < 80 ? 'warn' : 'ok'} label="SLA de resolución cumplido" value={formatPct(k.sla_resolution_rate)} detail={`Llegada: ${formatPct(k.sla_response_rate)}`} to={link({ metrica: 'sla' })} />
                <Kpi icon={AlertTriangle} tone="danger" label="Tickets con SLA vencido" value={formatNumber(k.sla_breached)} detail="Llegada o resolución fuera de meta" to={link({ metrica: 'sla_breached' })} />
                <Kpi icon={Repeat2} tone="warn" label="Reincidencia" value={formatPct(k.reincidence_rate)} detail="Cajero con otra falla en los 30 días previos" to={link({ metrica: 'reincidence' })} />
                <Kpi icon={Wrench} label="Pendientes hoy" value={formatNumber(k.backlog)} detail="Sin cerrar, según antigüedad abajo" to={link({ metrica: 'backlog' })} />
            </div>
            <div className="grid gap-6 xl:grid-cols-3">
                <Card className="xl:col-span-2">
                    <CardHeader title="Abiertos y concluidos por semana" />
                    <div className="px-3 pt-3 pb-2">{data.weekly.length ? <WeeklyChart data={data.weekly} /> : <EmptyState title="Sin datos" />}</div>
                </Card>
                <Card>
                    <CardHeader title="Antigüedad de los pendientes" subtitle="Tickets sin cerrar hoy, por tiempo abierto" />
                    <div className="px-3 pt-3 pb-2"><ColumnChart data={data.backlog_aging} labelKey="bucket" valueKey="total" name="Tickets" /></div>
                </Card>
            </div>
            <div className="grid gap-6 lg:grid-cols-2">
                <Card>
                    <CardHeader title="Por estado actual" subtitle="Tickets abiertos en el período" />
                    <div className="px-4 py-3">{data.by_status.length ? <HBarChart data={data.by_status} labelKey="name" valueKey="total" unit="Tickets" /> : <EmptyState title="Sin datos" />}</div>
                </Card>
                <Card>
                    <CardHeader title="Por prioridad" />
                    <div className="px-4 py-3">{data.by_priority.length ? <HBarChart data={data.by_priority} labelKey="name" valueKey="total" unit="Tickets" /> : <EmptyState title="Sin datos" />}</div>
                </Card>
            </div>
        </div>
    );
}

/* ---------------------------------------------------------------- Cajeros */

function AtmsTab({ filters }) {
    const { data, isLoading } = useReport('atms', filters);
    const [q, setQ] = useState('');
    if (isLoading || !data) {
        return <LoadingBlock />;
    }
    const rows = data.data.filter((r) => !q || `${r.atm_number} ${r.agency_name} ${r.state}`.toLowerCase().includes(q.toLowerCase()));
    return (
        <Card>
            <CardHeader
                title="Cajeros con fallas en el período"
                subtitle={`${data.data.length} cajeros · ordenados por cantidad de tickets`}
                actions={<ExportButton path="atms" filters={filters} />}
            />
            <div className="border-b border-line p-4"><Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Filtrar por ATM, agencia o estado" /></div>
            {!rows.length ? (
                <EmptyState icon={Building2} title="Sin cajeros con tickets en este período" />
            ) : (
                <Table>
                    <thead>
                        <tr><Th>ATM</Th><Th>Agencia</Th><Th className="text-right">Tickets</Th><Th className="text-right">Reincid.</Th><Th className="text-right">Abiertos</Th><Th className="text-right">Repuestos</Th><Th>Falla más frecuente</Th><Th className="text-right">Tiempo prom.</Th></tr>
                    </thead>
                    <tbody>
                        {rows.map((r) => (
                            <tr key={r.atm_id} className="hover:bg-brand-50/40">
                                <Td><Link to={`/cajeros/${r.atm_id}`} className="font-semibold text-brand-600 tabular hover:underline">{r.atm_number}</Link></Td>
                                <Td className="max-w-[240px]"><div className="truncate text-ink">{r.agency_name}</div><div className="text-xs text-muted">{r.state} · {r.model || 'Sin modelo'}</div></Td>
                                <Td className="text-right font-bold text-ink tabular">{r.tickets}</Td>
                                <Td className={cx('text-right tabular', r.reincidences > 0 && 'font-semibold text-amber-700')}>{r.reincidences}</Td>
                                <Td className="text-right tabular">{r.open || <span className="text-faint">0</span>}</Td>
                                <Td className="text-right tabular">{r.parts_units}</Td>
                                <Td className="max-w-[180px] truncate">{r.top_fault}</Td>
                                <Td className="text-right tabular">{r.mttr_ms ? formatDuration(r.mttr_ms) : '—'}</Td>
                            </tr>
                        ))}
                    </tbody>
                </Table>
            )}
        </Card>
    );
}

/* ---------------------------------------------------------------- Técnicos */

function TechniciansTab({ filters }) {
    const { data, isLoading } = useReport('technicians', filters);
    const navigate = useNavigate();
    const visitsOf = (id) => detailUrl({ ...filters, technician_id: id }, { tipo: 'visits' }, 'tecnicos');
    if (isLoading || !data) {
        return <LoadingBlock />;
    }
    return (
        <div className="space-y-6">
            <Card>
                <CardHeader title="Desempeño por técnico" subtitle="Toque un técnico para ver el detalle de sus atenciones" actions={<ExportButton path="technicians" filters={filters} />} />
                {!data.data.length ? (
                    <EmptyState icon={Wrench} title="Sin actividad de técnicos en este período" />
                ) : (
                    <Table>
                        <thead>
                            <tr><Th>Técnico</Th><Th className="text-right">Asignados</Th><Th className="text-right">Concluidos</Th><Th className="text-right">Pend. hoy</Th><Th className="text-right">Visitas</Th><Th className="text-right">Repuestos</Th><Th className="text-right">Llegada prom.</Th><Th className="text-right">Resolución prom.</Th><Th className="text-right">1.ª visita</Th><Th className="text-right">SLA</Th></tr>
                        </thead>
                        <tbody>
                            {data.data.map((r) => (
                                <tr key={r.technician_id} onClick={() => navigate(visitsOf(r.technician_id))} className="cursor-pointer hover:bg-brand-50/40" title="Ver sus atenciones">
                                    <Td className="font-semibold whitespace-nowrap"><Link to={visitsOf(r.technician_id)} onClick={(e) => e.stopPropagation()} className="text-brand-600 hover:underline">{r.name}</Link></Td>
                                    <Td className="text-right tabular">{r.assigned}</Td>
                                    <Td className="text-right font-bold tabular"><Link to={detailUrl({ ...filters, technician_id: r.technician_id }, { tipo: 'tickets', metrica: 'closed' }, 'tecnicos')} onClick={(e) => e.stopPropagation()} className="text-ink hover:text-brand-600 hover:underline">{r.closed}</Link></Td>
                                    <Td className={cx('text-right tabular', r.pending_now > 0 && 'font-semibold text-amber-700')}>{r.pending_now}</Td>
                                    <Td className="text-right tabular">{r.visits}</Td>
                                    <Td className="text-right tabular">{r.parts_units}</Td>
                                    <Td className="text-right tabular">{r.mtta_ms ? formatDuration(r.mtta_ms) : '—'}</Td>
                                    <Td className="text-right tabular">{r.mttr_ms ? formatDuration(r.mttr_ms) : '—'}</Td>
                                    <Td className="text-right tabular">{formatPct(r.first_visit_rate)}</Td>
                                    <Td className={cx('text-right font-semibold tabular', r.sla_resolution_rate !== null && r.sla_resolution_rate < 80 ? 'text-amber-700' : 'text-emerald-700')}>{formatPct(r.sla_resolution_rate)}</Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}
            </Card>
            {data.data.length > 0 && (
                <Card>
                    <CardHeader title="Tickets concluidos por técnico" />
                    <div className="px-4 py-3"><HBarChart data={data.data.filter((r) => r.closed > 0)} labelKey="name" valueKey="closed" unit="Concluidos" /></div>
                </Card>
            )}
        </div>
    );
}

/* ---------------------------------------------------------------- Repuestos */

function PartsTab({ filters }) {
    const { data, isLoading } = useReport('parts', filters);
    const navigate = useNavigate();
    const visitsWith = (id) => detailUrl(filters, { tipo: 'visits', repuesto: id }, 'repuestos');
    if (isLoading || !data) {
        return <LoadingBlock />;
    }
    if (!data.total) {
        return <Card><EmptyState icon={Package} title="Sin consumo de repuestos en este período" description="Pruebe con otro rango de fechas." /></Card>;
    }
    return (
        <div className="space-y-6">
            <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <Kpi icon={Package} label="Unidades utilizadas" value={formatNumber(data.total)} to={detailUrl(filters, { tipo: 'visits', with_parts: 1 }, 'repuestos')} />
                <Kpi icon={BarChart3} label="Tipos de repuesto" value={data.by_part.length} />
                <Kpi icon={Wrench} label="Visitas con cambio" value={formatNumber(data.visits_with_parts)} to={detailUrl(filters, { tipo: 'visits', with_parts: 1 }, 'repuestos')} />
                <Kpi icon={Building2} label="Cajeros atendidos" value={data.top_atms.length >= 10 ? '10+' : data.top_atms.length} detail="Con repuestos en el período" />
            </div>
            <div className="grid gap-6 xl:grid-cols-2">
                <Card>
                    <CardHeader title="Consumo por repuesto" subtitle="Toque un repuesto para ver en qué atenciones se usó" actions={<ExportButton path="parts" filters={filters} />} />
                    <div className="max-h-[520px] overflow-y-auto">
                        <Table compact>
                            <thead><tr><Th>Repuesto</Th><Th className="w-[28%]">Consumo</Th><Th className="text-right">Unid.</Th><Th className="text-right">Visitas</Th><Th className="text-right">Cajeros</Th></tr></thead>
                            <tbody>
                                {data.by_part.map((p) => (
                                    <tr key={p.name} onClick={() => navigate(visitsWith(p.part_id))} className="cursor-pointer hover:bg-brand-50/40" title="Ver atenciones">
                                        <Td className="font-semibold whitespace-nowrap"><Link to={visitsWith(p.part_id)} onClick={(e) => e.stopPropagation()} className="text-brand-600 hover:underline">{p.name}</Link></Td>
                                        <Td><InlineBar value={p.quantity} max={data.by_part[0].quantity} /></Td>
                                        <Td className="text-right font-bold text-ink tabular">{p.quantity}</Td>
                                        <Td className="text-right tabular">{p.visits}</Td>
                                        <Td className="text-right tabular">{p.atms}</Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </div>
                </Card>
                <Card>
                    <CardHeader title="Tendencia de los 5 más usados" subtitle="Unidades por mes, últimos 6 meses" />
                    <div className="px-3 pt-3"><TrendChart months={data.trend.months} series={data.trend.series} /></div>
                    <Table>
                        <thead>
                            <tr><Th>Repuesto</Th>{data.trend.months.map((m) => <Th key={m} className="text-right">{new Date(`${m}-01T00:00:00`).toLocaleDateString('es-VE', { month: 'short' })}</Th>)}</tr>
                        </thead>
                        <tbody>
                            {data.trend.series.map((s, i) => (
                                <tr key={s.name}>
                                    <Td className="whitespace-nowrap"><span className="mr-2 inline-block h-0.5 w-3 rounded align-middle" style={{ background: CATEGORICAL[i] }} />{s.name}</Td>
                                    {s.values.map((v, j) => <Td key={j} className="text-right tabular">{v}</Td>)}
                                </tr>
                            ))}
                            <tr><Td className="font-bold text-ink">Total</Td>{data.trend.totals.map((v, j) => <Td key={j} className="text-right font-bold text-ink tabular">{v}</Td>)}</tr>
                        </tbody>
                    </Table>
                </Card>
            </div>
            <div className="grid gap-6 xl:grid-cols-3">
                <Card>
                    <CardHeader title="Por estado" />
                    <div className="px-4 py-3"><HBarChart data={data.by_state} labelKey="name" valueKey="quantity" unit="Unidades" /></div>
                </Card>
                <Card>
                    <CardHeader title="Cajeros que más consumen" />
                    <ul className="divide-y divide-line">
                        {data.top_atms.map((a) => (
                            <li key={a.atm_id} className="flex items-center justify-between gap-3 px-5 py-2.5 text-sm">
                                <span className="min-w-0"><Link to={`/cajeros/${a.atm_id}`} className="font-semibold text-brand-600 hover:underline">{a.name}</Link><span className="block truncate text-xs text-muted">{a.agency_name}</span></span>
                                <span className="font-bold text-ink tabular">{a.quantity}</span>
                            </li>
                        ))}
                    </ul>
                </Card>
                <Card>
                    <CardHeader title="Por modelo de ATM" />
                    <Table>
                        <thead><tr><Th>Modelo</Th><Th>Repuestos</Th><Th className="text-right">Unid.</Th></tr></thead>
                        <tbody>
                            {data.by_model.map((m) => (
                                <tr key={m.name}>
                                    <Td className="font-semibold whitespace-nowrap text-ink">{m.name}</Td>
                                    <Td className="text-xs">{m.parts.slice(0, 4).map((p) => `${p.name} (${p.quantity})`).join(', ')}{m.parts.length > 4 ? '…' : ''}</Td>
                                    <Td className="text-right font-bold text-ink tabular">{m.quantity}</Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </Card>
            </div>
        </div>
    );
}

/* ---------------------------------------------------------------- Fallas */

function FaultsTab({ filters }) {
    const { data, isLoading } = useReport('faults', filters);
    const navigate = useNavigate();
    const ticketsOf = (id) => detailUrl(filters, { tipo: 'tickets', metrica: 'fault', falla: id }, 'fallas');
    if (isLoading || !data) {
        return <LoadingBlock />;
    }
    return (
        <div className="grid gap-6 xl:grid-cols-5">
            <Card className="xl:col-span-3">
                <CardHeader title="Tickets por tipo de falla" subtitle="Toque una falla para ver sus tickets" actions={<ExportButton path="faults" filters={filters} />} />
                {!data.data.length ? (
                    <EmptyState title="Sin tickets en este período" />
                ) : (
                    <Table>
                        <thead><tr><Th>Tipo de falla</Th><Th className="text-right">Tickets</Th><Th className="text-right">Concluidos</Th><Th className="text-right">Pendientes</Th><Th className="text-right">Cajeros</Th><Th className="text-right">Tiempo prom.</Th></tr></thead>
                        <tbody>
                            {data.data.map((r) => (
                                <tr key={r.name} onClick={() => r.fault_type_id && navigate(ticketsOf(r.fault_type_id))} className={cx(r.fault_type_id && 'cursor-pointer hover:bg-brand-50/40')} title="Ver sus tickets">
                                    <Td className="font-semibold">{r.fault_type_id ? <Link to={ticketsOf(r.fault_type_id)} onClick={(e) => e.stopPropagation()} className="text-brand-600 hover:underline">{r.name}</Link> : r.name}</Td>
                                    <Td className="text-right font-bold text-ink tabular">{r.tickets}</Td>
                                    <Td className="text-right tabular">{r.closed} <span className="text-xs text-muted">({formatPct(r.close_rate)})</span></Td>
                                    <Td className={cx('text-right tabular', r.pending > 0 && 'font-semibold text-amber-700')}>{r.pending}</Td>
                                    <Td className="text-right tabular">{r.atms}</Td>
                                    <Td className="text-right tabular">{r.mttr_ms ? formatDuration(r.mttr_ms) : '—'}</Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}
            </Card>
            <Card className="xl:col-span-2">
                <CardHeader title="Frecuencia" />
                <div className="px-4 py-3">{data.data.length ? <HBarChart data={data.data} labelKey="name" valueKey="tickets" unit="Tickets" /> : null}</div>
            </Card>
        </div>
    );
}

/* ---------------------------------------------------------------- Informe visual */

const longDate = (iso) => new Date(`${iso}T00:00:00`).toLocaleDateString('es-VE', { day: 'numeric', month: 'long', year: 'numeric' });
const monthName = (iso) => new Date(`${iso}T00:00:00`).toLocaleDateString('es-VE', { month: 'long', year: 'numeric' });
const count = (n, one, many) => `${formatNumber(n)} ${n === 1 ? one : many}`;
const join = (items) => (items.length <= 1 ? items.join('') : `${items.slice(0, -1).join(', ')} y ${items[items.length - 1]}`);
const pct = (n) => `${String(n).replace('.', ',')} %`;

/** El período en palabras: «septiembre de 2026» si es un mes completo, o «del … al …». */
function periodLabel({ from, to }) {
    const start = new Date(`${from}T00:00:00`);
    const end = new Date(`${to}T00:00:00`);
    const lastDay = new Date(start.getFullYear(), start.getMonth() + 1, 0).getDate();
    const wholeMonth = start.getDate() === 1 && end.getFullYear() === start.getFullYear() && end.getMonth() === start.getMonth() && end.getDate() === lastDay;
    return wholeMonth ? monthName(from) : `del ${longDate(from)} al ${longDate(to)}`;
}

function VisualSection({ number, title, sentence, children }) {
    return (
        <section className="visual-page overflow-hidden rounded-2xl border border-line bg-white">
            <div className="flex items-baseline gap-3 border-b border-line bg-surface/60 px-5 py-4">
                <span className="font-display text-2xl font-extrabold text-brand-600 tabular">{number}</span>
                <h3 className="text-lg font-bold">{title}</h3>
            </div>
            <div className="p-5">
                {sentence && <p className="mb-5 max-w-4xl text-[15px] leading-relaxed text-ink">{sentence}</p>}
                {children}
            </div>
        </section>
    );
}

function ValueTable({ head, rows }) {
    return (
        <Table compact>
            <thead><tr>{head.map((h, i) => <Th key={h} className={cx(i > 0 && 'text-right')}>{h}</Th>)}</tr></thead>
            <tbody>
                {rows.map((r) => (
                    <tr key={r[0]}>{r.map((c, i) => <Td key={i} className={cx(i > 0 ? 'text-right whitespace-nowrap tabular' : 'font-semibold text-ink')}>{c}</Td>)}</tr>
                ))}
            </tbody>
        </Table>
    );
}

/** El resumen mensual que se presenta al banco: zona, estados, motivos y repuestos, listo para imprimir. */
function VisualTab({ filters }) {
    const { data, isLoading } = useReport('visual', filters);
    if (isLoading || !data) {
        return <LoadingBlock />;
    }
    const period = periodLabel(filters);
    const zones = data.zones.by_state;
    const parts = data.parts.by_part;
    const maxParts = parts.length ? parts[0].quantity : 0;
    const topParts = parts.filter((p) => p.quantity === maxParts).map((p) => p.name);

    const zoneText = data.zones.atms
        ? `${count(data.zones.atms, 'cajero activo', 'cajeros activos')} en ${count(zones.length, 'estado', 'estados')}; la mayor concentración está en ${zones[0].name} (${formatNumber(zones[0].atms)}). En el período se atendieron ${count(data.zones.attended, 'cajero', 'cajeros')}.`
        : 'No hay cajeros activos con estos filtros.';
    const statusText = data.reports
        ? `Se atendieron un total de ${count(data.reports, 'reporte', 'reportes')}, quedando ${join(data.statuses.map((s) => `${s.name.toLowerCase()} ${formatNumber(s.total)}`))}.`
        : null;
    const faultText = data.reports
        ? `Se registraron para ${period}: ${join(data.faults.map((f) => `${pct(f.pct)} ${f.name.toLowerCase()}`))}.`
        : null;
    const partText = data.parts.total
        ? `Se utilizaron ${count(data.parts.total, 'repuesto', 'repuestos')}, ${topParts.length > 1 ? `siendo ${join(topParts)} los más utilizados (${formatNumber(maxParts)} cada uno)` : `siendo ${topParts[0]} el más utilizado (${formatNumber(maxParts)})`}.`
        : 'No se registraron repuestos en el período.';

    return (
        <div className="visual-report space-y-5">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p className="text-xs font-bold tracking-[0.16em] text-brand-600 uppercase">Informe visual</p>
                    <h2 className="text-2xl font-extrabold">Resumen de reportes de atención a cajeros</h2>
                    <p className="mt-1 text-sm text-muted capitalize-first">{data.bank ?? 'Todos los bancos'} · {period}</p>
                </div>
                <Button className="no-print" icon={Printer} onClick={() => window.print()}>Imprimir o guardar en PDF</Button>
            </div>
            {!data.reports && <Alert tone="info">No hay reportes atendidos en este período.</Alert>}

            <VisualSection number="01" title="Zona de atención" sentence={zoneText}>
                {zones.length > 0 && (
                    <div className="grid gap-6 lg:grid-cols-5">
                        <div className="lg:col-span-3"><HBarChart data={zones} labelKey="name" valueKey="atms" unit="Cajeros" /></div>
                        <div className="lg:col-span-2">
                            <ValueTable head={['Estado', 'Cajeros', 'Atendidos']} rows={[...zones.map((z) => [z.name, formatNumber(z.atms), formatNumber(z.attended)]), ['Total', formatNumber(data.zones.atms), formatNumber(data.zones.attended)]]} />
                        </div>
                    </div>
                )}
            </VisualSection>

            <VisualSection number="02" title="Cantidad y estados de los reportes" sentence={statusText}>
                {data.reports > 0 && (
                    <div className="grid gap-6 lg:grid-cols-5">
                        <div className="lg:col-span-3"><HBarChart data={data.statuses} labelKey="name" valueKey="total" unit="Reportes" /></div>
                        <div className="lg:col-span-2">
                            <ValueTable head={['Estatus final', 'Reportes', '%']} rows={[...data.statuses.map((s) => [s.name, formatNumber(s.total), pct(s.pct)]), ['Total', formatNumber(data.reports), '100 %']]} />
                        </div>
                    </div>
                )}
            </VisualSection>

            <VisualSection number="03" title="Motivos de los reportes" sentence={faultText}>
                {data.reports > 0 && (
                    <div className="grid items-center gap-6 lg:grid-cols-5">
                        <div className="lg:col-span-2"><DonutChart data={data.faults} /></div>
                        <div className="lg:col-span-3">
                            <Table compact>
                                <thead><tr><Th>Motivo</Th><Th className="text-right">Reportes</Th><Th className="text-right">%</Th></tr></thead>
                                <tbody>
                                    {data.faults.map((f, i) => (
                                        <tr key={f.name}>
                                            <Td className="font-semibold text-ink">
                                                <span className="mr-2 inline-block size-2.5 rounded-sm align-middle" style={{ background: donutColor(i, data.faults.length) }} />
                                                {f.name}
                                            </Td>
                                            <Td className="text-right whitespace-nowrap tabular">{formatNumber(f.total)}</Td>
                                            <Td className="text-right whitespace-nowrap tabular">{pct(f.pct)}</Td>
                                        </tr>
                                    ))}
                                </tbody>
                            </Table>
                        </div>
                    </div>
                )}
            </VisualSection>

            <VisualSection number="04" title="Repuestos utilizados" sentence={partText}>
                {parts.length > 0 && (
                    <div className="grid gap-6 lg:grid-cols-5">
                        <div className="lg:col-span-3"><HBarChart data={parts} labelKey="name" valueKey="quantity" unit="Unidades" /></div>
                        <div className="lg:col-span-2">
                            <ValueTable head={['Repuesto', 'Cantidad']} rows={[...parts.map((p) => [p.name, formatNumber(p.quantity)]), ['Total', formatNumber(data.parts.total)]]} />
                        </div>
                    </div>
                )}
            </VisualSection>
        </div>
    );
}

/* ---------------------------------------------------------------- Consolidado */

function ConsolidadoTab({ filters }) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    return (
        <Card>
            <CardHeader title="Exportar consolidado a Excel" subtitle="Mismas columnas del “Libro Consolidado Relación de Atenciones”, una fila por visita, más el N° de ticket. Usa las fechas y el banco de los filtros." actions={<FileSpreadsheet className="size-5 text-ok" />} />
            <div className="flex flex-wrap items-center gap-3 p-5">
                <p className="text-sm text-body">Del <b>{formatDate(filters.from)}</b> al <b>{formatDate(filters.to)}</b></p>
                <Button
                    icon={Download}
                    variant="success"
                    loading={busy}
                    className="ml-auto"
                    onClick={async () => {
                        setBusy(true);
                        setError(null);
                        try {
                            await download('reports/consolidado', { from: filters.from, to: filters.to, bank_id: filters.bank_id });
                            toast('Consolidado descargado');
                        } catch (e) {
                            setError(e.message);
                        } finally {
                            setBusy(false);
                        }
                    }}
                >
                    Descargar .xlsx
                </Button>
                {error && <Alert className="w-full">{error}</Alert>}
            </div>
        </Card>
    );
}

export default function Reports() {
    const { filters, set, tab, setTab } = useReportFilters();
    const content = {
        resumen: <SummaryTab filters={filters} />,
        visual: <VisualTab filters={filters} />,
        cajeros: <AtmsTab filters={filters} />,
        tecnicos: <TechniciansTab filters={filters} />,
        repuestos: <PartsTab filters={filters} />,
        fallas: <FaultsTab filters={filters} />,
        consolidado: <ConsolidadoTab filters={filters} />,
    };

    return (
        <>
            <div className="no-print">
            <PageHeader title="Reportes y métricas" subtitle="Indicadores de atención, SLA, cajeros, técnicos y repuestos. Cada reporte se descarga en Excel." />
            <div className="-mx-4 mb-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
                <div className="flex w-max gap-1 rounded-xl border border-line bg-white p-1">
                    {TABS.map((t) => (
                        <button
                            key={t.key}
                            type="button"
                            onClick={() => setTab(t.key)}
                            className={cx(
                                'inline-flex items-center gap-1.5 rounded-lg px-3.5 py-2 text-sm font-semibold whitespace-nowrap transition-colors',
                                tab === t.key ? 'bg-navy-900 text-white' : 'text-muted hover:bg-surface hover:text-ink',
                            )}
                        >
                            <t.icon className="size-4" /> {t.label}
                        </button>
                    ))}
                </div>
            </div>
            <Filters filters={filters} set={set} showTechnician={tab !== 'consolidado'} />
            </div>
            {content[tab] ?? content.resumen}
        </>
    );
}
