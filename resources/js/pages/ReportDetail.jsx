import { useMemo, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { ArrowLeft, Download, FileImage, Search } from 'lucide-react';
import { api, download } from '../lib/api';
import { formatDate, formatDateTime, formatDuration, formatNumber } from '../lib/format';
import { Button, Card, EmptyState, Input, LoadingBlock, PageHeader, Pagination, StatusBadge, Table, Td, Th, cx, toast } from '../components/ui';
import { PriorityBadge, SLA_STATUS } from '../components/Sla';

const PAGE = 50;

function SlaDot({ status }) {
    const s = SLA_STATUS[status] || SLA_STATUS.none;
    return status === 'none' ? <span className="text-faint">—</span> : (
        <span className={cx('inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap ring-1 ring-inset', s.className)}>
            <s.icon className="size-3" /> {s.label}
        </span>
    );
}

/** Enlace al detalle de una cifra del reporte, conservando los filtros actuales. */
export function detailUrl(filters, params, backTab) {
    const q = new URLSearchParams();
    const map = { from: 'desde', to: 'hasta', bank_id: 'banco', state_id: 'estado', technician_id: 'tecnico' };
    Object.entries(map).forEach(([k, v]) => filters[k] && q.set(v, filters[k]));
    Object.entries(params).forEach(([k, v]) => (v ? q.set(k, v) : q.delete(k)));
    if (backTab) {
        q.set('volver', backTab);
    }
    return `/reportes/detalle?${q.toString()}`;
}

/** Detalle de una cifra de los reportes: tickets o atenciones, con búsqueda y descarga a Excel. */
export default function ReportDetail() {
    const [params] = useSearchParams();
    const navigate = useNavigate();
    const [q, setQ] = useState('');
    const [page, setPage] = useState(1);
    const [busy, setBusy] = useState(false);

    const query = {
        type: params.get('tipo') || 'tickets',
        metric: params.get('metrica') || undefined,
        fault_type_id: params.get('falla') || undefined,
        part_id: params.get('repuesto') || undefined,
        from: params.get('desde') || undefined,
        to: params.get('hasta') || undefined,
        bank_id: params.get('banco') || undefined,
        state_id: params.get('estado') || undefined,
        technician_id: params.get('tecnico') || undefined,
    };
    const { data, isLoading, error } = useQuery({
        queryKey: ['report-detail', query],
        queryFn: () => api('reports/detail', { query }),
        placeholderData: keepPreviousData,
    });

    const back = new URLSearchParams(params);
    ['tipo', 'metrica', 'falla', 'repuesto', 'volver'].forEach((k) => back.delete(k));
    if (params.get('volver') && params.get('volver') !== 'resumen') {
        back.set('tab', params.get('volver'));
    }
    // En el detalle de atenciones de un técnico, "tecnico" es el sujeto; al volver no se filtra por él.
    if (query.type === 'visits' && params.get('volver') === 'tecnicos') {
        back.delete('tecnico');
    }

    const isVisits = data?.type === 'visits';
    const rows = useMemo(() => {
        const term = q.trim().toLowerCase();
        const list = data?.data ?? [];
        return term ? list.filter((r) => `${r.number} ${r.atm_number} ${r.agency_name} ${r.technician ?? ''} ${r.fault_type ?? ''} ${r.parts ?? ''} ${r.constancia_number ?? ''}`.toLowerCase().includes(term)) : list;
    }, [data, q]);
    const shown = rows.slice((page - 1) * PAGE, page * PAGE);
    const meta = { current_page: page, last_page: Math.max(1, Math.ceil(rows.length / PAGE)), from: rows.length ? (page - 1) * PAGE + 1 : 0, to: Math.min(page * PAGE, rows.length), total: rows.length };

    const totals = isVisits
        ? { label: 'atenciones', extra: `${formatNumber(rows.reduce((n, r) => n + r.parts_units, 0))} repuestos (unid.)` }
        : {
              label: 'tickets',
              extra: (() => {
                  const timed = rows.filter((r) => r.duration_ms !== null);
                  return timed.length ? `Resolución promedio ${formatDuration(timed.reduce((n, r) => n + r.duration_ms, 0) / timed.length)}` : '';
              })(),
          };

    async function exportExcel() {
        setBusy(true);
        try {
            await download('reports/detail', { ...query, format: 'xlsx' });
            toast('Detalle descargado');
        } catch (e) {
            toast(e.message, 'error');
        } finally {
            setBusy(false);
        }
    }

    return (
        <>
            <Link to={`/reportes?${back.toString()}`} className="mb-3 inline-flex items-center gap-1.5 text-sm font-semibold text-muted hover:text-ink">
                <ArrowLeft className="size-4" /> Reportes
            </Link>
            <PageHeader
                title={data?.title ?? 'Detalle'}
                subtitle={data?.filters}
                actions={<Button variant="success" icon={Download} loading={busy} onClick={exportExcel} disabled={!data?.data.length}>Descargar Excel</Button>}
            />

            {isLoading ? (
                <LoadingBlock />
            ) : error ? (
                <EmptyState title="No se pudo cargar el detalle" description={error.message} />
            ) : (
                <Card>
                    <div className="flex flex-wrap items-center gap-3 border-b border-line p-4">
                        <div className="relative min-w-[240px] flex-1">
                            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-faint" />
                            <Input value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} placeholder="Filtrar por ticket, ATM, agencia, técnico, falla o repuesto" className="pl-9" />
                        </div>
                        <p className="text-sm text-body">
                            <b className="text-ink tabular">{formatNumber(rows.length)}</b> {totals.label}
                            {totals.extra && <span className="text-muted"> · {totals.extra}</span>}
                        </p>
                    </div>

                    {!rows.length ? (
                        <EmptyState title="Sin resultados" description="No hay registros para esta cifra con los filtros actuales." />
                    ) : isVisits ? (
                        <Table>
                            <thead>
                                <tr><Th>Fecha</Th><Th>Ticket</Th><Th>Cajero</Th><Th>Técnico</Th><Th>Falla</Th><Th>Estatus final</Th><Th>Constancia</Th><Th>Repuestos</Th><Th>Trabajo realizado</Th></tr>
                            </thead>
                            <tbody>
                                {shown.map((r) => (
                                    <tr key={r.id} onClick={() => navigate(`/tickets/${r.ticket_id}`)} className="cursor-pointer align-top hover:bg-brand-50/40">
                                        <Td className="whitespace-nowrap">{r.finished_at ? formatDateTime(r.finished_at) : formatDate(r.visit_date)}</Td>
                                        <Td className="font-semibold whitespace-nowrap text-brand-600 tabular">{r.number}</Td>
                                        <Td className="max-w-[200px]"><div className="font-medium text-ink tabular">{r.atm_number}</div><div className="truncate text-xs text-muted">{r.agency_name}</div></Td>
                                        <Td className="whitespace-nowrap">{r.technician ?? '—'}</Td>
                                        <Td className="max-w-[160px] truncate">{r.fault_type ?? '—'}</Td>
                                        <Td className="max-w-[170px] text-xs">{r.condition ?? '—'}</Td>
                                        <Td className="whitespace-nowrap tabular">{r.constancia_number ?? '—'}{r.has_photo && <FileImage className="ml-1 inline size-3.5 text-brand-500" aria-label="Con foto" />}</Td>
                                        <Td className="max-w-[200px] text-xs">{r.parts || <span className="text-faint">—</span>}</Td>
                                        <Td className="max-w-[280px] text-xs text-body"><span className="line-clamp-2">{r.work_done}</span></Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    ) : (
                        <Table>
                            <thead>
                                <tr><Th>Ticket</Th><Th>Cajero</Th><Th>Falla</Th><Th>Técnico</Th><Th>Estado</Th><Th className="text-right">Visitas</Th><Th className="text-right">Llegada</Th><Th className="text-right">Resolución</Th><Th>SLA llegada</Th><Th>SLA resolución</Th><Th>Repuestos</Th></tr>
                            </thead>
                            <tbody>
                                {shown.map((r) => (
                                    <tr key={r.id} onClick={() => navigate(`/tickets/${r.id}`)} className="cursor-pointer hover:bg-brand-50/40">
                                        <Td className="whitespace-nowrap">
                                            <div className="flex items-center gap-1.5"><span className="font-semibold text-brand-600 tabular">{r.number}</span>{r.priority && <PriorityBadge priority={r.priority} size="sm" />}</div>
                                            <div className="text-xs text-muted">{formatDateTime(r.opened_at)}</div>
                                        </Td>
                                        <Td className="max-w-[200px]"><div className="font-medium text-ink tabular">{r.atm_number}</div><div className="truncate text-xs text-muted">{r.agency_name}</div></Td>
                                        <Td className="max-w-[150px] truncate">{r.fault_type ?? '—'}</Td>
                                        <Td className="whitespace-nowrap">{r.technician ?? <span className="text-faint">Sin asignar</span>}</Td>
                                        <Td><StatusBadge status={r.status} size="sm" /></Td>
                                        <Td className="text-right tabular">{r.visits}</Td>
                                        <Td className="text-right whitespace-nowrap tabular">{r.arrival_ms !== null ? formatDuration(r.arrival_ms) : '—'}</Td>
                                        <Td className="text-right whitespace-nowrap tabular">{r.duration_ms !== null ? formatDuration(r.duration_ms) : '—'}</Td>
                                        <Td><SlaDot status={r.sla_response} /></Td>
                                        <Td><SlaDot status={r.sla_resolution} /></Td>
                                        <Td className="max-w-[180px] text-xs">{r.parts || <span className="text-faint">—</span>}</Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    )}
                    <Pagination meta={meta} onPage={setPage} />
                </Card>
            )}
        </>
    );
}
