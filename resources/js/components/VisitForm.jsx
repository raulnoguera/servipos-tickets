import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Camera, CheckCircle2, ImagePlus, Minus, Plus, Trash2 } from 'lucide-react';
import { api } from '../lib/api';
import { useCatalogs } from '../lib/queries';
import { compressImage, formatBytes } from '../lib/compressImage';
import { Alert, Button, Field, Input, Segmented, Select, StatusBadge, Textarea, cx, toast } from './ui';

const newUuid = () => (crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random()}`.replace(/\./g, ''));

/** Registro de la atención en sitio (RF-3.1 a RF-3.3). */
export default function VisitForm({ ticket, onDone }) {
    const { data: catalogs } = useCatalogs();
    const queryClient = useQueryClient();
    const [uuid] = useState(newUuid);
    const [form, setForm] = useState({
        constancia_number: '',
        fault_type_id: ticket.fault_type_id ? String(ticket.fault_type_id) : '',
        work_done: '',
        atm_condition_id: '',
        parts_changed: null,
    });
    const [parts, setParts] = useState([{ part_id: '', quantity: 1 }]);
    const [photo, setPhoto] = useState(null);
    const [photoInfo, setPhotoInfo] = useState(null);
    const [compressing, setCompressing] = useState(false);

    const set = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.value }));

    async function pickPhoto(e) {
        const file = e.target.files?.[0];
        if (!file) {
            return;
        }
        setCompressing(true);
        const small = await compressImage(file);
        setPhoto(small);
        setPhotoInfo({ before: file.size, after: small.size, url: URL.createObjectURL(small) });
        setCompressing(false);
    }

    const save = useMutation({
        mutationFn: () => {
            const body = new FormData();
            body.append('client_uuid', uuid);
            Object.entries(form).forEach(([k, v]) => v !== null && v !== '' && body.append(k, k === 'parts_changed' ? (v ? '1' : '0') : v));
            if (form.parts_changed) {
                parts.filter((p) => p.part_id).forEach((p, i) => {
                    body.append(`parts[${i}][part_id]`, p.part_id);
                    body.append(`parts[${i}][quantity]`, p.quantity);
                });
            }
            if (photo) {
                body.append('photo', photo);
            }
            return api(`tickets/${ticket.id}/visits`, { method: 'POST', body });
        },
        onSuccess: (r) => {
            queryClient.setQueryData(['ticket', String(ticket.id)], r);
            queryClient.invalidateQueries({ queryKey: ['tickets'] });
            queryClient.invalidateQueries({ queryKey: ['my-tickets'] });
            queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            toast(`Atención registrada · ${r.data.status_label}`);
            onDone?.(r.data);
        },
    });
    const err = save.error;
    const partError = err && Object.entries(err.errors).find(([k]) => k.startsWith('parts'))?.[1]?.[0];

    const conditions = catalogs?.atm_conditions.filter((c) => c.active) ?? [];
    const usedParts = new Set(parts.map((p) => p.part_id));

    return (
        <form
            className="space-y-6"
            onSubmit={(e) => {
                e.preventDefault();
                save.mutate();
            }}
        >
            {/* Constancia */}
            <section className="space-y-4">
                <h3 className="text-sm font-bold tracking-wide text-muted uppercase">Constancia de servicio</h3>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="N° de constancia" hint="El número impreso en la planilla" error={err?.field?.('constancia_number')}>
                        {(id) => <Input id={id} value={form.constancia_number} onChange={set('constancia_number')} inputMode="numeric" className="tabular" />}
                    </Field>
                    <Field label="Falla atendida" error={err?.field?.('fault_type_id')}>
                        {(id) => (
                            <Select id={id} value={form.fault_type_id} onChange={set('fault_type_id')}>
                                <option value="">La reportada en el ticket</option>
                                {catalogs?.fault_types.filter((f) => f.active).map((f) => <option key={f.id} value={f.id}>{f.name}</option>)}
                            </Select>
                        )}
                    </Field>
                </div>

                <label
                    className={cx(
                        'flex cursor-pointer items-center gap-4 rounded-xl border-2 border-dashed p-4 transition',
                        photo ? 'border-emerald-300 bg-emerald-50/50' : 'border-line hover:border-brand-300 hover:bg-brand-50/40',
                        err?.field?.('photo') && 'border-red-300',
                    )}
                >
                    <input type="file" accept="image/*" capture="environment" className="sr-only" onChange={pickPhoto} />
                    {photoInfo ? (
                        <img src={photoInfo.url} alt="Constancia" className="size-20 shrink-0 rounded-lg object-cover ring-1 ring-line" />
                    ) : (
                        <div className="grid size-20 shrink-0 place-items-center rounded-lg bg-brand-50 text-brand-500">
                            <Camera className="size-8" />
                        </div>
                    )}
                    <div className="min-w-0 text-sm">
                        <p className="font-semibold text-ink">{photo ? 'Foto lista' : compressing ? 'Optimizando foto…' : 'Tomar foto de la planilla firmada'}</p>
                        {photoInfo ? (
                            <p className="text-muted">
                                {formatBytes(photoInfo.before)} → <b className="text-emerald-700">{formatBytes(photoInfo.after)}</b> · toque para cambiarla
                            </p>
                        ) : (
                            <p className="text-muted">Abre la cámara del teléfono. En computadora puede elegir un archivo.</p>
                        )}
                        {err?.field?.('photo') && <p className="mt-1 text-red-600">{err.field('photo')}</p>}
                    </div>
                    <ImagePlus className="ml-auto hidden size-5 shrink-0 text-faint sm:block" />
                </label>
            </section>

            {/* Trabajo y repuestos */}
            <section className="space-y-4">
                <h3 className="text-sm font-bold tracking-wide text-muted uppercase">Trabajo realizado</h3>
                <Field error={err?.field?.('work_done')}>
                    {(id) => <Textarea id={id} value={form.work_done} onChange={set('work_done')} required placeholder="Describa el diagnóstico y lo que se hizo en sitio" aria-label="Trabajo realizado" />}
                </Field>

                <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-surface p-4">
                    <div>
                        <p className="text-sm font-semibold text-ink">¿Hubo cambio de repuesto?<span className="ml-0.5 text-red-500">*</span></p>
                        <p className="text-xs text-muted">Si responde Sí, debe indicar cuál y cuántos.</p>
                    </div>
                    <Segmented
                        options={[{ value: true, label: 'Sí' }, { value: false, label: 'No' }]}
                        value={form.parts_changed}
                        onChange={(v) => setForm((f) => ({ ...f, parts_changed: v }))}
                    />
                </div>

                {form.parts_changed && (
                    <div className="space-y-2">
                        {parts.map((p, i) => (
                            <div key={i} className="flex items-center gap-2">
                                <Select
                                    value={p.part_id}
                                    onChange={(e) => setParts((list) => list.map((x, j) => (j === i ? { ...x, part_id: e.target.value } : x)))}
                                    className="flex-1"
                                    aria-label={`Repuesto ${i + 1}`}
                                    required
                                >
                                    <option value="">Seleccione el repuesto…</option>
                                    {catalogs?.parts.filter((c) => c.active && (!usedParts.has(String(c.id)) || String(c.id) === p.part_id)).map((c) => (
                                        <option key={c.id} value={c.id}>{c.name}</option>
                                    ))}
                                </Select>
                                <div className="flex h-10 items-center rounded-lg border border-line bg-white">
                                    <button type="button" className="grid h-full w-9 place-items-center text-muted hover:text-ink" aria-label="Menos" onClick={() => setParts((list) => list.map((x, j) => (j === i ? { ...x, quantity: Math.max(1, x.quantity - 1) } : x)))}>
                                        <Minus className="size-4" />
                                    </button>
                                    <span className="w-8 text-center text-sm font-bold text-ink tabular" aria-label="Cantidad">{p.quantity}</span>
                                    <button type="button" className="grid h-full w-9 place-items-center text-muted hover:text-ink" aria-label="Más" onClick={() => setParts((list) => list.map((x, j) => (j === i ? { ...x, quantity: x.quantity + 1 } : x)))}>
                                        <Plus className="size-4" />
                                    </button>
                                </div>
                                <button type="button" className="rounded-lg p-2 text-faint hover:bg-red-50 hover:text-red-600 disabled:opacity-30" disabled={parts.length === 1} onClick={() => setParts((list) => list.filter((_, j) => j !== i))} aria-label="Quitar repuesto">
                                    <Trash2 className="size-4" />
                                </button>
                            </div>
                        ))}
                        <Button type="button" variant="ghost" size="sm" icon={Plus} onClick={() => setParts((list) => [...list, { part_id: '', quantity: 1 }])}>
                            Agregar otro repuesto
                        </Button>
                        {partError && <p className="text-sm text-red-600">{partError}</p>}
                    </div>
                )}
                {err?.field?.('parts_changed') && <p className="text-sm text-red-600">Indique si hubo cambio de repuesto.</p>}
            </section>

            {/* Estatus final */}
            <section className="space-y-3">
                <h3 className="text-sm font-bold tracking-wide text-muted uppercase">¿Cómo queda el cajero?<span className="ml-0.5 text-red-500">*</span></h3>
                <div className="grid gap-2 sm:grid-cols-2">
                    {conditions.map((c) => {
                        const selected = form.atm_condition_id === String(c.id);
                        return (
                            <label
                                key={c.id}
                                className={cx(
                                    'flex cursor-pointer items-start gap-3 rounded-xl border p-3.5 transition',
                                    selected ? 'border-brand-500 bg-brand-50 ring-2 ring-brand-500/20' : 'border-line hover:border-brand-200',
                                )}
                            >
                                <input type="radio" name="atm_condition_id" value={c.id} checked={selected} onChange={set('atm_condition_id')} className="mt-0.5 size-4 accent-brand-500" required />
                                <span className="min-w-0">
                                    <span className="block text-sm font-semibold text-ink">{c.name}</span>
                                    <span className="mt-1 flex items-center gap-1.5 text-xs text-muted">
                                        El ticket pasa a <StatusBadge status={c.resulting_ticket_status} size="sm" />
                                    </span>
                                </span>
                            </label>
                        );
                    })}
                </div>
                {err?.field?.('atm_condition_id') && <p className="text-sm text-red-600">{err.field('atm_condition_id')}</p>}
            </section>

            {err && err.field?.('status') && <Alert>{err.field('status')}</Alert>}
            {err && !Object.keys(err.errors).length && <Alert>{err.message}</Alert>}

            <div className="sticky bottom-0 -mx-5 flex justify-end gap-2 border-t border-line bg-white px-5 py-4">
                <Button type="submit" size="lg" icon={CheckCircle2} loading={save.isPending || compressing} disabled={form.parts_changed === null} className="w-full sm:w-auto">
                    Registrar atención
                </Button>
            </div>
        </form>
    );
}
