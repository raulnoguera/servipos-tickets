import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Mail, MailCheck, RefreshCw, Search } from 'lucide-react';
import { api } from '../lib/api';
import { formatDateTime } from '../lib/format';
import { useCatalogs } from '../lib/queries';
import AtmSearch from '../components/AtmSearch';
import { Alert, Badge, Button, Card, EmptyState, Field, Input, LoadingBlock, Modal, PageHeader, Pagination, Segmented, Select, Table, Td, Th, toast } from '../components/ui';

const STATUS = {
    revisar: { label: 'Por revisar', tone: 'warn' },
    ticket: { label: 'Ticket abierto', tone: 'ok' },
    seguimiento: { label: 'Agregado a un ticket', tone: 'brand' },
    ignorado: { label: 'Ignorado', tone: 'neutral' },
};

function useDebounced(value, ms = 350) {
    const [debounced, setDebounced] = useState(value);
    useEffect(() => {
        const timer = setTimeout(() => setDebounced(value), ms);
        return () => clearTimeout(timer);
    }, [value, ms]);
    return debounced;
}

/** Lo que el sistema entendió del correo. */
function Parsed({ parsed, className }) {
    const items = [
        ['ATM', [parsed?.atm_number, parsed?.agency].filter(Boolean).join(' · ')],
        ['Serial', parsed?.serial],
        ['Falla', parsed?.fault],
    ].filter(([, value]) => value);
    if (!items.length) {
        return null;
    }
    return (
        <p className={className}>
            {items.map(([label, value], i) => (
                <span key={label}>{i > 0 && ' · '}<span className="text-faint">{label}</span> {value}</span>
            ))}
        </p>
    );
}

/** Estado de la lectura automática del buzón: apagada, al día, detenida o con error. */
function Reading({ meta }) {
    if (!meta) {
        return null;
    }
    if (!meta.reading) {
        return <Alert tone="info" className="mb-4">La lectura automática del buzón todavía no está activada. Cuando se conecte, los correos aparecerán aquí.</Alert>;
    }
    if (meta.last_error) {
        return (
            <Alert className="mb-4">
                El sistema no logra leer el buzón desde {formatDateTime(meta.last_error.at)}. Los reportes que lleguen no abrirán tickets hasta que se resuelva; avise al administrador.
                <span className="mt-1 block text-xs opacity-80">{meta.last_error.message}</span>
            </Alert>
        );
    }
    if (!meta.last_read_at) {
        return <Alert tone="warn" className="mb-4">El buzón está conectado, pero el sistema todavía no lo ha leído. La primera lectura ocurre a los pocos minutos de activar la tarea programada.</Alert>;
    }
    const minutes = Math.round((Date.now() - new Date(meta.last_read_at).getTime()) / 60000);
    if (minutes > 15) {
        return <Alert tone="warn" className="mb-4">La última lectura del buzón fue el {formatDateTime(meta.last_read_at)}. Debería leerse cada dos minutos; avise al administrador.</Alert>;
    }
    return <p className="mb-3 text-xs text-muted">Buzón leído por última vez {minutes < 1 ? 'hace menos de un minuto' : `hace ${minutes} min`} · se revisa cada dos minutos.</p>;
}

/** Correo abierto: lo que dice, lo que el sistema hizo y, si quedó por revisar, cómo resolverlo. */
function EmailModal({ email, onClose }) {
    const { data: catalogs } = useCatalogs();
    const queryClient = useQueryClient();
    const [atm, setAtm] = useState(null);
    const [notFound, setNotFound] = useState(null);
    const [faultTypeId, setFaultTypeId] = useState('');
    const [priority, setPriority] = useState('media');

    useEffect(() => {
        setAtm(email?.atm?.active ? email.atm : null);
        setNotFound(null);
        setFaultTypeId('');
        setPriority('media');
    }, [email?.id]);

    const done = (message) => {
        queryClient.invalidateQueries({ queryKey: ['emails'] });
        queryClient.invalidateQueries({ queryKey: ['tickets'] });
        queryClient.invalidateQueries({ queryKey: ['dashboard'] });
        toast(message);
        onClose();
    };
    const open = useMutation({
        mutationFn: () => api(`inbound-emails/${email.id}/ticket`, { method: 'POST', body: { atm_id: atm.id, fault_type_id: faultTypeId || null, priority } }),
        onSuccess: (res) => done(`Ticket ${res.data.number} abierto`),
    });
    const ignore = useMutation({
        mutationFn: () => api(`inbound-emails/${email.id}/ignore`, { method: 'POST' }),
        onSuccess: () => done('Correo descartado'),
    });
    const error = open.error ?? ignore.error;
    const pending = email?.status === 'revisar';

    return (
        <Modal
            open={!!email}
            onClose={onClose}
            title={email?.subject || 'Correo sin asunto'}
            size="lg"
            footer={pending && (
                <>
                    <Button variant="ghost" onClick={() => ignore.mutate()} loading={ignore.isPending} disabled={open.isPending}>Descartar</Button>
                    <Button onClick={() => open.mutate()} loading={open.isPending} disabled={!atm || ignore.isPending}>Abrir ticket</Button>
                </>
            )}
        >
            {email && (
                <div className="space-y-4">
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                        <Badge tone={STATUS[email.status].tone}>{STATUS[email.status].label}</Badge>
                        <span className="text-ink">{email.from_name || email.from_email}</span>
                        {email.from_name && <span className="text-muted">{email.from_email}</span>}
                        <span className="text-muted tabular">{formatDateTime(email.sent_at)}</span>
                    </div>

                    {email.reason && <Alert tone={pending ? 'warn' : 'info'}>{email.reason}</Alert>}
                    {email.ticket && (
                        <p className="text-sm">
                            Ticket <Link className="font-semibold text-brand-600 hover:underline" to={`/tickets/${email.ticket.id}`}>{email.ticket.number}</Link>
                            {email.handled_by && <span className="text-muted"> · abierto por {email.handled_by}</span>}
                        </p>
                    )}
                    {!email.ticket && email.handled_by && <p className="text-sm text-muted">Descartado por {email.handled_by} · {formatDateTime(email.handled_at)}</p>}

                    <div>
                        <Parsed parsed={email.parsed} className="mb-2 text-sm text-ink" />
                        <pre className="max-h-64 overflow-auto rounded-lg border border-line bg-surface p-3 font-sans text-sm whitespace-pre-wrap text-ink">{email.body || 'El correo no tiene texto.'}</pre>
                    </div>

                    {pending && (
                        <div className="space-y-3 border-t border-line pt-4">
                            <p className="text-sm font-semibold text-ink">Abrir el ticket a mano</p>
                            {atm ? (
                                <div className="flex items-center justify-between gap-3 rounded-lg border border-brand-200 bg-brand-50 px-4 py-2.5 text-sm">
                                    <span><span className="font-semibold text-ink">{atm.atm_number}</span> · {atm.agency_name} <span className="text-muted">· Serial {atm.serial || '—'}</span></span>
                                    <button type="button" className="font-semibold text-brand-600 hover:underline" onClick={() => setAtm(null)}>Cambiar</button>
                                </div>
                            ) : (
                                <>
                                    <AtmSearch onSelect={(found) => { setAtm(found); setNotFound(null); }} onNotFound={setNotFound} />
                                    {notFound && <Alert tone="warn">{notFound}</Alert>}
                                </>
                            )}
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Field label="Tipo de falla" hint="Vacío: lo deduce del correo">
                                    {(id) => (
                                        <Select id={id} value={faultTypeId} onChange={(e) => setFaultTypeId(e.target.value)}>
                                            <option value="">Según el correo</option>
                                            {catalogs?.fault_types.filter((f) => f.active).map((f) => <option key={f.id} value={f.id}>{f.name}</option>)}
                                        </Select>
                                    )}
                                </Field>
                                <Field label="Prioridad">
                                    {(id) => (
                                        <Select id={id} value={priority} onChange={(e) => setPriority(e.target.value)}>
                                            {catalogs?.priorities?.map((p) => <option key={p.value} value={p.value}>{p.label}</option>)}
                                        </Select>
                                    )}
                                </Field>
                            </div>
                            <p className="text-xs text-muted">El tiempo del ticket se cuenta desde que el banco envió el correo.</p>
                            {error && <Alert>{error.message}</Alert>}
                        </div>
                    )}
                </div>
            )}
        </Modal>
    );
}

/** Correos de los bancos: lo que el sistema hizo con cada uno y los que esperan una decisión. */
export default function Emails() {
    const [status, setStatus] = useState('revisar');
    const [q, setQ] = useState('');
    const [page, setPage] = useState(1);
    const [selected, setSelected] = useState(null);
    const term = useDebounced(q);

    const { data, isLoading, error } = useQuery({
        queryKey: ['emails', status, term, page],
        queryFn: () => api('inbound-emails', { query: { status, q: term, page } }),
        placeholderData: keepPreviousData,
        refetchInterval: 30000,
    });
    const counts = data?.meta.counts ?? {};
    const queryClient = useQueryClient();
    const reevaluate = useMutation({
        mutationFn: () => api('inbound-emails/reevaluate', { method: 'POST' }),
        onSuccess: (r) => {
            ['emails', 'tickets', 'dashboard'].forEach((key) => queryClient.invalidateQueries({ queryKey: [key] }));
            const solved = [r.tickets && `${r.tickets} ticket(s) abierto(s)`, r.follow_ups && `${r.follow_ups} agregado(s) a tickets abiertos`].filter(Boolean).join(' y ');
            toast(solved ? `${solved}. ${r.pending ? `Siguen por revisar: ${r.pending}.` : 'No queda nada por revisar.'}` : 'Ningún correo pudo resolverse solo: siguen necesitando una decisión.');
        },
        onError: (e) => toast(e.message, 'error'),
    });
    const tab = (value, label) => ({ value, label: counts[value] ? `${label} (${counts[value]})` : label });

    return (
        <>
            <PageHeader
                title="Correos del banco"
                subtitle="Reportes de falla que llegan al buzón. El sistema abre el ticket cuando reconoce el banco y el cajero; los que generan dudas esperan aquí a que alguien decida."
                actions={counts.revisar > 0 && (
                    <Button variant="secondary" icon={RefreshCw} onClick={() => reevaluate.mutate()} loading={reevaluate.isPending} title="Después de cargar o corregir cajeros, intenta de nuevo con los correos por revisar">
                        Volver a evaluar
                    </Button>
                )}
            />
            <Reading meta={data?.meta} />
            <Card>
                <div className="flex flex-wrap items-center gap-3 border-b border-line p-4">
                    <Segmented
                        size="sm"
                        value={status}
                        onChange={(value) => { setStatus(value); setPage(1); }}
                        options={[tab('revisar', 'Por revisar'), tab('ticket', 'Tickets abiertos'), tab('seguimiento', 'Agregados'), tab('ignorado', 'Ignorados'), { value: '', label: 'Todos' }]}
                    />
                    <div className="relative min-w-[220px] flex-1">
                        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-faint" />
                        <Input value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} placeholder="Buscar por asunto, remitente o texto" className="pl-9" aria-label="Buscar" />
                    </div>
                </div>

                {isLoading ? (
                    <LoadingBlock />
                ) : error ? (
                    <EmptyState title="No se pudieron cargar los correos" description={error.message} />
                ) : !data.data.length ? (
                    <EmptyState
                        icon={status === 'revisar' ? MailCheck : Mail}
                        title={status === 'revisar' ? 'Nada por revisar' : 'Sin correos'}
                        description={status === 'revisar' ? 'Todos los correos recibidos quedaron resueltos.' : 'No hay correos con estos filtros.'}
                    />
                ) : (
                    <Table>
                        <thead>
                            <tr><Th>Enviado</Th><Th>Remitente</Th><Th>Correo</Th><Th>Resultado</Th></tr>
                        </thead>
                        <tbody>
                            {data.data.map((email) => (
                                <tr key={email.id} className="cursor-pointer align-top hover:bg-surface" onClick={() => setSelected(email)}>
                                    <Td className="whitespace-nowrap tabular">{formatDateTime(email.sent_at)}</Td>
                                    <Td>
                                        <span className="text-ink">{email.from_name || email.from_email}</span>
                                        {email.from_name && <p className="text-xs text-muted">{email.from_email}</p>}
                                    </Td>
                                    <Td>
                                        <button type="button" className="text-left font-semibold text-brand-600 hover:underline">{email.subject || 'Sin asunto'}</button>
                                        <Parsed parsed={email.parsed} className="mt-0.5 text-xs text-muted" />
                                        {email.status === 'revisar' && email.reason && <p className="mt-0.5 text-xs text-amber-800">{email.reason}</p>}
                                    </Td>
                                    <Td className="whitespace-nowrap">
                                        <Badge tone={STATUS[email.status].tone}>{STATUS[email.status].label}</Badge>
                                        {email.ticket && (
                                            <p className="mt-1 text-xs">
                                                <Link className="font-semibold text-brand-600 hover:underline" to={`/tickets/${email.ticket.id}`} onClick={(e) => e.stopPropagation()}>{email.ticket.number}</Link>
                                            </p>
                                        )}
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}
                <Pagination meta={data?.meta} onPage={setPage} />
            </Card>
            <EmailModal email={selected} onClose={() => setSelected(null)} />
        </>
    );
}
