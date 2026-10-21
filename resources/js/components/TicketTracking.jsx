import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { CloudUpload, Eye, EyeOff, Gauge, MessageSquare, Pencil, Send } from 'lucide-react';
import { api } from '../lib/api';
import { useCatalogs } from '../lib/queries';
import { formatDateTime, formatDuration, initials } from '../lib/format';
import { Alert, Button, Card, CardHeader, Field, Input, Modal, Select, STATUS, Textarea, cx, toast } from './ui';
import { SlaMeter, slaRule } from './Sla';

function useTicketUpdate(ticketId) {
    const queryClient = useQueryClient();
    return (response) => {
        queryClient.setQueryData(['ticket', String(ticketId)], response);
        queryClient.invalidateQueries({ queryKey: ['tickets'] });
        queryClient.invalidateQueries({ queryKey: ['board'] });
    };
}

/** Semáforo del SLA del ticket: según la zona del cajero, contando solo el horario bancario. */
export function SlaCard({ ticket }) {
    const sla = ticket.sla;
    return (
        <Card>
            <CardHeader title="SLA" subtitle={sla?.green != null ? slaRule(sla) : undefined} actions={<Gauge className="size-5 text-muted" />} />
            <div className="space-y-4 p-5">
                {ticket.source === 'import' ? (
                    <p className="text-sm text-muted">Ticket del histórico importado: no tiene horas, no se mide SLA.</p>
                ) : !sla?.level ? (
                    <p className="text-sm text-muted">{ticket.status === 'anulado' ? 'Ticket anulado: no se mide SLA.' : 'No hay metas de SLA configuradas para esta zona.'}</p>
                ) : (
                    <>
                        <SlaMeter sla={sla} />
                        <p className="text-xs text-muted">Solo cuenta el horario bancario de los días hábiles. El reloj corre hasta que el técnico reporta el cierre y no se detiene mientras espera repuesto.</p>
                    </>
                )}
            </div>
        </Card>
    );
}

/** Seguir / dejar de seguir: quien sigue recibe todos los avisos del ticket. */
export function WatchButton({ ticket }) {
    const update = useTicketUpdate(ticket.id);
    const toggle = useMutation({
        mutationFn: () => api(`tickets/${ticket.id}/watch`, { method: ticket.watching ? 'DELETE' : 'POST' }),
        onSuccess: (r) => {
            update(r);
            toast(r.data.watching ? 'Ahora sigue este ticket: recibirá todos sus avisos' : 'Dejó de seguir el ticket');
        },
        onError: (e) => toast(e.message, 'error'),
    });
    const count = ticket.watchers?.length ?? 0;
    return (
        <Button
            variant={ticket.watching ? 'primary' : 'secondary'}
            icon={ticket.watching ? Eye : EyeOff}
            loading={toggle.isPending}
            onClick={() => toggle.mutate()}
            title={count ? `Lo siguen: ${ticket.watchers.map((w) => w.name).join(', ')}` : 'Nadie sigue este ticket'}
        >
            {ticket.watching ? 'Siguiendo' : 'Seguir'}{count > 0 && <span className="ml-1 rounded-full bg-white/20 px-1.5 text-xs tabular">{count}</span>}
        </Button>
    );
}

/** Editar falla, detalle y caso del banco (queda en el historial). */
export function EditTicketModal({ ticket, open, onClose }) {
    const { data: catalogs } = useCatalogs();
    const update = useTicketUpdate(ticket.id);
    const [form, setForm] = useState({
        fault_type_id: ticket.fault_type_id ? String(ticket.fault_type_id) : '',
        description: ticket.description ?? '',
        bank_reference: ticket.bank_reference ?? '',
    });
    const set = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.value }));
    const save = useMutation({
        mutationFn: () => api(`tickets/${ticket.id}`, { method: 'PATCH', body: { ...form, fault_type_id: form.fault_type_id || null } }),
        onSuccess: (r) => {
            update(r);
            toast('Ticket actualizado');
            onClose();
        },
    });
    const err = save.error;

    return (
        <Modal
            open={open}
            onClose={onClose}
            title={`Editar ${ticket.number}`}
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>Cancelar</Button>
                    <Button loading={save.isPending} onClick={() => save.mutate()}>Guardar cambios</Button>
                </>
            }
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Tipo de falla" error={err?.field?.('fault_type_id')}>
                    {(id) => (
                        <Select id={id} value={form.fault_type_id} onChange={set('fault_type_id')}>
                            <option value="">Sin clasificar</option>
                            {catalogs?.fault_types.filter((f) => f.active || String(f.id) === form.fault_type_id).map((f) => <option key={f.id} value={f.id}>{f.name}</option>)}
                        </Select>
                    )}
                </Field>
                <Field label="N° de caso del banco" error={err?.field?.('bank_reference')}>
                    {(id) => <Input id={id} value={form.bank_reference} onChange={set('bank_reference')} maxLength={60} />}
                </Field>
                <Field label="Detalle del reporte" className="sm:col-span-2" error={err?.field?.('description')}>
                    {(id) => <Textarea id={id} value={form.description} onChange={set('description')} />}
                </Field>
            </div>
            <p className="mt-3 text-xs text-muted">Cada cambio queda registrado en la actividad del ticket con su autor y hora.</p>
            {err && !Object.keys(err.errors).length && <Alert className="mt-3">{err.message}</Alert>}
        </Modal>
    );
}

export function EditButton({ onClick }) {
    return <Button variant="secondary" icon={Pencil} onClick={onClick}>Editar</Button>;
}

/** Actividad: comentarios y cambios del ticket en una sola línea de tiempo, con caja para comentar. */
/** El registro se hizo sin señal: la hora la declaró el teléfono y llegó al servidor después. */
export function LateNote({ ms, receivedAt }) {
    return (
        <p className="mt-1 inline-flex items-center gap-1 rounded-md bg-amber-50 px-1.5 py-0.5 text-[11px] font-medium text-amber-800 ring-1 ring-amber-200 ring-inset" title="Hora declarada por el teléfono; se envió después por falta de señal">
            <CloudUpload className="size-3 shrink-0" />
            Enviado {formatDuration(ms)} después{receivedAt && ` · recibido ${formatDateTime(receivedAt)}`}
        </p>
    );
}

export function ActivityCard({ ticket }) {
    const update = useTicketUpdate(ticket.id);
    const [body, setBody] = useState('');
    const [filter, setFilter] = useState('all');
    const send = useMutation({
        mutationFn: () => api(`tickets/${ticket.id}/comments`, { method: 'POST', body: { body } }),
        onSuccess: (r) => {
            update(r);
            setBody('');
            toast('Comentario publicado');
        },
        onError: (e) => toast(e.field?.('body') || e.message, 'error'),
    });

    const items = [
        ...(ticket.comments ?? []).map((c) => ({ ...c, type: 'comment' })),
        ...(ticket.events ?? []).map((e, i) => ({ ...e, id: `e${i}`, type: 'event' })),
    ]
        .filter((i) => filter === 'all' || i.type === filter)
        .sort((a, b) => new Date(b.at) - new Date(a.at));

    return (
        <Card id="actividad">
            <CardHeader
                title="Actividad"
                subtitle="Comentarios de seguimiento y cambios del ticket"
                actions={
                    <div className="flex rounded-lg border border-line bg-white p-0.5 text-xs font-semibold">
                        {[['all', 'Todo'], ['comment', 'Comentarios'], ['event', 'Cambios']].map(([key, label]) => (
                            <button key={key} type="button" onClick={() => setFilter(key)} className={cx('rounded-md px-2.5 py-1', filter === key ? 'bg-navy-900 text-white' : 'text-muted hover:text-ink')}>
                                {label}
                            </button>
                        ))}
                    </div>
                }
            />
            <form
                className="border-b border-line p-5"
                onSubmit={(e) => {
                    e.preventDefault();
                    body.trim() && send.mutate();
                }}
            >
                <Textarea
                    value={body}
                    onChange={(e) => setBody(e.target.value)}
                    placeholder="Escriba un comentario: novedades, coordinación con el banco, repuesto en camino…"
                    className="min-h-20"
                    maxLength={2000}
                    aria-label="Nuevo comentario"
                    onKeyDown={(e) => {
                        if (e.key === 'Enter' && (e.metaKey || e.ctrlKey) && body.trim()) {
                            send.mutate();
                        }
                    }}
                />
                <div className="mt-2 flex items-center justify-between gap-3">
                    <p className="text-xs text-muted">Se avisa al técnico, a quien abrió el ticket y a quienes lo siguen.</p>
                    <Button type="submit" size="sm" icon={Send} loading={send.isPending} disabled={!body.trim()}>Comentar</Button>
                </div>
            </form>
            <ol className="space-y-4 p-5">
                {items.map((item) =>
                    item.type === 'comment' ? (
                        <li key={`c${item.id}`} className="flex gap-3">
                            <span className="grid size-8 shrink-0 place-items-center rounded-full bg-brand-500 text-[11px] font-bold text-white">{initials(item.user ?? 'Sistema')}</span>
                            <div className="min-w-0 flex-1 rounded-xl rounded-tl-sm bg-brand-50/70 px-3.5 py-2.5">
                                <p className="text-xs text-muted"><b className="text-ink">{item.user ?? 'Sistema'}</b> · {formatDateTime(item.at)}</p>
                                <p className="mt-1 text-sm whitespace-pre-line text-body">{item.body}</p>
                            </div>
                        </li>
                    ) : (
                        <li key={item.id} className="flex gap-3">
                            <span className="grid size-8 shrink-0 place-items-center rounded-full bg-surface text-muted ring-1 ring-line">
                                {item.to ? <span className="size-2 rounded-full bg-brand-500" /> : <MessageSquare className="size-3.5" />}
                            </span>
                            <div className="min-w-0 pt-1 text-sm">
                                <p className="text-ink">
                                    <b>{item.to ? (STATUS[item.to]?.label ?? item.to) : 'Edición'}</b>
                                    {item.note && <span className="text-body"> — {item.to ? item.note : item.note.replace(/^Editado:\s*/, '')}</span>}
                                </p>
                                <p className="text-xs text-muted">{formatDateTime(item.at)}{item.user && ` · ${item.user}`}</p>
                                {item.late_ms && <LateNote ms={item.late_ms} receivedAt={item.received_at} />}
                            </div>
                        </li>
                    ),
                )}
                {!items.length && <li className="text-sm text-muted">Sin actividad todavía.</li>}
            </ol>
        </Card>
    );
}
