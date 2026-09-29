import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { AlertTriangle, CheckCircle2, FileSpreadsheet, FlaskConical, Upload, Wrench } from 'lucide-react';
import { api } from '../lib/api';
import { Alert, Badge, Button, Card, CardHeader, Field, Input, PageHeader, cx, toast } from '../components/ui';

const LEVELS = {
    ERROR: { label: 'Errores', icon: AlertTriangle, tone: 'danger' },
    AVISO: { label: 'Avisos para revisar', icon: AlertTriangle, tone: 'warn' },
    CORREGIDO: { label: 'Corregido automáticamente', icon: Wrench, tone: 'ok' },
};

export default function Import() {
    const queryClient = useQueryClient();
    const [file, setFile] = useState(null);
    const [bank, setBank] = useState('');
    const [report, setReport] = useState(null);

    const run = useMutation({
        mutationFn: (simulate) => {
            const body = new FormData();
            body.append('file', file);
            body.append('bank', bank);
            body.append('simulate', simulate ? '1' : '0');
            return api('import/excel', { method: 'POST', body });
        },
        onSuccess: (r) => {
            setReport(r);
            if (!r.simulated) {
                queryClient.invalidateQueries();
                toast('Histórico importado');
            }
        },
    });
    const err = run.error;

    return (
        <>
            <PageHeader title="Importar Excel histórico" subtitle="Carga el “Libro Consolidado” (pestañas Tablas y Base General): cajeros, catálogos, técnicos y atenciones." />
            <div className="grid gap-6 lg:grid-cols-[420px_1fr]">
                <Card className="self-start">
                    <CardHeader title="Archivo" />
                    <div className="space-y-4 p-5">
                        <label className={cx('flex cursor-pointer flex-col items-center rounded-xl border-2 border-dashed p-6 text-center transition', file ? 'border-emerald-300 bg-emerald-50/50' : 'border-line hover:border-brand-300')}>
                            <input type="file" accept=".xlsx" className="sr-only" onChange={(e) => { setFile(e.target.files?.[0] ?? null); setReport(null); }} />
                            <FileSpreadsheet className={cx('size-10', file ? 'text-ok' : 'text-brand-300')} />
                            <span className="mt-2 text-sm font-semibold text-ink">{file ? file.name : 'Seleccione el archivo .xlsx'}</span>
                            <span className="text-xs text-muted">{file ? `${Math.round(file.size / 1024)} KB` : 'Máximo 20 MB'}</span>
                        </label>
                        {err?.field?.('file') && <Alert>{err.field('file')}</Alert>}
                        <Field label="Banco de estos cajeros" required hint="El Excel no indica el banco; se asigna a todos los cajeros del archivo." error={err?.field?.('bank')}>
                            {(id) => <Input id={id} value={bank} onChange={(e) => setBank(e.target.value)} placeholder="Ej. Banco Nacional de Crédito" />}
                        </Field>
                        <div className="grid gap-2 sm:grid-cols-2">
                            <Button variant="secondary" icon={FlaskConical} disabled={!file || !bank} loading={run.isPending && run.variables} onClick={() => run.mutate(true)}>Simular</Button>
                            <Button icon={Upload} disabled={!file || !bank || !report?.simulated || report.has_errors} loading={run.isPending && !run.variables} onClick={() => run.mutate(false)}>Importar</Button>
                        </div>
                        <p className="text-xs text-muted">Primero simule: valida el archivo y muestra qué se corregirá, sin guardar nada. La importación real solo se habilita después de una simulación sin errores y puede hacerse una sola vez.</p>
                    </div>
                </Card>

                <Card>
                    <CardHeader
                        title="Reporte"
                        subtitle={report ? (report.simulated ? 'Simulación: no se guardó nada' : 'Importación completada') : 'Aparecerá aquí después de simular'}
                        actions={report && <Badge tone={report.simulated ? 'brand' : 'ok'}>{report.simulated ? 'Simulación' : 'Guardado'}</Badge>}
                    />
                    {report ? (
                        <div className="space-y-6 p-5">
                            <dl className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                                {Object.entries(report.counts).map(([k, v]) => (
                                    <div key={k} className="rounded-lg bg-surface p-3">
                                        <dt className="text-xs text-muted">{k}</dt>
                                        <dd className="font-display text-2xl font-extrabold text-ink tabular">{v}</dd>
                                    </div>
                                ))}
                            </dl>
                            {Object.entries(LEVELS).map(([level, meta]) => {
                                const notes = report.notes.filter((n) => n.level === level);
                                if (!notes.length) {
                                    return null;
                                }
                                return (
                                    <div key={level}>
                                        <h3 className="mb-2 flex items-center gap-2 text-sm font-bold text-ink">
                                            <meta.icon className="size-4" /> {meta.label} <Badge tone={meta.tone}>{notes.length}</Badge>
                                        </h3>
                                        <ul className="max-h-72 divide-y divide-line overflow-y-auto rounded-lg border border-line text-sm">
                                            {notes.map((n, i) => (
                                                <li key={i} className="flex gap-3 px-3 py-2">
                                                    <span className="shrink-0 text-xs font-semibold text-muted tabular">{n.where}</span>
                                                    <span className="text-body">{n.message}</span>
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                );
                            })}
                            {!report.has_errors && report.simulated && (
                                <Alert tone="ok" className="flex items-center gap-2"><CheckCircle2 className="size-4" /> Sin errores. Puede importar.</Alert>
                            )}
                        </div>
                    ) : (
                        <div className="p-10 text-center text-sm text-muted">Seleccione el archivo, indique el banco y presione “Simular”.</div>
                    )}
                </Card>
            </div>
        </>
    );
}
