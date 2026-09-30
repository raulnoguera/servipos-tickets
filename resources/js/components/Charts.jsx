import {
    Bar, BarChart, CartesianGrid, LabelList, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis,
} from 'recharts';
import { formatNumber } from '../lib/format';

/* Colores validados (daltonismo y contraste) sobre fondo blanco. */
export const SERIES = { opened: '#0055ff', closed: '#219653', single: '#0055ff' };
const GRID = '#eef1f7';
const AXIS = '#687799';

const shortDay = (iso) => new Date(`${iso}T00:00:00`).toLocaleDateString('es-VE', { day: '2-digit', month: 'short' });

function TooltipBox({ active, payload, label, labelFormatter }) {
    if (!active || !payload?.length) {
        return null;
    }
    return (
        <div className="rounded-lg border border-line bg-white px-3 py-2 text-xs shadow-lg">
            <div className="mb-1 font-semibold text-ink">{labelFormatter ? labelFormatter(label) : label}</div>
            {payload.map((p) => (
                <div key={p.dataKey} className="flex items-center gap-2 text-body">
                    <span className="inline-block h-0.5 w-3 rounded" style={{ background: p.color }} />
                    <span>{p.name}</span>
                    <span className="ml-auto pl-3 font-semibold text-ink tabular">{formatNumber(p.value)}</span>
                </div>
            ))}
        </div>
    );
}

/** Tickets abiertos vs. cerrados por día (dos series → leyenda + tooltip con cruz). */
export function DailyChart({ data }) {
    return (
        <ResponsiveContainer width="100%" height={260}>
            <LineChart data={data} margin={{ top: 8, right: 12, bottom: 0, left: -18 }}>
                <CartesianGrid stroke={GRID} vertical={false} />
                <XAxis dataKey="date" tickFormatter={shortDay} tick={{ fill: AXIS, fontSize: 11 }} tickLine={false} axisLine={{ stroke: GRID }} minTickGap={24} />
                <YAxis allowDecimals={false} tick={{ fill: AXIS, fontSize: 11 }} tickLine={false} axisLine={false} />
                <Tooltip content={<TooltipBox labelFormatter={shortDay} />} cursor={{ stroke: '#b6c1db', strokeWidth: 1 }} />
                <Legend verticalAlign="top" align="right" height={28} iconType="plainline" iconSize={14} wrapperStyle={{ fontSize: 12, color: AXIS }} />
                <Line type="linear" dataKey="opened" name="Abiertos" stroke={SERIES.opened} strokeWidth={2} dot={false} activeDot={{ r: 4.5, stroke: '#fff', strokeWidth: 2 }} strokeLinecap="round" />
                <Line type="linear" dataKey="closed" name="Concluidos" stroke={SERIES.closed} strokeWidth={2} dot={false} activeDot={{ r: 4.5, stroke: '#fff', strokeWidth: 2 }} strokeLinecap="round" />
            </LineChart>
        </ResponsiveContainer>
    );
}

/** Barras horizontales de una sola serie con el valor en la punta. */
export function HBarChart({ data, labelKey, valueKey, unit = '', color = SERIES.single }) {
    const height = Math.max(120, data.length * 34 + 16);
    return (
        <ResponsiveContainer width="100%" height={height}>
            <BarChart data={data} layout="vertical" margin={{ top: 4, right: 40, bottom: 4, left: 0 }} barCategoryGap={8}>
                <XAxis type="number" hide allowDecimals={false} />
                <YAxis type="category" dataKey={labelKey} width={150} tick={{ fill: '#3d4a66', fontSize: 12 }} tickLine={false} axisLine={false} />
                <Tooltip content={<TooltipBox />} cursor={{ fill: '#f1f5ff' }} />
                <Bar dataKey={valueKey} name={unit || 'Total'} fill={color} maxBarSize={20} radius={[0, 4, 4, 0]}>
                    <LabelList dataKey={valueKey} position="right" style={{ fill: '#00194c', fontSize: 12, fontWeight: 600 }} />
                </Bar>
            </BarChart>
        </ResponsiveContainer>
    );
}

/* Paleta categórica validada (orden fijo) para series múltiples, p. ej. tendencia de repuestos. */
export const CATEGORICAL = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4'];

const monthLabel = (ym) => new Date(`${ym}-01T00:00:00`).toLocaleDateString('es-VE', { month: 'short', year: '2-digit' });
const weekLabel = (iso) => `Sem. ${new Date(`${iso}T00:00:00`).toLocaleDateString('es-VE', { day: '2-digit', month: 'short' })}`;

/** Abiertos vs. concluidos por semana (dos series, columnas agrupadas). */
export function WeeklyChart({ data }) {
    return (
        <ResponsiveContainer width="100%" height={240}>
            <BarChart data={data} margin={{ top: 8, right: 12, bottom: 0, left: -18 }} barGap={2} barCategoryGap="28%">
                <CartesianGrid stroke={GRID} vertical={false} />
                <XAxis dataKey="week" tickFormatter={weekLabel} tick={{ fill: AXIS, fontSize: 11 }} tickLine={false} axisLine={{ stroke: GRID }} />
                <YAxis allowDecimals={false} tick={{ fill: AXIS, fontSize: 11 }} tickLine={false} axisLine={false} />
                <Tooltip content={<TooltipBox labelFormatter={weekLabel} />} cursor={{ fill: '#f1f5ff' }} />
                <Legend verticalAlign="top" align="right" height={28} iconType="square" iconSize={10} wrapperStyle={{ fontSize: 12, color: AXIS }} />
                <Bar dataKey="opened" name="Abiertos" fill={SERIES.opened} maxBarSize={22} radius={[4, 4, 0, 0]} />
                <Bar dataKey="closed" name="Concluidos" fill={SERIES.closed} maxBarSize={22} radius={[4, 4, 0, 0]} />
            </BarChart>
        </ResponsiveContainer>
    );
}

/** Columnas de una sola serie con el valor encima (p. ej. antigüedad de pendientes). */
export function ColumnChart({ data, labelKey, valueKey, name = 'Total', color = SERIES.single, height = 220 }) {
    return (
        <ResponsiveContainer width="100%" height={height}>
            <BarChart data={data} margin={{ top: 22, right: 8, bottom: 0, left: -18 }} barCategoryGap="30%">
                <CartesianGrid stroke={GRID} vertical={false} />
                <XAxis dataKey={labelKey} tick={{ fill: AXIS, fontSize: 11 }} tickLine={false} axisLine={{ stroke: GRID }} interval={0} />
                <YAxis allowDecimals={false} tick={{ fill: AXIS, fontSize: 11 }} tickLine={false} axisLine={false} />
                <Tooltip content={<TooltipBox />} cursor={{ fill: '#f1f5ff' }} />
                <Bar dataKey={valueKey} name={name} fill={color} maxBarSize={48} radius={[4, 4, 0, 0]}>
                    <LabelList dataKey={valueKey} position="top" style={{ fill: '#00194c', fontSize: 12, fontWeight: 600 }} />
                </Bar>
            </BarChart>
        </ResponsiveContainer>
    );
}

/** Tendencia mensual de hasta 5 repuestos (líneas con leyenda; la tabla acompaña los valores). */
export function TrendChart({ months, series }) {
    const data = months.map((m, i) => Object.fromEntries([['month', m], ...series.map((s) => [s.name, s.values[i]])]));
    return (
        <ResponsiveContainer width="100%" height={260}>
            <LineChart data={data} margin={{ top: 8, right: 16, bottom: 0, left: -18 }}>
                <CartesianGrid stroke={GRID} vertical={false} />
                <XAxis dataKey="month" tickFormatter={monthLabel} tick={{ fill: AXIS, fontSize: 11 }} tickLine={false} axisLine={{ stroke: GRID }} />
                <YAxis allowDecimals={false} tick={{ fill: AXIS, fontSize: 11 }} tickLine={false} axisLine={false} />
                <Tooltip content={<TooltipBox labelFormatter={monthLabel} />} cursor={{ stroke: '#b6c1db', strokeWidth: 1 }} />
                <Legend verticalAlign="top" align="right" height={36} iconType="plainline" iconSize={14} wrapperStyle={{ fontSize: 12, color: AXIS }} />
                {series.map((s, i) => (
                    <Line key={s.name} type="linear" dataKey={s.name} stroke={CATEGORICAL[i]} strokeWidth={2} dot={{ r: 3, strokeWidth: 0, fill: CATEGORICAL[i] }} activeDot={{ r: 5, stroke: '#fff', strokeWidth: 2 }} />
                ))}
            </LineChart>
        </ResponsiveContainer>
    );
}
