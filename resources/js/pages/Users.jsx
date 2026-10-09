import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Check, Copy, KeyRound, MessageCircle, Pencil, Plus } from 'lucide-react';
import { api } from '../lib/api';
import { useCatalogs } from '../lib/queries';
import { formatDateTime, initials } from '../lib/format';
import {
    Alert, Badge, Button, Card, Field, Input, LoadingBlock, Modal, PageHeader, Select, Table, Td, Th, Toggle, cx, toast,
} from '../components/ui';

const ROLE_TONE = { admin: 'danger', operator: 'brand', technician: 'ok' };

function UserForm({ user, onClose }) {
    const { data: catalogs } = useCatalogs();
    const queryClient = useQueryClient();
    const [form, setForm] = useState(
        user
            ? { name: user.name, email: user.email ?? '', role: user.role, phone: user.phone ?? '', active: user.active, password: '', state_ids: user.state_ids ?? [] }
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
    const isTechnician = form.role === 'technician';
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
                <Field label={isTechnician ? 'Teléfono (su usuario y WhatsApp)' : 'Teléfono (WhatsApp)'} required={isTechnician} hint={isTechnician ? 'Con este número inicia sesión. Ej. 0414-1234567' : 'Ej. 0414-1234567'} error={err?.field?.('phone')}>
                    {(id) => <Input id={id} type="tel" value={form.phone} onChange={set('phone')} />}
                </Field>
                <Field label={isTechnician ? 'Correo (opcional)' : 'Correo (su usuario)'} required={!isTechnician} error={err?.field?.('email')}>
                    {(id) => <Input id={id} type="email" value={form.email} onChange={set('email')} />}
                </Field>
                <Field label="Contraseña (opcional)" hint={user ? 'Déjela vacía para no cambiarla. Lo recomendado es usar “Enviar acceso” para que el usuario cree la suya.' : 'Puede dejarla vacía y usar “Enviar acceso”: el usuario crea su propia contraseña.'} error={err?.field?.('password')} className="sm:col-span-2">
                    {(id) => <Input id={id} type="password" autoComplete="new-password" value={form.password} onChange={set('password')} placeholder="Mínimo 10 caracteres, con letras y números" />}
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

/** Enlace de un solo uso para que el usuario cree su contraseña; se envía por WhatsApp o se copia. */
function AccessLinkModal({ user, onClose }) {
    const queryClient = useQueryClient();
    const [copied, setCopied] = useState(false);
    const create = useMutation({
        mutationFn: () => api(`users/${user.id}/access-link`, { method: 'POST' }),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['users'] }),
    });
    const link = create.data;

    async function copy() {
        try {
            await navigator.clipboard.writeText(link.message);
            setCopied(true);
            setTimeout(() => setCopied(false), 2500);
        } catch {
            toast('No se pudo copiar. Seleccione el texto y cópielo a mano.', 'error');
        }
    }

    return (
        <Modal
            open
            onClose={onClose}
            title={`Acceso para ${user.name}`}
            size="sm"
            footer={link ? <Button variant="secondary" onClick={onClose}>Listo</Button> : (
                <>
                    <Button variant="secondary" onClick={onClose}>Cancelar</Button>
                    <Button icon={KeyRound} loading={create.isPending} onClick={() => create.mutate()}>Generar enlace</Button>
                </>
            )}
        >
            {!link ? (
                <>
                    <p className="text-sm text-body">
                        Se genera un enlace de <b>un solo uso</b>, válido por 48 horas, para que {user.name.split(' ')[0]} cree su propia contraseña.
                        Usted no la conocerá. Sirve para el primer ingreso y cuando alguien olvida su contraseña.
                    </p>
                    <p className="mt-3 rounded-xl bg-surface px-3.5 py-3 text-sm text-body">Ingresará con su usuario: <b className="text-ink tabular">{user.login}</b></p>
                    {user.access_link_expires_at && <Alert tone="warn" className="mt-3">Ya tiene un enlace vigente. Al generar uno nuevo, el anterior deja de funcionar.</Alert>}
                    {create.error && <Alert className="mt-3">{create.error.field?.('user') || create.error.message}</Alert>}
                </>
            ) : (
                <>
                    <p className="text-sm text-body">Enlace listo. Vence el <b className="text-ink">{formatDateTime(link.expires_at)}</b>. Envíeselo solo a {user.name.split(' ')[0]}:</p>
                    <p className="mt-3 max-h-40 overflow-y-auto rounded-xl bg-surface p-3 text-xs break-words whitespace-pre-line text-body select-all">{link.message}</p>
                    <div className="mt-4 flex flex-wrap gap-2">
                        {link.whatsapp_url && (
                            <Button as="a" href={link.whatsapp_url} target="_blank" rel="noreferrer" variant="whatsapp" icon={MessageCircle} className="flex-1">Enviar por WhatsApp</Button>
                        )}
                        <Button variant="secondary" icon={copied ? Check : Copy} onClick={copy} className="flex-1">{copied ? 'Copiado' : 'Copiar mensaje'}</Button>
                    </div>
                    {!link.whatsapp_url && <p className="mt-3 text-xs text-muted">Este usuario no tiene teléfono cargado: copie el mensaje y envíelo por el medio que prefiera.</p>}
                </>
            )}
        </Modal>
    );
}

export default function Users() {
    const [role, setRole] = useState('');
    const [editing, setEditing] = useState(null);
    const [access, setAccess] = useState(null);
    const { data, isLoading } = useQuery({ queryKey: ['users', role], queryFn: () => api('users', { query: { role } }) });
    // Técnicos importados del Excel: solo tienen nombre, todavía no pueden iniciar sesión.
    const withoutLogin = data?.data.filter((u) => u.active && !u.login).length ?? 0;
    const neverSignedIn = data?.data.filter((u) => u.active && u.login && !u.last_login_at).length ?? 0;

    return (
        <>
            <PageHeader
                title="Usuarios y técnicos"
                subtitle="Accesos por rol: Administrador, Operador de Call Center y Técnico de campo"
                actions={<Button icon={Plus} onClick={() => setEditing('new')}>Nuevo usuario</Button>}
            />
            {withoutLogin > 0 && (
                <Alert tone="warn" className="mb-4">
                    {withoutLogin} técnico(s) no tienen teléfono cargado y todavía no pueden ingresar. Edítelos para agregar su número (es su usuario) y luego use “Enviar acceso”.
                </Alert>
            )}
            {withoutLogin === 0 && neverSignedIn > 0 && (
                <Alert tone="info" className="mb-4">
                    {neverSignedIn} usuario(s) aún no han ingresado. Use “Enviar acceso” para mandarles por WhatsApp el enlace con el que crean su contraseña.
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
                            <tr><Th>Usuario</Th><Th>Rol</Th><Th>Cobertura</Th><Th>Último ingreso</Th><Th className="text-center">Tickets activos</Th><Th /></tr>
                        </thead>
                        <tbody>
                            {data.data.map((u) => (
                                <tr key={u.id} className={cx('hover:bg-brand-50/40', !u.active && 'opacity-60')}>
                                    <Td>
                                        <div className="flex items-center gap-3">
                                            <div className="grid size-9 shrink-0 place-items-center rounded-full bg-brand-50 text-xs font-bold text-brand-700">{initials(u.name)}</div>
                                            <div className="min-w-0">
                                                <div className="font-semibold text-ink">{u.name} {!u.active && <Badge>Inactivo</Badge>}</div>
                                                <div className="truncate text-xs text-muted tabular">{u.login ?? <span className="text-amber-700">Sin usuario: falta el teléfono</span>}</div>
                                            </div>
                                        </div>
                                    </Td>
                                    <Td><Badge tone={ROLE_TONE[u.role]}>{u.role_label}</Badge></Td>
                                    <Td className="max-w-[240px] truncate text-sm">{u.states?.length ? u.states.join(', ') : <span className="text-faint">—</span>}</Td>
                                    <Td className="text-sm whitespace-nowrap">
                                        {u.last_login_at ? formatDateTime(u.last_login_at) : u.access_link_expires_at ? <Badge tone="brand">Enlace enviado</Badge> : <Badge tone="warn">Nunca ingresó</Badge>}
                                    </Td>
                                    <Td className="text-center tabular">{u.open_tickets ?? 0}</Td>
                                    <Td className="text-right whitespace-nowrap">
                                        {u.active && u.login && <Button variant="ghost" size="sm" icon={KeyRound} onClick={() => setAccess(u)}>Enviar acceso</Button>}
                                        <Button variant="ghost" size="sm" icon={Pencil} onClick={() => setEditing(u)}>Editar</Button>
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}
            </Card>
            {editing && <UserForm user={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
            {access && <AccessLinkModal user={access} onClose={() => setAccess(null)} />}
        </>
    );
}
