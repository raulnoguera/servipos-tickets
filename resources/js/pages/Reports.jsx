import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Download, FileSpreadsheet, Package } from 'lucide-react';
import { api, download } from '../lib/api';
import { useCatalogs } from '../lib/queries';
import { formatNumber } from '../lib/format';
import { Alert, Button, Card, CardHeader, EmptyState, Field, Input, LoadingBlock, PageHeader, Select, Table, Td, Th, toast } from '../components/ui';
import { HBarChart } from '../components/Charts';

const thisMonth = () => new Date().toISOString().slice(0, 7);
const monthBounds = (ym) => {
    const [y, m] = ym.split('-').map(Number);
    const last = new Date(y, m, 0).getDate();
    return [`${ym}-01`, `${ym}-${String(last).padStart(2, '0')}`];
};
const monthLabel = (ym) => new Date(`${ym}-01T00:00:00`).toLocaleDateString('es-VE', { month: 'long', year: 'numeric' });

function ExportCard() {
    const { data: catalogs } = useCatalogs();
    const [[from, to], setRange] = useState(monthBounds(thisMonth()));
    const [bank, setBank] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);

    async function run() {
        setBusy(true);
        setError(null);
        try {
            await download('reports/consolidado', { from, to, bank_id: bank });
            toast('Consolidado descargado');
        } catch (e) {
            setError(e.message);
        } finally {
            setBusy(false);
        }
    }

    return (
        <Card>
            <CardHeader title="Exportar consolidado a Excel" subtitle="Mismas columnas del “Libro Consolidado Relación de Atenciones”, una fila por visita, más el N° de ticket." actions={<FileSpreadsheet className="size-5 text-ok" />} />
            <div className="grid gap-4 p-5 sm:grid-cols-[1fr_1fr_1fr_auto] sm:items-end">
                <Field label="Desde">{(id) => <Input id={id} type="date" value={from} onChange={(e) => setRange([e.target.value, to])} />}</Field>
                <Field label="Hasta">{(id) => <Input id={id} type="date" value={to} onChange={(e) => setRange([from, e.target.value])} />}</Field>
                <Field label="Banco">
                    {(id) => (
                        <Select id={id} value={bank} onChange={(e) => setBank(e.target.value)}>
                            <option value="">Todos</option>
                            {catalogs?.banks.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                        </Select>
                    )}
                </Field>
                <Button icon={Download} loading={busy} onClick={run} variant="success">Descargar .xlsx</Button>
                {error && <Alert className="sm:col-span-4">{error}</Alert>}
            </div>
        </Card>
    );
}

export default function Reports() {
    const { data: catalogs } = useCatalogs();
    const [month, setMonth] = useState(thisMonth());
    const [bank, setBank] = useState('');
    const { data, isLoading } = useQuery({
        queryKey: ['report-parts', month, bank],
        queryFn: () => api('reports/parts', { query: { month, bank_id: bank } }),
    });

    return (
        <>
            <PageHeader
                title="Reportes"
                subtitle="Consumo de repuestos y exportación del consolidado"
                actions={
                    <>
                        <Input type="month" value={month} onChange={(e) => e.target.value && setMonth(e.target.value)} className="w-44" aria-label="Mes" />
                        <Select value={bank} onChange={(e) => setBank(e.target.value)} className="w-52" aria-label="Banco">
                            <option value="">Todos los bancos</option>
                            {catalogs?.banks.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                        </Select>
                    </>
                }
            />

            <div className="space-y-6">
                <ExportCard />

                {isLoading ? (
                    <LoadingBlock />
                ) : (
                    <>
                        <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                            {[
                                ['Repuestos utilizados', formatNumber(data.total), 'unidades'],
                                ['Tipos de repuesto', data.by_part.length, 'distintos'],
                                ['Visitas del mes', data.visits, 'atenciones en sitio'],
                                ['Visitas con cambio', data.visits_with_parts, data.visits ? `${Math.round((data.visits_with_parts / data.visits) * 100)} % del total` : '—'],
                            ].map(([label, value, detail]) => (
                                <Card key={label} className="p-5">
                                    <p className="text-sm font-semibold text-muted">{label}</p>
                                    <p className="mt-2 font-display text-3xl font-extrabold text-ink tabular">{value}</p>
                                    <p className="mt-1 text-xs text-muted">{detail}</p>
                                </Card>
                            ))}
                        </div>

                        {!data.by_part.length ? (
                            <Card><EmptyState icon={Package} title={`Sin consumo de repuestos en ${monthLabel(month)}`} description="Elija otro mes para ver el consolidado." /></Card>
                        ) : (
                            <div className="grid gap-6 xl:grid-cols-2">
                                <Card>
                                    <CardHeader title="Consumo por tipo de repuesto" subtitle={`Unidades en ${monthLabel(month)}`} />
                                    <div className="px-4 py-3">
                                        <HBarChart data={data.by_part} labelKey="part" valueKey="quantity" unit="Unidades" />
                                    </div>
                                </Card>
                                <Card>
                                    <CardHeader title="Consumo por modelo de ATM" subtitle="Detalle de repuestos por modelo" />
                                    <Table>
                                        <thead><tr><Th>Modelo</Th><Th>Repuestos</Th><Th className="text-right">Unidades</Th></tr></thead>
                                        <tbody>
                                            {data.by_model.map((m) => (
                                                <tr key={m.model}>
                                                    <Td className="font-semibold whitespace-nowrap text-ink">{m.model}</Td>
                                                    <Td className="text-sm">{m.parts.map((p) => `${p.part} (${p.quantity})`).join(', ')}</Td>
                                                    <Td className="text-right font-bold text-ink tabular">{m.quantity}</Td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </Table>
                                </Card>
                            </div>
                        )}
                    </>
                )}
            </div>
        </>
    );
}
