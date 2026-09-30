import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Building2, Pencil, Plus, Search } from 'lucide-react';
import { api } from '../lib/api';
import { useAuth } from '../lib/auth';
import { useCatalogs } from '../lib/queries';
import {
    Alert, Badge, Button, Card, EmptyState, Field, Input, LoadingBlock, Modal, PageHeader, Pagination, Select, Table, Td, Th, Toggle, toast,
} from '../components/ui';

const EMPTY = { atm_number: '', serial: '', bank_id: '', state_id: '', agency_code: '', agency_name: '', site_type: 'AGENCIA', brand: 'DIEBOLD', model: '', address: '', active: true };

function AtmForm({ atm, onClose }) {
    const { data: catalogs } = useCatalogs();
    const queryClient = useQueryClient();
    const [form, setForm] = useState(() => (atm ? Object.fromEntries(Object.keys(EMPTY).map((k) => [k, atm[k] ?? ''])) : EMPTY));
    const set = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.value }));
    const save = useMutation({
        mutationFn: () => api(atm ? `atms/${atm.id}` : 'atms', { method: atm ? 'PUT' : 'POST', body: { ...form, state_id: form.state_id || null } }),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['atms'] });
            toast(atm ? 'Cajero actualizado' : 'Cajero creado');
            onClose();
        },
    });
    const err = save.error;
    const text = (key, label, props = {}) => (
        <Field label={label} error={err?.field?.(key)} required={props.required}>
            {(id) => <Input id={id} value={form[key]} onChange={set(key)} {...props} />}
        </Field>
    );

    return (
        <Modal
            open
            onClose={onClose}
            title={atm ? `Editar ATM ${atm.atm_number}` : 'Nuevo cajero'}
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>Cancelar</Button>
                    <Button loading={save.isPending} onClick={() => save.mutate()}>Guardar</Button>
                </>
            }
        >
            <div className="grid gap-4 sm:grid-cols-2">
                {text('atm_number', 'N° de ATM', { required: true, className: 'uppercase tabular' })}
                {text('serial', 'Serial del equipo')}
                <Field label="Banco" required error={err?.field?.('bank_id')}>
                    {(id) => (
                        <Select id={id} value={form.bank_id} onChange={set('bank_id')}>
                            <option value="">Seleccione…</option>
                            {catalogs?.banks.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                        </Select>
                    )}
                </Field>
                <Field label="Estado" error={err?.field?.('state_id')}>
                    {(id) => (
                        <Select id={id} value={form.state_id} onChange={set('state_id')}>
                            <option value="">Seleccione…</option>
                            {catalogs?.states.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                        </Select>
                    )}
                </Field>
                {text('agency_code', 'Código de agencia')}
                {text('agency_name', 'Nombre de agencia', { required: true })}
                <Field label="Tipo de sitio">
                    {(id) => (
                        <Select id={id} value={form.site_type} onChange={set('site_type')}>
                            <option value="AGENCIA">Agencia</option>
                            <option value="DIRECCIÓN GENERAL">Dirección general</option>
                            <option value="REMOTO">Remoto</option>
                        </Select>
                    )}
                </Field>
                {text('brand', 'Marca')}
                {text('model', 'Modelo', { placeholder: 'OPTEVA 562' })}
                <div className="sm:col-span-2">{text('address', 'Dirección / ubicación')}</div>
                <Toggle checked={!!form.active} onChange={(v) => setForm((f) => ({ ...f, active: v }))} label="Activo" />
            </div>
            {err && !Object.keys(err.errors).length && <Alert className="mt-4">{err.message}</Alert>}
        </Modal>
    );
}

export default function Atms() {
    const { isAdmin } = useAuth();
    const { data: catalogs } = useCatalogs();
    const [q, setQ] = useState('');
    const [debounced, setDebounced] = useState('');
    const [state, setState] = useState('');
    const [page, setPage] = useState(1);
    const [editing, setEditing] = useState(null);

    useEffect(() => {
        const t = setTimeout(() => { setDebounced(q); setPage(1); }, 300);
        return () => clearTimeout(t);
    }, [q]);

    const { data, isLoading } = useQuery({
        queryKey: ['atms', debounced, state, page],
        queryFn: () => api('atms', { query: { q: debounced, state_id: state, page } }),
        placeholderData: keepPreviousData,
    });

    return (
        <>
            <PageHeader
                title="Maestro de cajeros"
                subtitle={data ? `${data.meta.total} cajeros registrados` : 'Cajeros automáticos bajo servicio'}
                actions={isAdmin && <Button icon={Plus} onClick={() => setEditing('new')}>Nuevo cajero</Button>}
            />
            <Card>
                <div className="grid gap-3 border-b border-line p-4 sm:grid-cols-[1fr_240px]">
                    <div className="relative">
                        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-faint" />
                        <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Buscar por N° de ATM, serial, agencia o código" className="pl-9" />
                    </div>
                    <Select value={state} onChange={(e) => { setState(e.target.value); setPage(1); }} aria-label="Estado">
                        <option value="">Todos los estados</option>
                        {catalogs?.states.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                    </Select>
                </div>
                {isLoading ? (
                    <LoadingBlock />
                ) : !data.data.length ? (
                    <EmptyState icon={Building2} title="No se encontraron cajeros" description="Importe el Excel histórico o registre uno nuevo." />
                ) : (
                    <>
                        <Table>
                            <thead>
                                <tr>
                                    <Th>N° ATM</Th><Th>Serial</Th><Th>Agencia</Th><Th>Estado</Th><Th>Modelo</Th><Th>Banco</Th><Th className="text-center">Abiertos</Th>{isAdmin && <Th />}
                                </tr>
                            </thead>
                            <tbody>
                                {data.data.map((a) => (
                                    <tr key={a.id} className="hover:bg-brand-50/40">
                                        <Td className="font-semibold tabular">
                                            <Link to={`/cajeros/${a.id}`} className="text-brand-600 hover:underline" title="Ver hoja de vida">{a.atm_number}</Link> {!a.active && <Badge className="ml-1">Inactivo</Badge>}
                                        </Td>
                                        <Td className="text-muted tabular">{a.serial ?? '—'}</Td>
                                        <Td className="max-w-[260px]">
                                            <div className="truncate text-ink">{a.agency_name}</div>
                                            <div className="text-xs text-muted">{a.agency_code ? `Cód. ${a.agency_code}` : 'Sin código'} · {a.site_type ?? '—'}</div>
                                        </Td>
                                        <Td>{a.state ?? '—'}</Td>
                                        <Td>{a.model ?? <span className="text-faint">Sin dato</span>}</Td>
                                        <Td className="max-w-[160px] truncate">{a.bank ?? '—'}</Td>
                                        <Td className="text-center">{a.open_tickets ? <Badge tone="warn">{a.open_tickets}</Badge> : <span className="text-faint">0</span>}</Td>
                                        {isAdmin && (
                                            <Td className="text-right">
                                                <Button variant="ghost" size="sm" icon={Pencil} onClick={() => setEditing(a)}>Editar</Button>
                                            </Td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                        <Pagination meta={data.meta} onPage={setPage} />
                    </>
                )}
            </Card>
            {editing && <AtmForm atm={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
        </>
    );
}
