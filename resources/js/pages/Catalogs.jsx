import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Check, ChevronRight, Pencil, Plus, X } from 'lucide-react';
import { api } from '../lib/api';
import { useCatalogs } from '../lib/queries';
import { Badge, Button, Card, Input, LoadingBlock, PageHeader, Select, StatusBadge, Toggle, cx, toast } from '../components/ui';
import { LevelBadge } from '../components/Sla';
import { formatDate } from '../lib/format';

const TABS = [
    { type: 'fault-types', key: 'fault_types', label: 'Tipos de falla', hint: 'Motivos de visita (Dispensación, Impresora, Comunicación…)' },
    { type: 'parts', key: 'parts', label: 'Repuestos', hint: 'Se guardan en MAYÚSCULAS para evitar duplicados' },
    { type: 'atm-conditions', key: 'atm_conditions', label: 'Estatus final', hint: 'Cómo queda el cajero y a qué estado lleva el ticket' },
    { type: 'banks', key: 'banks', label: 'Bancos', hint: 'Clientes bancarios' },
    { type: 'brands', key: 'brands', label: 'Marcas y modelos', hint: 'Marcas de cajeros y sus modelos; se eligen en la ficha de cada cajero. Se guardan en MAYÚSCULAS.' },
    { type: 'states', key: 'states', label: 'Estados', hint: 'Estados y regiones de cobertura' },
    { type: 'sla', key: 'sla', label: 'SLA', hint: 'Semáforo por zona, horario bancario y feriados' },
];

const DAYS = [[1, 'Lun'], [2, 'Mar'], [3, 'Mié'], [4, 'Jue'], [5, 'Vie'], [6, 'Sáb'], [7, 'Dom']];
const toText = (n) => (n == null ? '' : String(n).replace('.', ','));
const toNumber = (text) => {
    const n = parseFloat(String(text).replace(',', '.'));
    return Number.isFinite(n) ? n : null;
};

/** Metas del semáforo por zona, horario bancario y feriados. */
function SlaSettings() {
    const queryClient = useQueryClient();
    const { data, isLoading } = useQuery({ queryKey: ['sla-policies'], queryFn: () => api('sla-policies') });
    const [draft, setDraft] = useState(null);
    const [holiday, setHoliday] = useState({ date: '', name: '' });
    const server = data?.data;
    const current = draft ?? (server && {
        policies: server.policies.map((p) => ({ ...p, green: toText(p.green), orange: toText(p.orange) })),
        schedule: { start: server.schedule.start, end: server.schedule.end, days: server.schedule.days },
    });
    const applied = (r, message) => {
        queryClient.setQueryData(['sla-policies'], r);
        queryClient.invalidateQueries({ queryKey: ['tickets'] });
        queryClient.invalidateQueries({ queryKey: ['board'] });
        queryClient.invalidateQueries({ queryKey: ['dashboard'] });
        toast(message);
    };
    const save = useMutation({
        mutationFn: () => api('sla-policies', {
            method: 'PUT',
            body: {
                policies: current.policies.map((p) => ({ zone: p.zone, unit: p.unit, green: toNumber(p.green), orange: toNumber(p.orange) })),
                schedule: current.schedule,
            },
        }),
        onSuccess: (r) => {
            setDraft(null);
            applied(r, 'Metas y horario guardados; los tickets en curso ya los usan');
        },
        onError: (e) => toast(Object.values(e.errors ?? {})[0]?.[0] ?? e.message, 'error'),
    });
    const addHoliday = useMutation({
        mutationFn: () => api('holidays', { method: 'POST', body: holiday }),
        onSuccess: (r) => {
            setHoliday({ date: '', name: '' });
            applied(r, 'Feriado agregado');
        },
        onError: (e) => toast(Object.values(e.errors ?? {})[0]?.[0] ?? e.message, 'error'),
    });
    const removeHoliday = useMutation({
        mutationFn: (id) => api(`holidays/${id}`, { method: 'DELETE' }),
        onSuccess: (r) => applied(r, 'Feriado eliminado'),
        onError: (e) => toast(e.message, 'error'),
    });
    if (isLoading || !current) {
        return <LoadingBlock />;
    }
    const setPolicy = (i, key) => (e) => setDraft({ ...current, policies: current.policies.map((r, j) => (j === i ? { ...r, [key]: e.target.value } : r)) });
    const setSchedule = (key, value) => setDraft({ ...current, schedule: { ...current.schedule, [key]: value } });
    const toggleDay = (d) => setSchedule('days', current.schedule.days.includes(d) ? current.schedule.days.filter((x) => x !== d) : [...current.schedule.days, d].sort());
    const unitLabel = (unit, n) => (unit === 'days' ? (toNumber(n) === 1 ? 'día hábil' : 'días hábiles') : (toNumber(n) === 1 ? 'hora hábil' : 'horas hábiles'));

    return (
        <div className="divide-y divide-line">
            <section className="p-5">
                <h3 className="font-display text-base font-bold text-ink">Semáforo por zona</h3>
                <p className="mt-1 text-sm text-muted">El color del ticket depende de la zona del cajero y del tiempo hábil que lleva abierto, hasta que el técnico reporta el cierre.</p>
                <div className="mt-4 overflow-x-auto">
                    <table className="w-full min-w-[620px] text-sm">
                        <thead>
                            <tr className="text-left text-xs font-bold tracking-wide text-muted uppercase">
                                <th className="pb-2">Zona</th><th className="pb-2">Se mide en</th><th className="pb-2">Verde hasta</th><th className="pb-2">Naranja hasta</th><th className="pb-2">Después</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {current.policies.map((p, i) => (
                                <tr key={p.zone}>
                                    <td className="py-2.5 pr-3 font-semibold text-ink">{p.label}</td>
                                    <td className="py-2.5 pr-3">
                                        <Select value={p.unit} onChange={setPolicy(i, 'unit')} className="w-40" aria-label={`Unidad ${p.label}`}>
                                            <option value="hours">Horas hábiles</option>
                                            <option value="days">Días hábiles</option>
                                        </Select>
                                    </td>
                                    <td className="py-2.5 pr-3"><Input value={p.green} onChange={setPolicy(i, 'green')} inputMode="decimal" className="w-28 border-emerald-300 tabular" aria-label={`Verde hasta, ${p.label}`} /></td>
                                    <td className="py-2.5 pr-3"><Input value={p.orange} onChange={setPolicy(i, 'orange')} inputMode="decimal" className="w-28 border-orange-300 tabular" aria-label={`Naranja hasta, ${p.label}`} /></td>
                                    <td className="py-2.5"><LevelBadge level="critica" /></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <ul className="mt-3 space-y-1 text-xs text-muted">
                    {current.policies.map((p) => (
                        <li key={p.zone}><b className="text-body">{p.label}:</b> verde hasta {p.green} {unitLabel(p.unit, p.green)}, naranja hasta {p.orange} {unitLabel(p.unit, p.orange)}; después, crítica.</li>
                    ))}
                </ul>
            </section>

            <section className="p-5">
                <h3 className="font-display text-base font-bold text-ink">Horario bancario</h3>
                <p className="mt-1 text-sm text-muted">Solo este horario suma tiempo. Un día hábil equivale a una jornada completa (hoy, {Math.round((server.schedule.day_minutes / 60) * 10) / 10} h).</p>
                <div className="mt-4 flex flex-wrap items-end gap-4">
                    <label className="text-sm font-semibold text-ink">Desde
                        <Input type="time" value={current.schedule.start} onChange={(e) => setSchedule('start', e.target.value)} className="mt-1 w-32 tabular" />
                    </label>
                    <label className="text-sm font-semibold text-ink">Hasta
                        <Input type="time" value={current.schedule.end} onChange={(e) => setSchedule('end', e.target.value)} className="mt-1 w-32 tabular" />
                    </label>
                    <div>
                        <p className="text-sm font-semibold text-ink">Días</p>
                        <div className="mt-1 flex flex-wrap gap-1.5">
                            {DAYS.map(([d, label]) => (
                                <button
                                    key={d}
                                    type="button"
                                    onClick={() => toggleDay(d)}
                                    aria-pressed={current.schedule.days.includes(d)}
                                    className={cx('h-10 w-12 rounded-lg border text-sm font-semibold transition', current.schedule.days.includes(d) ? 'border-brand-500 bg-brand-500 text-white' : 'border-line bg-white text-muted hover:border-brand-300')}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                    </div>
                </div>
                <div className="mt-5 flex items-center justify-end gap-3">
                    {draft && <Button variant="secondary" onClick={() => setDraft(null)}>Descartar</Button>}
                    <Button loading={save.isPending} disabled={!draft} onClick={() => save.mutate()}>Guardar metas y horario</Button>
                </div>
            </section>

            <section className="p-5">
                <h3 className="font-display text-base font-bold text-ink">Feriados</h3>
                <p className="mt-1 text-sm text-muted">Estos días no suman tiempo. Agregue los feriados bancarios del año.</p>
                <form
                    className="mt-4 flex flex-wrap items-end gap-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        addHoliday.mutate();
                    }}
                >
                    <Input type="date" required value={holiday.date} onChange={(e) => setHoliday({ ...holiday, date: e.target.value })} className="w-44 tabular" aria-label="Fecha del feriado" />
                    <Input required maxLength={80} value={holiday.name} onChange={(e) => setHoliday({ ...holiday, name: e.target.value })} placeholder="Motivo (p. ej. Carnaval)" className="w-64" aria-label="Motivo del feriado" />
                    <Button type="submit" icon={Plus} loading={addHoliday.isPending}>Agregar</Button>
                </form>
                <ul className="mt-4 divide-y divide-line rounded-xl border border-line">
                    {server.holidays.length === 0 && <li className="px-4 py-3 text-sm text-muted">No hay feriados cargados.</li>}
                    {server.holidays.map((h) => (
                        <li key={h.id} className="flex items-center justify-between gap-3 px-4 py-2.5 text-sm">
                            <span><b className="tabular text-ink">{formatDate(h.date)}</b> <span className="text-body">· {h.name}</span></span>
                            <button type="button" onClick={() => removeHoliday.mutate(h.id)} className="rounded-lg p-1.5 text-faint hover:bg-red-50 hover:text-red-600" aria-label={`Quitar ${h.name}`} title="Quitar">
                                <X className="size-4" />
                            </button>
                        </li>
                    ))}
                </ul>
            </section>
        </div>
    );
}

const RESULT_STATUSES = ['concluido', 'pendiente_repuesto', 'pendiente_validacion'];

/** Formulario corto para agregar un elemento (una marca, o un modelo dentro de su marca). */
function AddForm({ type, extra = {}, placeholder, compact = false }) {
    const queryClient = useQueryClient();
    const [name, setName] = useState('');
    const add = useMutation({
        mutationFn: () => api(`catalogs/${type}`, { method: 'POST', body: { name, ...extra } }),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['catalogs'] });
            setName('');
            toast('Agregado');
        },
        onError: (e) => toast(e.field?.('name') || e.message, 'error'),
    });
    return (
        <form className={cx('flex gap-2', compact ? 'py-2' : 'border-b border-line bg-surface/60 px-5 py-3')} onSubmit={(e) => { e.preventDefault(); add.mutate(); }}>
            <Input value={name} onChange={(e) => setName(e.target.value)} placeholder={placeholder} required className={compact ? 'h-9 text-sm' : ''} />
            <Button type="submit" size={compact ? 'sm' : 'md'} variant={compact ? 'secondary' : 'primary'} icon={Plus} loading={add.isPending}>Agregar</Button>
        </form>
    );
}

/** Marcas de cajeros, cada una con sus modelos. */
function BrandsPanel({ brands }) {
    const [open, setOpen] = useState(null);
    return (
        <>
            <AddForm type="brands" placeholder="Agregar una marca, p. ej. NCR…" />
            {!brands.length ? (
                <p className="px-5 py-6 text-sm text-muted">Todavía no hay marcas.</p>
            ) : (
                <ul className="divide-y divide-line">
                    {brands.map((brand) => {
                        const expanded = open === brand.id;
                        return (
                            <li key={brand.id}>
                                <div className="flex items-center">
                                    <button
                                        type="button"
                                        onClick={() => setOpen(expanded ? null : brand.id)}
                                        className="flex shrink-0 items-center gap-1 py-3 pl-4 text-muted hover:text-ink"
                                        aria-expanded={expanded}
                                        aria-label={expanded ? `Ocultar modelos de ${brand.name}` : `Ver modelos de ${brand.name}`}
                                    >
                                        <ChevronRight className={cx('size-4 transition-transform', expanded && 'rotate-90')} />
                                    </button>
                                    <ul className="flex-1"><Row tab={{ type: 'brands' }} item={brand} /></ul>
                                    <Badge className="mr-5">{brand.models.length} {brand.models.length === 1 ? 'modelo' : 'modelos'}</Badge>
                                </div>
                                {expanded && (
                                    <div className="border-t border-line bg-surface/50 pr-5 pl-12">
                                        <AddForm type="models" extra={{ atm_brand_id: brand.id }} placeholder={`Agregar un modelo de ${brand.name}…`} compact />
                                        {brand.models.length > 0 && (
                                            <ul className="divide-y divide-line border-t border-line">
                                                {brand.models.map((m) => <Row key={m.id} tab={{ type: 'models' }} item={m} />)}
                                            </ul>
                                        )}
                                    </div>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}
        </>
    );
}

function Row({ tab, item }) {
    const queryClient = useQueryClient();
    const [editing, setEditing] = useState(false);
    const [form, setForm] = useState(item);
    const save = useMutation({
        mutationFn: (body) => api(`catalogs/${tab.type}/${item.id}`, { method: 'PUT', body }),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['catalogs'] });
            setEditing(false);
            toast('Guardado');
        },
        onError: (e) => toast(e.field?.('name') || e.message, 'error'),
    });

    return (
        <li className="flex flex-wrap items-center gap-3 px-5 py-3">
            {editing ? (
                <>
                    <Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className="min-w-[200px] flex-1" autoFocus />
                    {tab.type === 'atm-conditions' && (
                        <Select value={form.resulting_ticket_status} onChange={(e) => setForm({ ...form, resulting_ticket_status: e.target.value })} className="w-56">
                            {RESULT_STATUSES.map((s) => <option key={s} value={s}>{s.replace('_', ' ')}</option>)}
                        </Select>
                    )}
                    <Button size="sm" icon={Check} loading={save.isPending} onClick={() => save.mutate(form)}>Guardar</Button>
                    <Button size="sm" variant="ghost" icon={X} onClick={() => { setForm(item); setEditing(false); }} />
                </>
            ) : (
                <>
                    <span className={cx('flex-1 font-medium', item.active === false ? 'text-faint line-through' : 'text-ink')}>{item.name}</span>
                    {item.resulting_ticket_status && <StatusBadge status={item.resulting_ticket_status} size="sm" />}
                    {'active' in item && <Toggle checked={item.active} onChange={(v) => save.mutate({ ...item, active: v })} label={<span className="w-14 text-xs text-muted">{item.active ? 'Activo' : 'Inactivo'}</span>} />}
                    <Button size="sm" variant="ghost" icon={Pencil} onClick={() => setEditing(true)} aria-label="Editar" />
                </>
            )}
        </li>
    );
}

export default function Catalogs() {
    const { data, isLoading } = useCatalogs();
    const queryClient = useQueryClient();
    const [tab, setTab] = useState(TABS[0]);
    const [name, setName] = useState('');
    const add = useMutation({
        mutationFn: () => api(`catalogs/${tab.type}`, {
            method: 'POST',
            body: { name, ...(tab.type === 'atm-conditions' ? { resulting_ticket_status: 'pendiente_validacion', sort: 50 } : {}) },
        }),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['catalogs'] });
            setName('');
            toast('Agregado');
        },
        onError: (e) => toast(e.field?.('name') || e.message, 'error'),
    });
    const items = data?.[tab.key] ?? [];

    return (
        <>
            <PageHeader title="Catálogos" subtitle="Listas que alimentan los formularios de apertura y cierre" />
            <div className="grid gap-6 lg:grid-cols-[240px_1fr]">
                <nav className="flex gap-1 overflow-x-auto lg:flex-col">
                    {TABS.map((t) => (
                        <button
                            key={t.type}
                            type="button"
                            onClick={() => setTab(t)}
                            className={cx(
                                'flex items-center justify-between rounded-lg px-4 py-2.5 text-left text-sm font-semibold whitespace-nowrap transition-colors',
                                tab.type === t.type ? 'bg-navy-900 text-white' : 'text-body hover:bg-white',
                            )}
                        >
                            {t.label}
                            {data && data[t.key] && <Badge className={tab.type === t.type ? 'bg-white/15 text-white ring-white/20' : ''}>{data[t.key].length}</Badge>}
                        </button>
                    ))}
                </nav>
                <Card>
                    <div className="border-b border-line px-5 py-4">
                        <h2 className="text-base font-bold">{tab.label}</h2>
                        <p className="text-sm text-muted">{tab.hint}</p>
                    </div>
                    {tab.type === 'sla' ? <SlaSettings /> : tab.type === 'brands' ? (isLoading ? <LoadingBlock /> : <BrandsPanel brands={data?.brands ?? []} />) : (<>
                    <form className="flex gap-2 border-b border-line bg-surface/60 px-5 py-3" onSubmit={(e) => { e.preventDefault(); add.mutate(); }}>
                        <Input value={name} onChange={(e) => setName(e.target.value)} placeholder={`Agregar a ${tab.label.toLowerCase()}…`} required />
                        <Button type="submit" icon={Plus} loading={add.isPending}>Agregar</Button>
                    </form>
                    {isLoading ? <LoadingBlock /> : <ul className="divide-y divide-line">{items.map((i) => <Row key={`${tab.type}-${i.id}`} tab={tab} item={i} />)}</ul>}
                    </>)}
                </Card>
            </div>
        </>
    );
}
