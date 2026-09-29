import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { AlertTriangle, Building2, CheckCircle2, MapPin, MessageCircle, Plus, Search } from 'lucide-react';
import { api } from '../lib/api';
import { useCatalogs } from '../lib/queries';
import { mapsUrl } from '../lib/format';
import { Alert, Button, Card, CardHeader, Field, Input, PageHeader, Select, Spinner, Textarea, toast } from '../components/ui';

function AtmCard({ atm }) {
    const rows = [
        ['Serial', atm.serial],
        ['Código de agencia', atm.agency_code],
        ['Estado', atm.state],
        ['Marca / modelo', [atm.brand, atm.model].filter(Boolean).join(' ') || null],
        ['Tipo', atm.site_type],
        ['Banco', atm.bank],
    ];
    return (
        <div className="rounded-xl border border-brand-200 bg-brand-50/60 p-4">
            <div className="flex items-start gap-3">
                <div className="grid size-10 shrink-0 place-items-center rounded-lg bg-brand-500 text-white">
                    <Building2 className="size-5" />
                </div>
                <div className="min-w-0">
                    <p className="text-xs font-semibold tracking-wide text-brand-700 uppercase">ATM {atm.atm_number}</p>
                    <p className="font-display text-lg font-bold text-ink">{atm.agency_name}</p>
                    {atm.address && <p className="text-sm text-muted">{atm.address}</p>}
                </div>
            </div>
            <dl className="mt-4 grid grid-cols-2 gap-x-4 gap-y-2.5 text-sm">
                {rows.map(([label, value]) => (
                    <div key={label}>
                        <dt className="text-xs text-muted">{label}</dt>
                        <dd className="font-semibold text-ink">{value || <span className="font-normal text-faint">Sin dato</span>}</dd>
                    </div>
                ))}
            </dl>
            <a href={mapsUrl(atm)} target="_blank" rel="noreferrer" className="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold text-brand-600 hover:underline">
                <MapPin className="size-4" /> Ver en el mapa
            </a>
        </div>
    );
}

export default function TicketNew() {
    const { data: catalogs } = useCatalogs();
    const queryClient = useQueryClient();
    const [number, setNumber] = useState('');
    const [atm, setAtm] = useState(null);
    const [lookup, setLookup] = useState({ loading: false, error: null });
    const [form, setForm] = useState({ fault_type_id: '', bank_reference: '', description: '', assigned_to: '' });
    const [created, setCreated] = useState(null);

    const set = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.value }));

    async function findAtm(e) {
        e?.preventDefault();
        if (!number.trim()) {
            return;
        }
        setLookup({ loading: true, error: null });
        try {
            const r = await api(`atms/lookup/${encodeURIComponent(number.trim())}`);
            setAtm(r.data);
            setLookup({ loading: false, error: null });
        } catch (err) {
            setAtm(null);
            setLookup({ loading: false, error: err.status === 404 ? `No existe el ATM "${number.trim().toUpperCase()}" en el maestro de cajeros.` : err.message });
        }
    }

    // Técnicos que cubren el estado del cajero, primero.
    const technicians = useMemo(() => {
        const list = catalogs?.technicians ?? [];
        if (!atm?.state_id) {
            return { suggested: [], others: list };
        }
        return {
            suggested: list.filter((t) => t.state_ids.includes(atm.state_id)),
            others: list.filter((t) => !t.state_ids.includes(atm.state_id)),
        };
    }, [catalogs, atm]);

    const create = useMutation({
        mutationFn: () => api('tickets', { method: 'POST', body: { ...form, atm_id: atm.id, assigned_to: form.assigned_to || null } }),
        onSuccess: (r) => {
            setCreated(r.data);
            queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            queryClient.invalidateQueries({ queryKey: ['tickets'] });
            toast(`Ticket ${r.data.number} creado`);
        },
    });
    const err = create.error;

    function reset() {
        setCreated(null);
        setAtm(null);
        setNumber('');
        setForm({ fault_type_id: '', bank_reference: '', description: '', assigned_to: '' });
        create.reset();
    }

    if (created) {
        return (
            <div className="mx-auto max-w-xl pt-6">
                <Card className="p-8 text-center">
                    <div className="mx-auto mb-4 grid size-14 place-items-center rounded-full bg-emerald-50 text-emerald-600">
                        <CheckCircle2 className="size-8" />
                    </div>
                    <p className="text-sm font-semibold text-muted">Ticket creado</p>
                    <p className="mt-1 font-display text-4xl font-extrabold tracking-tight text-ink tabular">{created.number}</p>
                    <p className="mt-2 text-sm text-body">
                        ATM {created.atm.atm_number} · {created.atm.agency_name}
                        {created.technician && <> · asignado a <b>{created.technician.name}</b></>}
                    </p>
                    <div className="mt-6 flex flex-col gap-2 sm:flex-row sm:justify-center">
                        {created.whatsapp_url && (
                            <Button as="a" href={created.whatsapp_url} target="_blank" rel="noreferrer" variant="whatsapp" icon={MessageCircle}>
                                Avisar por WhatsApp
                            </Button>
                        )}
                        <Button as={Link} to={`/tickets/${created.id}`} variant="secondary">Ver ticket</Button>
                        <Button variant="ghost" icon={Plus} onClick={reset}>Abrir otro</Button>
                    </div>
                    {created.technician && !created.whatsapp_url && (
                        <p className="mt-4 text-xs text-muted">El técnico no tiene teléfono registrado; agréguelo en Usuarios para avisarle por WhatsApp.</p>
                    )}
                </Card>
            </div>
        );
    }

    return (
        <>
            <PageHeader title="Nuevo ticket" subtitle="Registre la falla reportada por el banco. Escriba el N° de ATM y los datos del cajero se completan solos." />

            <div className="grid gap-6 lg:grid-cols-[1fr_380px]">
                <Card>
                    <CardHeader title="1. Cajero" />
                    <div className="space-y-4 p-5">
                        <form onSubmit={findAtm} className="flex gap-2">
                            <div className="relative flex-1">
                                <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-faint" />
                                <Input
                                    value={number}
                                    onChange={(e) => setNumber(e.target.value.toUpperCase())}
                                    onBlur={() => number && !atm && findAtm()}
                                    placeholder="N° de ATM, ej. A1918035"
                                    className="h-11 pl-9 text-base font-semibold tracking-wide tabular"
                                    autoFocus
                                    aria-label="Número de ATM"
                                />
                            </div>
                            <Button type="submit" size="lg" variant="secondary" loading={lookup.loading}>Buscar</Button>
                        </form>
                        {lookup.error && <Alert tone="warn">{lookup.error}</Alert>}
                        {atm && atm.open_tickets > 0 && (
                            <Alert tone="warn" className="flex items-start gap-2">
                                <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                                <span>Este cajero ya tiene {atm.open_tickets} ticket(s) sin cerrar. <Link className="font-semibold underline" to={`/tickets?q=${atm.atm_number}&filtro=todos`}>Revisarlos</Link> antes de abrir otro.</span>
                            </Alert>
                        )}
                    </div>

                    <CardHeader title="2. Falla reportada" className="border-t" />
                    <form
                        className="grid gap-4 p-5 sm:grid-cols-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            create.mutate();
                        }}
                    >
                        <Field label="Tipo de falla" required error={err?.field?.('fault_type_id')}>
                            {(id) => (
                                <Select id={id} value={form.fault_type_id} onChange={set('fault_type_id')} required>
                                    <option value="">Seleccione…</option>
                                    {catalogs?.fault_types.filter((f) => f.active).map((f) => <option key={f.id} value={f.id}>{f.name}</option>)}
                                </Select>
                            )}
                        </Field>
                        <Field label="N° de caso del banco" hint="Opcional, si el correo lo trae" error={err?.field?.('bank_reference')}>
                            {(id) => <Input id={id} value={form.bank_reference} onChange={set('bank_reference')} maxLength={60} />}
                        </Field>
                        <Field label="Detalle del reporte" className="sm:col-span-2" error={err?.field?.('description')}>
                            {(id) => <Textarea id={id} value={form.description} onChange={set('description')} placeholder="Copie aquí lo que indica el correo del banco" />}
                        </Field>
                        <Field label="Asignar a técnico" className="sm:col-span-2" hint="Puede dejarlo sin asignar y hacerlo después" error={err?.field?.('assigned_to')}>
                            {(id) => (
                                <Select id={id} value={form.assigned_to} onChange={set('assigned_to')}>
                                    <option value="">Sin asignar por ahora</option>
                                    {technicians.suggested.length > 0 && (
                                        <optgroup label={`Cubren ${atm.state}`}>
                                            {technicians.suggested.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                                        </optgroup>
                                    )}
                                    <optgroup label={technicians.suggested.length ? 'Otros técnicos' : 'Técnicos'}>
                                        {technicians.others.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                                    </optgroup>
                                </Select>
                            )}
                        </Field>
                        {err && !Object.keys(err.errors).length && <Alert className="sm:col-span-2">{err.message}</Alert>}
                        <div className="flex justify-end sm:col-span-2">
                            <Button type="submit" size="lg" loading={create.isPending} disabled={!atm} icon={Plus}>
                                Crear ticket
                            </Button>
                        </div>
                    </form>
                </Card>

                <div className="lg:sticky lg:top-8 lg:self-start">
                    {atm ? (
                        <AtmCard atm={atm} />
                    ) : (
                        <Card className="flex flex-col items-center p-8 text-center">
                            {lookup.loading ? <Spinner /> : <Building2 className="size-10 text-brand-200" />}
                            <p className="mt-3 font-semibold text-ink">Datos del cajero</p>
                            <p className="mt-1 text-sm text-muted">Serial, agencia, estado y modelo aparecerán aquí al ingresar el N° de ATM.</p>
                        </Card>
                    )}
                </div>
            </div>
        </>
    );
}
