import { CartesianGrid, Cell, Legend, Line, LineChart, Pie, PieChart, XAxis, YAxis } from 'recharts';
import fondoBnc from '../../images/informe/fondo-bnc.jpg';
import portadaBnc from '../../images/informe/portada-bnc.jpg';
import mapaVenezuela from '../../images/informe/mapa-venezuela.png';
import { Logo } from './Logo';

/*
 * El informe visual tal como lo presenta el banco: diapositivas 16:9 con su
 * fondo, títulos en tres colores, letra redondeada y gráficas al estilo Excel.
 * Solo existe al imprimir; en pantalla se ve el informe interactivo.
 */

/* Medidas de la diapositiva en píxeles CSS (13,333 × 7,5 pulgadas a 96 ppp). */
const W = 1280;
const H = 720;
const box = (x, y, w, h) => ({ left: `${x}%`, top: `${y}%`, width: `${w}%`, height: `${h}%` });
const px = (wPct, hPct) => [Math.round((W * wPct) / 100), Math.round((H * hPct) / 100)];

/* Colores de la presentación (tema de Office del banco). */
const GOLD = '#bf9000';
const BLUE = '#0070c0';
const GREEN = '#00b050';
const NAVY = '#002060';
const LINE = '#5b9bd5';
const OFFICE = ['#5b9bd5', '#ed7d31', '#a5a5a5', '#ffc000', '#4472c4', '#70ad47', '#255e91', '#9e480e', '#636363', '#997300'];
const AXIS = { fill: '#595959', fontSize: 13, fontFamily: 'Calibri, Carlito, Arial, sans-serif' };

/** Título en tres colores: la primera palabra dorada, las del medio azules y la última verde. */
function Title({ words, style, size, extra }) {
    const color = (i) => (i === 0 ? GOLD : i === words.length - 1 ? GREEN : BLUE);
    return (
        <div className="slide-title" style={{ ...style, fontSize: `${size}pt` }}>
            <span>{words.map((w, i) => <span key={w} style={{ color: color(i) }}>{i > 0 && ' '}{w}</span>)}</span>
            {extra && <span style={{ display: 'block', color: GREEN }}>{extra}</span>}
        </div>
    );
}

function Slide({ bnc, children }) {
    return (
        <section className="slide" style={bnc ? { backgroundImage: `url(${fondoBnc})` } : undefined}>
            {!bnc && <div className="slide-logo"><Logo subtitle={false} large /></div>}
            {children}
        </section>
    );
}

function Body({ style, size = 32, children }) {
    return <div className="slide-body" style={{ ...style, fontSize: `${size}pt` }}>{children}</div>;
}

/** Tabla al estilo de Excel: encabezado con línea azul y filas alternas. */
function ExcelTable({ style, head, rows, total }) {
    return (
        <div className="slide-table" style={style}>
            <table>
                <thead><tr>{head.map((h, i) => <th key={h} style={{ textAlign: i ? 'right' : 'left' }}>{h}</th>)}</tr></thead>
                <tbody>
                    {rows.map((r) => <tr key={r[0]}>{r.map((c, i) => <td key={i} style={{ textAlign: i ? 'right' : 'left' }}>{c}</td>)}</tr>)}
                    {total && <tr className="total">{total.map((c, i) => <td key={i} style={{ textAlign: i ? 'right' : 'left' }}>{c}</td>)}</tr>}
                </tbody>
            </table>
        </div>
    );
}

/** Etiqueta del eje en varias líneas, para nombres largos como «Fuera de Servicio / Pendiente por 3ero». */
function WrapTick({ x, y, payload }) {
    const lines = [];
    for (const word of String(payload.value).split(' ')) {
        const last = lines[lines.length - 1];
        if (last && `${last} ${word}`.length <= 16) {
            lines[lines.length - 1] = `${last} ${word}`;
        } else {
            lines.push(word);
        }
    }
    return (
        <text x={x} y={y + 14} textAnchor="middle" fill={AXIS.fill} fontSize={13} fontFamily={AXIS.fontFamily}>
            {lines.map((l, i) => <tspan key={i} x={x} dy={i ? 15 : 0}>{l}</tspan>)}
        </text>
    );
}

function ExcelLine({ data, labelKey, valueKey, size, angled = false }) {
    const [w, h] = size;
    return (
        <div className="slide-chart">
            <LineChart width={w} height={h} data={data} margin={{ top: 16, right: 56, bottom: angled ? 8 : 4, left: 8 }}>
                <CartesianGrid stroke="#d9d9d9" vertical={false} />
                <XAxis
                    dataKey={labelKey}
                    interval={0}
                    tick={angled ? { ...AXIS, fontSize: 12 } : <WrapTick />}
                    tickLine={false}
                    axisLine={{ stroke: '#d9d9d9' }}
                    angle={angled ? -45 : 0}
                    textAnchor={angled ? 'end' : 'middle'}
                    height={angled ? 110 : 64}
                />
                <YAxis allowDecimals={false} tick={AXIS} tickLine={false} axisLine={false} width={44} />
                <Line type="linear" dataKey={valueKey} stroke={LINE} strokeWidth={3} dot={false} isAnimationActive={false} />
            </LineChart>
        </div>
    );
}

export default function InformePrint({ data, period, monthTitle, sentences }) {
    const bnc = /nacional de cr[eé]dito/i.test(data.bank ?? '') || /^bnc$/i.test(data.bank_short ?? '');
    const brand = data.brands.length === 1 ? ` ${data.brands[0]}` : '';
    const zones = data.zones.by_state;

    return (
        <div className="print-slides" aria-hidden="true">
            <Slide bnc={bnc}>
                <div className="slide-body" style={{ ...box(4, 25, bnc ? 45 : 92, 50), fontSize: '54pt' }}>
                    Resumen sobre reportes de atenciones a ATM{brand}
                    <span style={{ display: 'block', marginTop: '0.35in', fontSize: '24pt', color: GREEN }}>{period}</span>
                </div>
                {bnc && <img src={portadaBnc} alt="" className="slide-photo" style={box(50, 25, 39, 55)} />}
            </Slide>

            <Slide bnc={bnc}>
                <Title words={['Zona', 'de', 'Atención']} size={54} style={box(14, 6, 72, 15)} />
                <ExcelTable
                    style={box(3, 24, 36, 70)}
                    head={['Estado', 'ATM']}
                    rows={zones.map((z) => [z.name, z.atms])}
                    total={['', data.zones.atms]}
                />
                <img src={mapaVenezuela} alt="" className="slide-photo" style={{ ...box(44, 25, 40, 61), objectFit: 'contain' }} />
            </Slide>

            <Slide bnc={bnc}>
                <Title words={['Cantidad', 'y Estados de', 'Reportes']} size={48} style={box(22, 4, 54, 22)} />
                <div style={{ position: 'absolute', ...box(5, 30, 42, 52) }}>
                    <ExcelLine data={data.statuses} labelKey="name" valueKey="total" size={px(42, 52)} />
                </div>
                <Body style={box(48, 32, 50, 54)}>{sentences.statuses}</Body>
            </Slide>

            <Slide bnc={bnc}>
                <Title words={['Motivos', 'de los', 'Reportes']} extra={monthTitle} size={36} style={{ ...box(1, 20, 48, 20), textAlign: 'center' }} />
                <Body style={box(51, 22, 47, 64)} size={data.faults.length > 6 ? 20 : 24}>{sentences.faults}</Body>
                <div style={{ position: 'absolute', ...box(5, 42, 42, 54) }}>
                    <PieChart width={px(42, 54)[0]} height={px(42, 54)[1]}>
                        <Pie data={data.faults} dataKey="total" nameKey="name" cx="50%" cy="40%" outerRadius="70%" stroke="#ffffff" strokeWidth={1} isAnimationActive={false}>
                            {data.faults.map((f, i) => <Cell key={f.name} fill={OFFICE[i % OFFICE.length]} />)}
                        </Pie>
                        <Legend verticalAlign="bottom" iconType="square" iconSize={9} wrapperStyle={{ ...AXIS, fontSize: 12, lineHeight: '18px' }} />
                    </PieChart>
                </div>
            </Slide>

            <Slide bnc={bnc}>
                <Title words={['Repuestos', 'Utilizados']} size={44} style={box(22, 4, 54, 13)} />
                <div style={{ position: 'absolute', ...box(5, 20, 50, 58) }}>
                    {data.parts.by_part.length > 0 && <ExcelLine data={data.parts.by_part} labelKey="name" valueKey="quantity" size={px(50, 58)} angled />}
                </div>
                <ExcelTable
                    style={box(62, 18, 25, 62)}
                    head={['Repuesto', 'Cantidad']}
                    rows={data.parts.by_part.map((p) => [p.name, p.quantity])}
                    total={['Total', data.parts.total]}
                />
                <Body style={box(1, 82, 87, 15)}>{sentences.parts}</Body>
            </Slide>
        </div>
    );
}
