import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Plus, RefreshCw, UserX } from 'lucide-react';
import { api } from '../lib/api';
import { useCatalogs } from '../lib/queries';
import { Button, Elapsed, LoadingBlock, PageHeader, Select, STATUS, Toggle, cx } from '../components/ui';
import { PriorityBadge, SlaBadge, worstSla } from '../components/Sla';

const COLUMNS = ['abierto', 'asignado', 'en_transito', 'en_sitio', 'pendiente_repuesto', 'pendiente_validacion', 'por_confirmar', 'concluido'];
const COLUMN_ACCENT = {
    abierto: 'bg-slate-400', asignado: 'bg-brand-500', en_transito: 'bg-violet-500', en_sitio: 'bg-sky-500',
    pendiente_repuesto: 'bg-amber-500', pendiente_validacion: 'bg-orange-500', por_confirmar: 'bg-teal-500', concluido: 'bg-emerald-500',
};
const WEIGHT = { critica: 3, media: 2, baja: 1 };

function TicketCard({ t }) {
    const breached = worstSla(t.sla) === 'breached';
    return (
        <Link
            to={`/tickets/${t.id}`}
            className={cx(
                'block rounded-xl border bg-white p-3 shadow-[0_1px_2px_rgba(0,25,76,0.05)] transition hover:border-brand-300 hover:shadow-md',
                breached ? 'border-red-300' : 'border-line',
            )}
        >
            <div className="flex items-center justify-between gap-2">
                <span className="text-sm font-bold text-brand-600 tabular">{t.number}</span>
                <PriorityBadge priority={t.priority} size="sm" />
            </div>
            <p className="mt-1.5 truncate text-sm font-semibold text-ink" title={t.atm.agency_name}>{t.atm.agency_name}</p>
            <p className="truncate text-xs text-muted">ATM {t.atm.atm_number}{t.atm.state && ` · ${t.atm.state}`}</p>
            {t.fault_type && <p className="mt-1 truncate text-xs text-body">{t.fault_type}</p>}
            <div className="mt-2.5 flex items-center justify-between gap-2 border-t border-line pt-2 text-xs">
                <span className={cx('flex min-w-0 items-center gap-1 truncate', t.technician ? 'text-body' : 'font-semibold text-amber-700')}>
                    {!t.technician && <UserX className="size-3.5 shrink-0" />}
                    {t.technician?.name ?? 'Sin asignar'}
                </span>
                <Elapsed ticket={t} className="shrink-0 text-muted" />
            </div>
            {t.status !== 'concluido' && <div className="mt-2"><SlaBadge sla={t.sla} size="sm" /></div>}
        </Link>
    );
}

/** Tablero Kanban: todos los tickets en curso por estado, más los concluidos de hoy. */
export default function Board() {
    const { data: catalogs } = useCatalogs();
    const [technician, setTechnician] = useState('');
    const [priority, setPriority] = useState('');
    const [onlyBreached, setOnlyBreached] = useState(false);
    const today = new Date();
    const todayIso = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;

    const query = { technician_id: technician, priority, sla: onlyBreached ? 'breached' : '', per_page: 200 };
    const open = useQuery({ queryKey: ['board', 'open', query], queryFn: () => api('tickets', { query: { ...query, open: 1 } }), refetchInterval: 30000 });
    const done = useQuery({
        queryKey: ['board', 'done', query, todayIso],
        queryFn: () => api('tickets', { query: { ...query, status: 'concluido', closed_from: todayIso } }),
        refetchInterval: 30000,
    });

    const tickets = [...(open.data?.data ?? []), ...(done.data?.data ?? []).filter((t) => t.closed_at?.slice(0, 10) === todayIso)];
    const byColumn = Object.fromEntries(COLUMNS.map((c) => [
        c,
        tickets.filter((t) => t.status === c).sort((a, b) => (WEIGHT[b.priority] - WEIGHT[a.priority]) || (new Date(a.opened_at) - new Date(b.opened_at))),
    ]));

    return (
        <>
            <PageHeader
                title="Tablero"
                subtitle="Todos los tickets en curso por estado. Los más urgentes y antiguos arriba; borde rojo = SLA vencido."
                actions={
                    <>
                        <Button variant="secondary" icon={RefreshCw} loading={open.isFetching} onClick={() => { open.refetch(); done.refetch(); }} className="px-3" aria-label="Actualizar" />
                        <Button as={Link} to="/tickets/nuevo" icon={Plus}>Nuevo ticket</Button>
                    </>
                }
            />
            <div className="mb-4 flex flex-wrap items-center gap-3">
                <div className="w-full sm:w-56">
                    <Select value={technician} onChange={(e) => setTechnician(e.target.value)} aria-label="Técnico">
                        <option value="">Todos los técnicos</option>
                        {catalogs?.technicians.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                    </Select>
                </div>
                <div className="w-full sm:w-44">
                    <Select value={priority} onChange={(e) => setPriority(e.target.value)} aria-label="Prioridad">
                        <option value="">Toda prioridad</option>
                        {catalogs?.priorities?.map((p) => <option key={p.value} value={p.value}>{p.label}</option>)}
                    </Select>
                </div>
                <Toggle checked={onlyBreached} onChange={setOnlyBreached} label="Solo SLA vencido" />
            </div>

            {open.isLoading ? (
                <LoadingBlock />
            ) : (
                <div className="-mx-4 overflow-x-auto px-4 pb-4 sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8">
                    <div className="grid w-max auto-cols-[260px] grid-flow-col gap-3">
                        {COLUMNS.map((c) => (
                            <section key={c} className="flex max-h-[calc(100dvh-240px)] min-h-[200px] flex-col rounded-2xl bg-slate-100/70 ring-1 ring-line">
                                <header className="flex items-center justify-between px-3 pt-3 pb-2">
                                    <span className="flex items-center gap-2 text-sm font-bold text-ink">
                                        <span className={cx('size-2.5 rounded-full', COLUMN_ACCENT[c])} />
                                        {c === 'concluido' ? 'Concluidos hoy' : STATUS[c].label}
                                    </span>
                                    <span className="rounded-full bg-white px-2 py-0.5 text-xs font-bold text-body ring-1 ring-line tabular">{byColumn[c].length}</span>
                                </header>
                                <div className="flex-1 space-y-2 overflow-y-auto px-2 pb-2">
                                    {byColumn[c].map((t) => <TicketCard key={t.id} t={t} />)}
                                    {!byColumn[c].length && <p className="px-2 py-6 text-center text-xs text-faint">Sin tickets</p>}
                                </div>
                            </section>
                        ))}
                    </div>
                </div>
            )}
        </>
    );
}
