import { useEffect } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ChevronRight, ClipboardCheck, CloudUpload, MapPin, Navigation, PartyPopper, Truck } from 'lucide-react';
import { api } from '../lib/api';
import { useAuth } from '../lib/auth';
import { changeStatus } from '../lib/actions';
import { queuedState, useOutbox } from '../lib/sync';
import { mapsUrl } from '../lib/format';
import { Alert, Badge, Button, Card, Elapsed, EmptyState, LoadingBlock, StatusBadge, toast } from '../components/ui';
import PushPrompt from '../components/PushPrompt';

const ORDER = { en_sitio: 0, en_transito: 1, asignado: 2, pendiente_repuesto: 3, pendiente_validacion: 4 };

function TicketCard({ ticket, queued }) {
    const { user } = useAuth();
    const queryClient = useQueryClient();
    const status = queued.status ?? ticket.status;

    const move = useMutation({
        mutationFn: (next) => changeStatus({ ticket, status: next, userId: user.id }),
        onSuccess: (r) => {
            queryClient.invalidateQueries({ queryKey: ['my-tickets'] });
            toast(r.queued ? 'Sin señal: guardado en el teléfono, se enviará al volver la conexión' : r.data.data.status_label);
        },
        onError: (e) => toast(e.field?.('status') || e.message, 'error'),
    });

    const next = {
        asignado: { status: 'en_transito', label: 'Voy en camino', icon: Truck },
        en_transito: { status: 'en_sitio', label: 'Llegué al sitio', icon: MapPin },
    }[status];

    return (
        <Card className="overflow-hidden">
            <Link to={`/tickets/${ticket.id}`} className="block p-4 active:bg-surface">
                <div className="flex items-center justify-between gap-2">
                    <span className="font-display text-lg font-extrabold text-ink tabular">{ticket.number}</span>
                    <StatusBadge status={status} size="sm" />
                </div>
                <p className="mt-2 font-semibold text-ink">{ticket.atm.agency_name}</p>
                <p className="text-sm text-muted">
                    ATM {ticket.atm.atm_number}{ticket.atm.state && ` · ${ticket.atm.state}`}
                </p>
                {ticket.fault_type && <p className="mt-2 text-sm text-body"><b className="text-ink">Falla:</b> {ticket.fault_type}</p>}
                {queued.count > 0 && (
                    <Badge tone={queued.hasError ? 'danger' : 'warn'} className="mt-2">
                        <CloudUpload className="size-3" />
                        {queued.hasError ? 'Envío con error, revíselo' : queued.visitQueued ? 'Atención guardada · por enviar' : 'Cambio guardado · por enviar'}
                    </Badge>
                )}
                <div className="mt-2 flex items-center justify-between text-xs text-muted">
                    <Elapsed ticket={ticket} />
                    <span className="inline-flex items-center text-brand-600">Ver detalle <ChevronRight className="size-4" /></span>
                </div>
            </Link>
            {!queued.visitQueued && (
                <div className="flex gap-2 border-t border-line bg-surface/60 p-3">
                    <Button as="a" href={mapsUrl(ticket.atm)} target="_blank" rel="noreferrer" variant="secondary" icon={Navigation} className="flex-1 px-3">Cómo llegar</Button>
                    {next ? (
                        <Button icon={next.icon} className="flex-1 px-3" loading={move.isPending} onClick={() => move.mutate(next.status)}>{next.label}</Button>
                    ) : (
                        <Button as={Link} to={`/tickets/${ticket.id}?atender=1`} icon={ClipboardCheck} className="flex-1 px-3">Registrar atención</Button>
                    )}
                </div>
            )}
        </Card>
    );
}

export default function TechnicianHome() {
    const { user } = useAuth();
    const queryClient = useQueryClient();
    const queuedItems = useOutbox(user.id);
    const { data, isLoading, error } = useQuery({
        queryKey: ['my-tickets'],
        queryFn: () => api('tickets', { query: { open: 1, per_page: 100 } }),
        refetchInterval: 30000,
    });

    // Descarga el detalle de cada ticket para poder abrirlo luego sin señal.
    useEffect(() => {
        data?.data.forEach((t) => queryClient.prefetchQuery({ queryKey: ['ticket', String(t.id)], queryFn: () => api(`tickets/${t.id}`) }));
        queryClient.prefetchQuery({ queryKey: ['catalogs'], queryFn: () => api('catalogs'), staleTime: 10 * 60 * 1000 });
    }, [data, queryClient]);

    const tickets = [...(data?.data ?? [])]
        .map((t) => ({ ticket: t, queued: queuedState(queuedItems, t.id) }))
        .sort((a, b) => (ORDER[a.queued.status ?? a.ticket.status] ?? 9) - (ORDER[b.queued.status ?? b.ticket.status] ?? 9));
    const isPending = ({ ticket, queued }) => (queued.status ?? ticket.status).startsWith('pendiente') || queued.visitQueued;
    const active = tickets.filter((t) => !isPending(t));
    const pending = tickets.filter(isPending);
    const hour = new Date().getHours();

    return (
        <div className="mx-auto max-w-2xl">
            <div className="mb-5">
                <p className="text-sm text-muted">{hour < 12 ? 'Buenos días' : hour < 19 ? 'Buenas tardes' : 'Buenas noches'},</p>
                <h1 className="text-2xl font-extrabold">{user.name.split(' ')[0]}</h1>
            </div>

            <PushPrompt />

            {isLoading ? (
                <LoadingBlock />
            ) : error ? (
                <Alert tone="warn">{error.offline ? 'Sin señal y aún no hay tickets guardados en este teléfono. Se cargarán al volver la conexión.' : error.message}</Alert>
            ) : !tickets.length ? (
                <Card>
                    <EmptyState icon={PartyPopper} title="No tiene tickets pendientes" description="Cuando el Call Center le asigne una llamada aparecerá aquí." />
                </Card>
            ) : (
                <div className="space-y-8">
                    <section>
                        <h2 className="mb-3 text-sm font-bold tracking-wide text-muted uppercase">Por atender ({active.length})</h2>
                        <div className="space-y-3">
                            {active.length ? active.map((t) => <TicketCard key={t.ticket.id} {...t} />) : <p className="text-sm text-muted">Nada por atender ahora.</p>}
                        </div>
                    </section>
                    {pending.length > 0 && (
                        <section>
                            <h2 className="mb-3 text-sm font-bold tracking-wide text-muted uppercase">Pendientes ({pending.length})</h2>
                            <div className="space-y-3">{pending.map((t) => <TicketCard key={t.ticket.id} {...t} />)}</div>
                        </section>
                    )}
                </div>
            )}
        </div>
    );
}
