import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil, Plus } from 'lucide-react';
import { api } from '../lib/api';
import { useCatalogs } from '../lib/queries';
import { initials } from '../lib/format';
import {
    Alert, Badge, Button, Card, Field, Input, LoadingBlock, Modal, PageHeader, Select, Table, Td, Th, Toggle, cx, toast,
} from '../components/ui';

const ROLE_TONE = { admin: 'danger', operator: 'brand', technician: 'ok' };

function UserForm({ user, onClose }) {
    const { data: catalogs } = useCatalogs();
    const queryClient = useQueryClient();
    const [form, setForm] = useState(
        user
            ? { name: user.name, email: user.email, role: user.role, phone: user.phone ?? '', active: user.active, password: '', state_ids: user.state_ids ?? [] }
            : { name: '', email: '', role: 'technician', phone: '', active: true, password: '', state_ids: [] },
    );
    const set = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.value }));
    const save = useMutation({
        mutationFn: () => api(user ? `users/${user.id}` : 'users', { method: user ? 'PUT' : 'POST', body: form }),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['users'] });
            queryClient.invalidateQueries({ queryKey: ['catalogs'] });
            toast(user ? 'Usuario actualizado' : 'Usuario creado');
            onClose();
        },
    });
    const err = save.error;
    const toggleState = (id) => setForm((f) => ({ ...f, state_ids: f.state_ids.includes(id) ? f.state_ids.filter((s) => s !== id) : [...f.state_ids, id] }));

    return (
        <Modal
            open
            onClose={onClose}
            title={user ? 'Editar usuario' : 'Nuevo usuario'}
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>Cancelar</Button>
                    <Button loading={save.isPending} onClick={() => save.mutate()}>Guardar</Button>
                </>
            }
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Nombre completo" required error={err?.field?.('name')}>{(id) => <Input id={id} value={form.name} onChange={set('name')} />}</Field>
                <Field label="Rol" required error={err?.field?.('role')}>
                    {(id) => (
                        <Select id={id} value={form.role} onChange={set('role')}>
                            {catalogs?.roles.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
                        </Select>
                    )}
                </Field>
                <Field label="Correo (usuario)" required error={err?.field?.('email')}>{(id) => <Input id={id} type="email" value={form.email} onChange={set('email')} />}</Field>
                <Field label="Teléfono (WhatsApp)" hint="Ej. 0414-1234567" error={err?.field?.('phone')}>{(id) => <Input id={id} type="tel" value={form.phone} onChange={set('phone')} />}</Field>
                <Field label={user ? 'Nueva contraseña' : 'Contraseña'} required={!user} hint={user ? 'Déjela vacía para no cambiarla' : 'Mínimo 8 caracteres'} error={err?.field?.('password')} className="sm:col-span-2">
                    {(id) => <Input id={id} type="password" autoComplete="new-password" value={form.password} onChange={set('password')} />}
                </Field>
                {form.role === 'technician' && (
                    <div className="sm:col-span-2">
                        <p className="mb-2 text-sm font-semibold text-ink">Estados que cubre</p>
                        <div className="flex flex-wrap gap-2">
                            {catalogs?.states.map((s) => (
                                <button
                                    key={s.id}
                                    type="button"
                                    onClick={() => toggleState(s.id)}
                                    className={cx(
                                        'rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset transition',
                                        form.state_ids.includes(s.id) ? 'bg-brand-500 text-white ring-brand-500' : 'bg-white text-body ring-line hover:ring-brand-200',
                                    )}
                                >
                                    {s.name}
                                </button>
                            ))}
                        </div>
                    </div>
                )}
                <Toggle checked={form.active} onChange={(v) => setForm((f) => ({ ...f, active: v }))} label="Puede iniciar sesión" />
            </div>
            {err && !Object.keys(err.errors).length && <Alert className="mt-4">{err.message}</Alert>}
        </Modal>
    );
}

export default function Users() {
    const [role, setRole] = useState('');
    const [editing, setEditing] = useState(null);
    const { data, isLoading } = useQuery({ queryKey: ['users', role], queryFn: () => api('users', { query: { role } }) });
    const provisional = data?.data.filter((u) => u.email.endsWith('.servipos.test')).length ?? 0;

    return (
        <>
            <PageHeader
                title="Usuarios y técnicos"
                subtitle="Accesos por rol: Administrador, Operador de Call Center y Técnico de campo"
                actions={<Button icon={Plus} onClick={() => setEditing('new')}>Nuevo usuario</Button>}
            />
            {provisional > 0 && (
                <Alert tone="warn" className="mb-4">
                    {provisional} técnico(s) importados del Excel tienen correo provisional (…@tecnicos.servipos.test). Actualice su correo, teléfono y contraseña para darles acceso.
                </Alert>
            )}
            <Card>
                <div className="border-b border-line p-4">
                    <div className="sm:w-60">
                        <Select value={role} onChange={(e) => setRole(e.target.value)} aria-label="Rol">
                            <option value="">Todos los roles</option>
                            <option value="admin">Administradores</option>
                            <option value="operator">Operadores Call Center</option>
                            <option value="technician">Técnicos de campo</option>
                        </Select>
                    </div>
                </div>
                {isLoading ? (
                    <LoadingBlock />
                ) : (
                    <Table>
                        <thead>
                            <tr><Th>Usuario</Th><Th>Rol</Th><Th>Teléfono</Th><Th>Cobertura</Th><Th className="text-center">Tickets activos</Th><Th /></tr>
                        </thead>
                        <tbody>
                            {data.data.map((u) => (
                                <tr key={u.id} className={cx('hover:bg-brand-50/40', !u.active && 'opacity-60')}>
                                    <Td>
                                        <div className="flex items-center gap-3">
                                            <div className="grid size-9 shrink-0 place-items-center rounded-full bg-brand-50 text-xs font-bold text-brand-700">{initials(u.name)}</div>
                                            <div className="min-w-0">
                                                <div className="font-semibold text-ink">{u.name} {!u.active && <Badge>Inactivo</Badge>}</div>
                                                <div className="truncate text-xs text-muted">{u.email}</div>
                                            </div>
                                        </div>
                                    </Td>
                                    <Td><Badge tone={ROLE_TONE[u.role]}>{u.role_label}</Badge></Td>
                                    <Td className="tabular">{u.phone ?? <span className="text-faint">—</span>}</Td>
                                    <Td className="max-w-[260px] truncate text-sm">{u.states?.length ? u.states.join(', ') : <span className="text-faint">—</span>}</Td>
                                    <Td className="text-center tabular">{u.open_tickets ?? 0}</Td>
                                    <Td className="text-right"><Button variant="ghost" size="sm" icon={Pencil} onClick={() => setEditing(u)}>Editar</Button></Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}
            </Card>
            {editing && <UserForm user={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
        </>
    );
}
