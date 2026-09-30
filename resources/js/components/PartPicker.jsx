import { useMemo, useState } from 'react';
import { ChevronDown, Package, Search } from 'lucide-react';
import { Input, Modal, cx } from './ui';

/** Quita tildes y pasa a mayúsculas para comparar ("bateria" = "BATERÍA"). */
const normalize = (text) => text.normalize('NFD').replace(/[̀-ͯ]/g, '').toUpperCase().trim();

/**
 * Selector de repuesto con buscador. Filtra sobre el catálogo ya descargado,
 * así que funciona sin señal. Cada palabra escrita debe aparecer en el nombre.
 */
export default function PartPicker({ parts, value, onChange, exclude = [], label }) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const selected = parts.find((p) => String(p.id) === String(value));

    const results = useMemo(() => {
        const words = normalize(query).split(/\s+/).filter(Boolean);
        return parts
            .filter((p) => p.active && (!exclude.includes(String(p.id)) || String(p.id) === String(value)))
            .filter((p) => words.every((w) => normalize(p.name).includes(w)))
            .sort((a, b) => {
                // Primero los que empiezan con lo escrito.
                const first = normalize(query);
                return Number(!normalize(b.name).startsWith(first)) - Number(!normalize(a.name).startsWith(first)) || a.name.localeCompare(b.name);
            });
    }, [parts, query, exclude, value]);

    function choose(part) {
        onChange(String(part.id));
        setOpen(false);
        setQuery('');
    }

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                aria-label={label}
                className={cx(
                    'flex h-11 min-w-0 basis-full items-center gap-2 rounded-lg border bg-white px-3 text-left text-sm transition sm:h-10 sm:basis-0 sm:flex-1',
                    selected ? 'border-line font-semibold text-ink' : 'border-dashed border-brand-300 text-brand-600',
                    'focus:border-brand-500 focus:ring-3 focus:ring-brand-500/15 focus:outline-none',
                )}
            >
                {selected ? <Package className="size-4 shrink-0 text-brand-500" /> : <Search className="size-4 shrink-0" />}
                <span className="truncate">{selected ? selected.name : 'Elegir repuesto…'}</span>
                <ChevronDown className="ml-auto size-4 shrink-0 text-faint" />
            </button>

            <Modal open={open} onClose={() => setOpen(false)} title="Elegir repuesto" size="sm">
                <div className="relative">
                    <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-faint" />
                    <Input
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                results[0] && choose(results[0]);
                            }
                        }}
                        placeholder="Escriba para buscar: picker, correa, batería…"
                        className="h-11 pl-9"
                        autoFocus
                        autoComplete="off"
                        aria-label="Buscar repuesto"
                    />
                </div>
                <p className="mt-2 text-xs text-muted">{results.length} repuesto(s)</p>
                <ul className="-mx-5 mt-2 max-h-[50dvh] divide-y divide-line overflow-y-auto border-t border-line">
                    {results.map((part) => (
                        <li key={part.id}>
                            <button
                                type="button"
                                onClick={() => choose(part)}
                                className={cx(
                                    'flex w-full items-center gap-3 px-5 py-3 text-left text-sm active:bg-brand-50',
                                    String(part.id) === String(value) ? 'bg-brand-50 font-bold text-brand-700' : 'font-medium text-ink hover:bg-surface',
                                )}
                            >
                                <Package className="size-4 shrink-0 text-brand-400" />
                                {part.name}
                            </button>
                        </li>
                    ))}
                    {!results.length && (
                        <li className="px-5 py-6 text-center text-sm text-muted">
                            No hay repuestos con “{query}”. Si falta en el catálogo, pídale al administrador que lo agregue.
                        </li>
                    )}
                </ul>
            </Modal>
        </>
    );
}
