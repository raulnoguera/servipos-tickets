import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Check, Pencil, Plus, X } from 'lucide-react';
import { api } from '../lib/api';
import { useCatalogs } from '../lib/queries';
import { Badge, Button, Card, Input, LoadingBlock, PageHeader, Select, StatusBadge, Toggle, cx, toast } from '../components/ui';

const TABS = [
    { type: 'fault-types', key: 'fault_types', label: 'Tipos de falla', hint: 'Motivos de visita (Dispensación, Impresora, Comunicación…)' },
    { type: 'parts', key: 'parts', label: 'Repuestos', hint: 'Se guardan en MAYÚSCULAS para evitar duplicados' },
    { type: 'atm-conditions', key: 'atm_conditions', label: 'Estatus final', hint: 'Cómo queda el cajero y a qué estado lleva el ticket' },
    { type: 'banks', key: 'banks', label: 'Bancos', hint: 'Clientes bancarios' },
    { type: 'states', key: 'states', label: 'Estados', hint: 'Estados y regiones de cobertura' },
];

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
                            {data && <Badge className={tab.type === t.type ? 'bg-white/15 text-white ring-white/20' : ''}>{data[t.key]?.length}</Badge>}
                        </button>
                    ))}
                </nav>
                <Card>
                    <div className="border-b border-line px-5 py-4">
                        <h2 className="text-base font-bold">{tab.label}</h2>
                        <p className="text-sm text-muted">{tab.hint}</p>
                    </div>
                    <form className="flex gap-2 border-b border-line bg-surface/60 px-5 py-3" onSubmit={(e) => { e.preventDefault(); add.mutate(); }}>
                        <Input value={name} onChange={(e) => setName(e.target.value)} placeholder={`Agregar a ${tab.label.toLowerCase()}…`} required />
                        <Button type="submit" icon={Plus} loading={add.isPending}>Agregar</Button>
                    </form>
                    {isLoading ? <LoadingBlock /> : <ul className="divide-y divide-line">{items.map((i) => <Row key={`${tab.type}-${i.id}`} tab={tab} item={i} />)}</ul>}
                </Card>
            </div>
        </>
    );
}
