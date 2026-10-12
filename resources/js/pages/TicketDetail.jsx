import { useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    ArrowLeft, Ban, Building2, CheckCircle2, ClipboardCheck, FileImage, Hourglass, MapPin, MessageCircle, Navigation, Package, Truck, Undo2, UserPlus,
} from 'lucide-react';
import { api } from '../lib/api';
import { useAuth } from '../lib/auth';
import { useCatalogs } from '../lib/queries';
import { changeStatus } from '../lib/actions';
import { queuedState, useOutbox } from '../lib/sync';
import { formatDate, formatDateTime, formatDurationExact, mapsUrl } from '../lib/format';
import {
    Alert, Badge, Button, Card, CardHeader, Elapsed, EmptyState, Field, Input, LoadingBlock, Modal, Select, StatusBadge, Textarea, toast,
} from '../components/ui';
import VisitForm from '../components/VisitForm';
import { PriorityBadge, SlaBadge } from '../components/Sla';
import { ActivityCard, EditButton, EditTicketModal, LateNote, SlaCard, WatchButton } from '../components/TicketTracking';

function Info({ label, children }) {
    return (
        <div>
            <dt className="text-xs text-muted">{label}</dt>
            <dd className="mt-0.5 font-semibold text-ink">{children || <span className="font-normal text-faint">—</span>}</dd>
        </div>
    );
}

function AssignModal({ ticket, open, onClose }) {
    const { data: catalogs } = useCatalogs();
    const queryClient = useQueryClient();
    const [technician, setTechnician] = useState(ticket.technician?.id ? String(ticket.technician.id) : '');
    const assign = useMutation({
        mutationFn: () => api(`tickets/${ticket.id}/assign`, { method: 'POST', body: { technician_id: technician } }),
        onSuccess: (r) => {
            queryClient.setQueryData(['ticket', String(ticket.id)], r);
            queryClient.invalidateQueries({ queryKey: ['tickets'] });
            toast(`Asignado a ${r.data.technician.name}`);
            onClose();
            if (r.data.whatsapp_url) {
                window.open(r.data.whatsapp_url, '_blank', 'noopener');
            }
        },
    });
    const list = catalogs?.technicians ?? [];
    const stateId = ticket.atm.state_id;
    const covering = list.filter((t) => t.state_ids.includes(stateId));
    const others = list.filter((t) => !t.state_ids.includes(stateId));

    return (
        <Modal
            open={open}
            onClose={onClose}
            title={ticket.technician ? 'Reasignar técnico' : 'Asignar técnico'}
            size="sm"
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>Cancelar</Button>
                    <Button icon={MessageCircle} disabled={!technician} loading={assign.isPending} onClick={() => assign.mutate()}>
                        Asignar y avisar
                    </Button>
                </>
            }
        >
            <Field label="Técnico" error={assign.error?.field?.('technician_id') || assign.error?.field?.('status')}>
                {(id) => (
                    <Select id={id} value={technician} onChange={(e) => setTechnician(e.target.value)}>
                        <option value="">Seleccione…</option>
                        {covering.length > 0 && (
                            <optgroup label={`Cubren ${ticket.atm.state}`}>
                                {covering.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                            </optgroup>
                        )}
                        <optgroup label={covering.length ? 'Otros técnicos' : 'Técnicos'}>
                            {others.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                        </optgroup>
                    </Select>
                )}
            </Field>
            <p className="mt-3 text-xs text-muted">Al asignar se abre WhatsApp con el resumen del ticket listo para enviar al técnico.</p>
        </Modal>
    );
}

function CancelModal({ ticket, open, onClose }) {
    const queryClient = useQueryClient();
    const [note, setNote] = useState('');
    const cancel = useMutation({
        mutationFn: () => api(`tickets/${ticket.id}/status`, { method: 'POST', body: { status: 'anulado', note } }),
        onSuccess: (r) => {
            queryClient.setQueryData(['ticket', String(ticket.id)], r);
            queryClient.invalidateQueries({ queryKey: ['tickets'] });
            toast('Ticket anulado');
            onClose();
        },
    });
    return (
        <Modal
            open={open}
            onClose={onClose}
            title="Anular ticket"
            size="sm"
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>Volver</Button>
                    <Button variant="danger" icon={Ban} disabled={!note.trim()} loading={cancel.isPending} onClick={() => cancel.mutate()}>Anular</Button>
                </>
            }
        >
            <Field label="Motivo" required hint="Por ejemplo: reporte duplicado o el banco canceló la solicitud." error={cancel.error?.field?.('note') || cancel.error?.field?.('status')}>
                {(id) => <Input id={id} value={note} onChange={(e) => setNote(e.target.value)} maxLength={255} autoFocus />}
            </Field>
        </Modal>
    );
}

/** Actualiza el ticket en pantalla y las listas donde aparece. */
function useClosureAction(ticket, path, message, onClose) {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: (body) => api(`tickets/${ticket.id}/${path}`, { method: 'POST', body }),
        onSuccess: (r) => {
            queryClient.setQueryData(['ticket', String(ticket.id)], r);
            ['tickets', 'board', 'dashboard', 'notifications-unread'].forEach((key) => queryClient.invalidateQueries({ queryKey: [key] }));
            toast(message);
            onClose();
        },
    });
}

/** Última confirmación del administrador: con ella el ticket queda concluido. */
function ConfirmClosureModal({ ticket, open, onClose }) {
    const [note, setNote] = useState('');
    const confirm = useClosureAction(ticket, 'confirm', 'Cierre confirmado · ticket concluido', onClose);
    const visit = ticket.visits[ticket.visits.length - 1];
    return (
        <Modal
            open={open}
            onClose={onClose}
            title="Confirmar cierre del ticket"
            size="sm"
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>Volver</Button>
                    <Button variant="success" icon={CheckCircle2} loading={confirm.isPending} onClick={() => confirm.mutate({ note: note.trim() || null })}>Sí, cerrar ticket</Button>
                </>
            }
        >
            <p className="text-sm text-body">
                Va a dar por concluido el ticket <b className="text-ink tabular">{ticket.number}</b> del ATM <b className="text-ink">{ticket.atm.atm_number}</b> ({ticket.atm.agency_name}).
                Esta es la última confirmación: después no se podrá modificar.
            </p>
            {visit && (
                <dl className="mt-4 space-y-1 rounded-xl bg-surface p-3 text-sm">
                    <div className="flex justify-between gap-3"><dt className="text-muted">Técnico</dt><dd className="font-semibold text-ink">{visit.technician ?? '—'}</dd></div>
                    <div className="flex justify-between gap-3"><dt className="text-muted">Estatus final</dt><dd className="font-semibold text-ink">{visit.atm_condition ?? '—'}</dd></div>
                    <div className="flex justify-between gap-3"><dt className="text-muted">Constancia</dt><dd className="font-semibold text-ink">{visit.constancia_number ?? 'Sin número'}{visit.constancia_url ? ' · con foto' : ' · sin foto'}</dd></div>
                </dl>
            )}
            <Field label="Observación (opcional)" className="mt-4" error={confirm.error?.field?.('note') || confirm.error?.field?.('status') || confirm.error?.message}>
                {(id) => <Input id={id} value={note} onChange={(e) => setNote(e.target.value)} maxLength={255} placeholder="Ej.: validado con el banco" />}
            </Field>
        </Modal>
    );
}

/** El administrador no da por bueno el cierre y lo devuelve al técnico. */
function RejectClosureModal({ ticket, open, onClose }) {
    const [note, setNote] = useState('');
    const reject = useClosureAction(ticket, 'reject', 'Ticket devuelto al técnico', onClose);
    return (
        <Modal
            open={open}
            onClose={onClose}
            title="Devolver al técnico"
            size="sm"
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>Volver</Button>
                    <Button variant="danger" icon={Undo2} disabled={note.trim().length < 5} loading={reject.isPending} onClick={() => reject.mutate({ note })}>Devolver</Button>
                </>
            }
        >
            <p className="mb-4 text-sm text-body">El ticket vuelve a quedar asignado a {ticket.technician?.name ?? 'el técnico'}, que recibe el aviso con el motivo. El reloj del SLA sigue corriendo desde la apertura.</p>
            <Field label="Motivo" required hint="Por ejemplo: el banco reporta que el cajero sigue fallando, o falta la foto de la constancia." error={reject.error?.field?.('note') || reject.error?.field?.('status')}>
                {(id) => <Textarea id={id} value={note} onChange={(e) => setNote(e.target.value)} maxLength={255} className="min-h-20" autoFocus />}
            </Field>
        </Modal>
    );
}

function VisitCard({ visit, index, onPhoto }) {
    return (
        <li className="rounded-xl border border-line p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex items-center gap-2">
                    <span className="grid size-7 place-items-center rounded-full bg-navy-900 text-xs font-bold text-white">{index + 1}</span>
                    <span className="font-semibold text-ink">{formatDate(visit.visit_date)}</span>
                    {visit.finished_at && <span className="text-sm text-muted">{new Date(visit.finished_at).toLocaleTimeString('es-VE', { hour: '2-digit', minute: '2-digit' })}</span>}
                </div>
                {visit.atm_condition && <Badge tone={visit.atm_condition === 'Operativo' ? 'ok' : visit.atm_condition.startsWith('Operativo') ? 'warn' : 'danger'}>{visit.atm_condition}</Badge>}
            </div>
            {visit.late_ms && <LateNote ms={visit.late_ms} />}
            <dl className="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
                <Info label="Técnico">{visit.technician}</Info>
                <Info label="N° constancia">{visit.constancia_number}</Info>
                <Info label="Falla">{visit.fault_type}</Info>
            </dl>
            {visit.work_done && (
                <div className="mt-3">
                    <p className="text-xs text-muted">Trabajo realizado</p>
                    <p className="mt-1 rounded-lg bg-surface p-3 text-sm whitespace-pre-line text-body">{visit.work_done}</p>
                </div>
            )}
            <div className="mt-3 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p className="text-xs text-muted">
                        {visit.parts.length
                            ? `Repuestos utilizados en esta atención (${visit.parts.reduce((total, p) => total + p.quantity, 0)} ${visit.parts.reduce((total, p) => total + p.quantity, 0) === 1 ? 'unidad' : 'unidades'})`
                            : 'Repuestos utilizados en esta atención'}
                    </p>
                    <div className="mt-1.5 flex flex-wrap gap-2">
                        {visit.parts.length ? (
                            visit.parts.map((p) => (
                                <Badge key={p.id} tone="brand"><Package className="size-3" /> {p.name} × {p.quantity}</Badge>
                            ))
                        ) : (
                            <span className="text-sm font-medium text-body">No se cambiaron repuestos</span>
                        )}
                    </div>
                </div>
                {visit.constancia_url && (
                    <button type="button" onClick={() => onPhoto(visit.constancia_url)} className="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-600 hover:underline">
                        <FileImage className="size-4" /> Ver constancia
                    </button>
                )}
            </div>
        </li>
    );
}

export default function TicketDetail() {
    const { id } = useParams();
    const { user, canManage, isTechnician } = useAuth();
    const queuedItems = useOutbox(user.id);
    const queryClient = useQueryClient();
    const [searchParams, setSearchParams] = useSearchParams();
    const [modal, setModal] = useState(searchParams.get('atender') ? 'visit' : null);
    const [photo, setPhoto] = useState(null);

    const { data, isLoading, error } = useQuery({
        queryKey: ['ticket', id],
        queryFn: () => api(`tickets/${id}`),
        refetchInterval: 30000,
    });

    const move = useMutation({
        mutationFn: (status) => changeStatus({ ticket: data.data, status, userId: user.id }),
        onSuccess: (r) => {
            if (r.queued) {
                toast('Sin señal: guardado en el teléfono, se enviará al volver la conexión');
                return;
            }
            queryClient.setQueryData(['ticket', id], r.data);
            queryClient.invalidateQueries({ queryKey: ['my-tickets'] });
            toast(`Estado: ${r.data.data.status_label}`);
        },
        onError: (e) => toast(e.field?.('status') || e.message, 'error'),
    });

    if (isLoading) {
        return <LoadingBlock />;
    }
    if (error) {
        const title = error.offline
            ? 'Sin señal: este ticket no se había abierto antes en este teléfono'
            : error.status === 403 ? 'No tiene acceso a este ticket' : 'No se encontró el ticket';
        return <EmptyState title={title} action={<Button as={Link} to="/" variant="secondary">Volver</Button>} />;
    }

    const queued = queuedState(queuedItems, data.data.id);
    const t = { ...data.data, status: queued.status ?? data.data.status };
    const finished = ['concluido', 'anulado'].includes(t.status);
    // El técnico ya informó el cierre: el reloj está detenido y solo falta la confirmación del administrador.
    const awaiting = t.status === 'por_confirmar';
    const closed = finished || awaiting || queued.visitQueued;
    // El técnico solo trabaja los tickets que tiene asignados; los de su historial son de consulta.
    const mine = !isTechnician || t.technician?.id === user.id;
    const canWork = !closed && t.status !== 'abierto' && mine;
    const backTo = isTechnician ? (mine && !finished ? '/mis-tickets' : '/historial') : '/tickets';

    return (
        <>
            <Link to={backTo} className="mb-3 inline-flex items-center gap-1.5 text-sm font-semibold text-muted hover:text-ink">
                <ArrowLeft className="size-4" /> {isTechnician ? (backTo === '/historial' ? 'Mi historial' : 'Mis tickets') : 'Tickets'}
            </Link>

            <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div className="flex flex-wrap items-center gap-3">
                        <h1 className="font-display text-3xl font-extrabold tracking-tight tabular">{t.number}</h1>
                        <StatusBadge status={t.status} />
                        <PriorityBadge priority={t.priority} />
                        {!finished && <SlaBadge sla={t.sla} />}
                    </div>
                    <p className="mt-1 text-sm text-muted">
                        Abierto {formatDateTime(t.opened_at)}{t.opened_by && ` por ${t.opened_by}`}
                        {t.bank_reference && <> · Caso banco <b className="text-ink">{t.bank_reference}</b></>}
                        {t.source === 'import' && <> · <Badge>Histórico Excel</Badge></>}
                        {t.source === 'email' && <> · <Badge tone="brand">Por correo del banco</Badge></>}
                    </p>
                    {t.confirmed_at && (
                        <p className="mt-0.5 text-sm text-muted">
                            Cierre confirmado {formatDateTime(t.confirmed_at)}{t.confirmed_by && ` por ${t.confirmed_by}`}
                        </p>
                    )}
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <WatchButton ticket={t} />
                    {canManage && <EditButton onClick={() => setModal('edit')} />}
                </div>
                <div className="rounded-xl border border-line bg-white px-4 py-2.5 text-right">
                    <p className="text-xs font-semibold text-muted">{finished ? 'Tiempo total del ciclo' : awaiting ? 'Tiempo hasta la atención' : 'Tiempo transcurrido'}</p>
                    <p className="font-display text-2xl font-extrabold text-ink"><Elapsed ticket={t} /></p>
                    {(finished || awaiting) && t.duration_ms !== null && <p className="text-[11px] text-muted tabular" title="Duración exacta con milisegundos">{formatDurationExact(t.duration_ms)}</p>}
                </div>
            </div>

            <div className="grid gap-6 lg:grid-cols-[1fr_340px]">
                <div className="space-y-6">
                    {queued.count > 0 && (
                        <Alert tone={queued.hasError ? 'danger' : 'warn'}>
                            {queued.hasError
                                ? 'Un envío de este ticket no se pudo registrar. Revíselo en el aviso de arriba.'
                                : queued.visitQueued
                                  ? 'La atención quedó guardada en el teléfono y se enviará sola al volver la señal.'
                                  : 'Hay un cambio de estado guardado en el teléfono que se enviará al volver la señal.'}
                        </Alert>
                    )}

                    {isTechnician && !mine && (
                        <Alert tone="info">Este ticket ahora está a cargo de {t.technician?.name ?? 'otro técnico'}. Puede consultarlo, pero no registrar cambios.</Alert>
                    )}

                    {/* Cierre informado por el técnico: última confirmación del administrador */}
                    {awaiting && (t.can_confirm ? (
                        <Card className="border-teal-200 bg-teal-50/60 p-5">
                            <div className="flex items-start gap-3">
                                <span className="grid size-10 shrink-0 place-items-center rounded-lg bg-teal-600 text-white"><Hourglass className="size-5" /></span>
                                <div className="min-w-0 flex-1">
                                    <h2 className="font-display text-lg font-bold text-ink">El técnico informó que el cajero quedó operativo</h2>
                                    <p className="mt-1 text-sm text-body">Revise la atención, los repuestos y la constancia. Con su confirmación el ticket queda concluido.</p>
                                    <div className="mt-4 flex flex-wrap gap-2">
                                        <Button size="lg" variant="success" icon={CheckCircle2} onClick={() => setModal('confirm')}>Confirmar cierre</Button>
                                        <Button size="lg" variant="secondary" icon={Undo2} onClick={() => setModal('reject')}>Devolver al técnico</Button>
                                    </div>
                                </div>
                            </div>
                        </Card>
                    ) : (
                        <Alert tone="info">
                            {isTechnician
                                ? 'Atención enviada. El ticket quedará concluido cuando el administrador confirme el cierre; si necesita algo más, se lo devolverá con el motivo.'
                                : 'El técnico informó que el cajero quedó operativo. El ticket queda concluido cuando el administrador confirme el cierre.'}
                        </Alert>
                    ))}

                    {/* Acciones */}
                    {!closed && mine && (
                        <Card className="p-4">
                            <div className="flex flex-wrap gap-2">
                                {isTechnician && t.status === 'asignado' && (
                                    <Button size="lg" icon={Truck} loading={move.isPending} onClick={() => move.mutate('en_transito')}>Voy en camino</Button>
                                )}
                                {isTechnician && ['asignado', 'en_transito'].includes(t.status) && (
                                    <Button size="lg" variant={t.status === 'en_transito' ? 'primary' : 'secondary'} icon={MapPin} loading={move.isPending} onClick={() => move.mutate('en_sitio')}>Llegué al sitio</Button>
                                )}
                                {canWork && (
                                    <Button size="lg" variant={t.status === 'en_sitio' || t.status.startsWith('pendiente') || !isTechnician ? 'primary' : 'secondary'} icon={ClipboardCheck} onClick={() => setModal('visit')}>
                                        Registrar atención
                                    </Button>
                                )}
                                {canManage && (
                                    <Button size="lg" variant={t.status === 'abierto' ? 'primary' : 'secondary'} icon={UserPlus} onClick={() => setModal('assign')}>
                                        {t.technician ? 'Reasignar' : 'Asignar técnico'}
                                    </Button>
                                )}
                                {canManage && t.whatsapp_url && (
                                    <Button as="a" size="lg" href={t.whatsapp_url} target="_blank" rel="noreferrer" variant="whatsapp" icon={MessageCircle}>WhatsApp</Button>
                                )}
                                {canManage && <Button size="lg" variant="ghost" icon={Ban} onClick={() => setModal('cancel')} className="sm:ml-auto">Anular</Button>}
                            </div>
                            {t.status === 'abierto' && <Alert tone="info" className="mt-3">Asigne un técnico para que pueda registrar la atención.</Alert>}
                        </Card>
                    )}

                    {/* Falla */}
                    <Card>
                        <CardHeader title="Falla reportada" />
                        <div className="p-5">
                            <p className="font-semibold text-ink">{t.fault_type ?? 'Sin clasificar'}</p>
                            {t.description ? <p className="mt-2 text-sm whitespace-pre-line text-body">{t.description}</p> : <p className="mt-1 text-sm text-faint">Sin detalle.</p>}
                        </div>
                    </Card>

                    {/* Visitas */}
                    <Card>
                        <CardHeader title="Atenciones en sitio" subtitle={`${t.visits.length} visita(s)`} />
                        <div className="p-5">
                            {t.visits.length ? (
                                <ol className="space-y-3">{t.visits.map((v, i) => <VisitCard key={v.id} visit={v} index={i} onPhoto={setPhoto} />)}</ol>
                            ) : (
                                <EmptyState icon={ClipboardCheck} title="Aún no hay atenciones" description="Cuando el técnico registre la visita aparecerá aquí con la constancia y los repuestos." />
                            )}
                        </div>
                    </Card>

                    <ActivityCard ticket={t} />
                </div>

                <div className="space-y-6">
                    <SlaCard ticket={t} />

                    <Card>
                        <CardHeader
                            title="Cajero"
                            actions={canManage ? <Link to={`/cajeros/${t.atm.id}`} className="text-sm font-semibold text-brand-600 hover:underline">Hoja de vida</Link> : <Building2 className="size-5 text-brand-500" />}
                        />
                        <div className="p-5">
                            <p className="text-xs font-semibold tracking-wide text-brand-600 uppercase">ATM {t.atm.atm_number}</p>
                            <p className="font-display text-lg font-bold text-ink">{t.atm.agency_name}</p>
                            {t.atm.address && <p className="text-sm text-muted">{t.atm.address}</p>}
                            <dl className="mt-4 grid grid-cols-2 gap-3 text-sm">
                                <Info label="Serial">{t.atm.serial}</Info>
                                <Info label="Cód. agencia">{t.atm.agency_code}</Info>
                                <Info label="Estado">{t.atm.state}</Info>
                                <Info label="Modelo">{[t.atm.brand, t.atm.model].filter(Boolean).join(' ')}</Info>
                                <Info label="Banco">{t.bank}</Info>
                            </dl>
                            <Button as="a" href={mapsUrl(t.atm)} target="_blank" rel="noreferrer" variant="secondary" icon={Navigation} className="mt-4 w-full">Cómo llegar</Button>
                        </div>
                    </Card>

                    <Card>
                        <CardHeader title="Técnico" />
                        <div className="p-5 text-sm">
                            {t.technician ? (
                                <>
                                    <p className="font-semibold text-ink">{t.technician.name}</p>
                                    {t.technician.phone && <a href={`tel:${t.technician.phone}`} className="text-brand-600 hover:underline">{t.technician.phone}</a>}
                                    {t.assigned_at && <p className="mt-1 text-xs text-muted">Asignado {formatDateTime(t.assigned_at)}</p>}
                                </>
                            ) : (
                                <p className="text-faint">Sin asignar</p>
                            )}
                        </div>
                    </Card>

                </div>
            </div>

            <Modal open={modal === 'visit' && canWork} onClose={() => { setModal(null); setSearchParams({}, { replace: true }); }} title={`Registrar atención · ${t.number}`} size="lg">
                <VisitForm ticket={t} onDone={() => { setModal(null); setSearchParams({}, { replace: true }); }} />
            </Modal>
            {modal === 'assign' && <AssignModal ticket={t} open onClose={() => setModal(null)} />}
            {modal === 'cancel' && <CancelModal ticket={t} open onClose={() => setModal(null)} />}
            {modal === 'confirm' && <ConfirmClosureModal ticket={t} open onClose={() => setModal(null)} />}
            {modal === 'reject' && <RejectClosureModal ticket={t} open onClose={() => setModal(null)} />}
            {modal === 'edit' && <EditTicketModal ticket={t} open onClose={() => setModal(null)} />}
            <Modal open={!!photo} onClose={() => setPhoto(null)} title="Constancia de servicio" size="lg">
                {photo && <img src={photo} alt="Constancia de servicio" className="mx-auto max-h-[75dvh] rounded-lg" />}
            </Modal>
        </>
    );
}
