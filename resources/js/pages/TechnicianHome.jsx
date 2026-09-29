import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ChevronRight, ClipboardCheck, MapPin, Navigation, PartyPopper, Truck } from 'lucide-react';
import { api } from '../lib/api';
import { useAuth } from '../lib/auth';
import { mapsUrl } from '../lib/format';
import { Button, Card, Elapsed, EmptyState, LoadingBlock, StatusBadge, toast } from '../components/ui';

const ORDER = { en_sitio: 0, en_transito: 1, asignado: 2, pendiente_repuesto: 3, pendiente_validacion: 4 };

function TicketCard({ ticket }) {
    const queryClient = useQueryClient();
    const move = useMutation({
        mutationFn: (status) => api(`tickets/${ticket.id}/status`, { method: 'POST', body: { status } }),
        onSuccess: (r) => {
            queryClient.invalidateQueries({ queryKey: ['my-tickets'] });
            toast(r.data.status_label);
        },
        onError: (e) => toast(e.field?.('status') || e.message, 'error'),
    });

    const next = {
        asignado: { status: 'en_transito', label: 'Voy en camino', icon: Truck },
        en_transito: { status: 'en_sitio', label: 'Llegué al sitio', icon: MapPin },
    }[ticket.status];

    return (
        <Card className="overflow-hidden">
            <Link to={`/tickets/${ticket.id}`} className="block p-4 active:bg-surface">
                <div className="flex items-center justify-between gap-2">
                    <span className="font-display text-lg font-extrabold text-ink tabular">{ticket.number}</span>
                    <StatusBadge status={ticket.status} size="sm" />
                </div>
                <p className="mt-2 font-semibold text-ink">{ticket.atm.agency_name}</p>
                <p className="text-sm text-muted">
                    ATM {ticket.atm.atm_number}{ticket.atm.state && ` · ${ticket.atm.state}`}
                </p>
                {ticket.fault_type && <p className="mt-2 text-sm text-body"><b className="text-ink">Falla:</b> {ticket.fault_type}</p>}
                <div className="mt-2 flex items-center justify-between text-xs text-muted">
                    <Elapsed ticket={ticket} />
                    <span className="inline-flex items-center text-brand-600">Ver detalle <ChevronRight className="size-4" /></span>
                </div>
            </Link>
            <div className="flex gap-2 border-t border-line bg-surface/60 p-3">
                <Button as="a" href={mapsUrl(ticket.atm)} target="_blank" rel="noreferrer" variant="secondary" icon={Navigation} className="flex-1 px-3">Cómo llegar</Button>
                {next ? (
                    <Button icon={next.icon} className="flex-1 px-3" loading={move.isPending} onClick={() => move.mutate(next.status)}>{next.label}</Button>
                ) : (
                    <Button as={Link} to={`/tickets/${ticket.id}?atender=1`} icon={ClipboardCheck} className="flex-1">Registrar atención</Button>
                )}
            </div>
        </Card>
    );
}

export default function TechnicianHome() {
    const { user } = useAuth();
    const { data, isLoading } = useQuery({
        queryKey: ['my-tickets'],
        queryFn: () => api('tickets', { query: { open: 1, per_page: 100 } }),
        refetchInterval: 30000,
    });

    const tickets = [...(data?.data ?? [])].sort((a, b) => (ORDER[a.status] ?? 9) - (ORDER[b.status] ?? 9));
    const active = tickets.filter((t) => !t.status.startsWith('pendiente'));
    const pending = tickets.filter((t) => t.status.startsWith('pendiente'));
    const hour = new Date().getHours();

    return (
        <div className="mx-auto max-w-2xl">
            <div className="mb-6">
                <p className="text-sm text-muted">{hour < 12 ? 'Buenos días' : hour < 19 ? 'Buenas tardes' : 'Buenas noches'},</p>
                <h1 className="text-2xl font-extrabold">{user.name.split(' ')[0]}</h1>
            </div>

            {isLoading ? (
                <LoadingBlock />
            ) : !tickets.length ? (
                <Card>
                    <EmptyState icon={PartyPopper} title="No tiene tickets pendientes" description="Cuando el Call Center le asigne una llamada aparecerá aquí." />
                </Card>
            ) : (
                <div className="space-y-8">
                    <section>
                        <h2 className="mb-3 text-sm font-bold tracking-wide text-muted uppercase">Por atender ({active.length})</h2>
                        <div className="space-y-3">
                            {active.length ? active.map((t) => <TicketCard key={t.id} ticket={t} />) : <p className="text-sm text-muted">Nada por atender ahora.</p>}
                        </div>
                    </section>
                    {pending.length > 0 && (
                        <section>
                            <h2 className="mb-3 text-sm font-bold tracking-wide text-muted uppercase">Pendientes ({pending.length})</h2>
                            <div className="space-y-3">{pending.map((t) => <TicketCard key={t.id} ticket={t} />)}</div>
                        </section>
                    )}
                </div>
            )}
        </div>
    );
}
