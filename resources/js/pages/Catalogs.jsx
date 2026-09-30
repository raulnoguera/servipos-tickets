import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Check, Pencil, Plus, X } from 'lucide-react';
import { api } from '../lib/api';
import { useCatalogs } from '../lib/queries';
import { Badge, Button, Card, Input, LoadingBlock, PageHeader, Select, StatusBadge, Toggle, cx, toast } from '../components/ui';
import { PriorityBadge } from '../components/Sla';

const TABS = [
    { type: 'fault-types', key: 'fault_types', label: 'Tipos de falla', hint: 'Motivos de visita (Dispensación, Impresora, Comunicación…)' },
    { type: 'parts', key: 'parts', label: 'Repuestos', hint: 'Se guardan en MAYÚSCULAS para evitar duplicados' },
    { type: 'atm-conditions', key: 'atm_conditions', label: 'Estatus final', hint: 'Cómo queda el cajero y a qué estado lleva el ticket' },
    { type: 'banks', key: 'banks', label: 'Bancos', hint: 'Clientes bancarios' },
    { type: 'states', key: 'states', label: 'Estados', hint: 'Estados y regiones de cobertura' },
    { type: 'sla', key: 'sla', label: 'SLA', hint: 'Metas de tiempo por prioridad' },
];

const toHours = (minutes) => (minutes ? String(Math.round((minutes / 60) * 100) / 100).replace('.', ',') : '');
const toMinutes = (hours) => {
    const n = parseFloat(String(hours).replace(',', '.'));
    return Number.isFinite(n) && n > 0 ? Math.round(n * 60) : null;
};

/** Metas de SLA por prioridad (en horas en pantalla; minutos en el servidor). */
function SlaSettings() {
    const queryClient = useQueryClient();
    const { data, isLoading } = useQuery({ queryKey: ['sla-policies'], queryFn: () => api('sla-policies') });
    const [rows, setRows] = useState(null);
    const current = rows ?? data?.data.map((p) => ({ ...p, response: toHours(p.response_minutes), resolution: toHours(p.resolution_minutes) }));
    const save = useMutation({
        mutationFn: () => api('sla-policies', {
            method: 'PUT',
            body: { policies: current.map((p) => ({ priority: p.priority, response_minutes: toMinutes(p.response), resolution_minutes: toMinutes(p.resolution) })) },
        }),
        onSuccess: (r) => {
            queryClient.setQueryData(['sla-policies'], r);
            queryClient.invalidateQueries({ queryKey: ['tickets'] });
            setRows(null);
            toast('Metas de SLA guardadas');
        },
        onError: (e) => toast(e.message, 'error'),
    });
    if (isLoading || !current) {
        return <LoadingBlock />;
    }
    const setField = (i, key) => (e) => setRows(current.map((r, j) => (j === i ? { ...r, [key]: e.target.value } : r)));

    return (
        <div className="p-5">
            <div className="overflow-x-auto">
                <table className="w-full min-w-[480px] text-sm">
                    <thead>
                        <tr className="text-left text-xs font-bold tracking-wide text-muted uppercase">
                            <th className="pb-2">Prioridad</th><th className="pb-2">Llegada al sitio (horas)</th><th className="pb-2">Resolución (horas)</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-line">
                        {current.map((p, i) => (
                            <tr key={p.priority}>
                                <td className="py-2.5 pr-3"><PriorityBadge priority={p.priority} /></td>
                                <td className="py-2.5 pr-3"><Input value={p.response} onChange={setField(i, 'response')} inputMode="decimal" placeholder="Sin meta" className="w-36 tabular" aria-label={`Llegada ${p.label}`} /></td>
                                <td className="py-2.5"><Input value={p.resolution} onChange={setField(i, 'resolution')} inputMode="decimal" placeholder="Sin meta" className="w-36 tabular" aria-label={`Resolución ${p.label}`} /></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <p className="mt-3 text-xs text-muted">
                Ejemplo: 1,5 = 1 hora 30 minutos. Deje vacío para no medir esa meta. El reloj corre desde la apertura del ticket y no se detiene mientras espera repuesto.
                Los valores iniciales son de ejemplo: ajústelos a lo acordado con cada banco.
            </p>
            <div className="mt-4 flex justify-end">
                <Button loading={save.isPending} disabled={!rows} onClick={() => save.mutate()}>Guardar metas</Button>
            </div>
        </div>
    );
}

const RESULT_STATUSES = ['concluido', 'pendiente_repuesto', 'pendiente_validacion'];

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
                    {tab.type === 'sla' ? <SlaSettings /> : (<>
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
