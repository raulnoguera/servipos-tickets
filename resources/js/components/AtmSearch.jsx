import { useEffect, useId, useRef, useState } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { AlertTriangle, Building2, List, Search } from 'lucide-react';
import { api } from '../lib/api';
import { useCatalogs } from '../lib/queries';
import { Button, EmptyState, Input, LoadingBlock, Modal, Pagination, Select, Spinner, cx } from './ui';

function useDebounced(value, ms = 250) {
    const [debounced, setDebounced] = useState(value);
    useEffect(() => {
        const t = setTimeout(() => setDebounced(value), ms);
        return () => clearTimeout(t);
    }, [value, ms]);
    return debounced;
}

/** Una fila de cajero: N° de ATM, agencia, estado/modelo y aviso de tickets abiertos. */
function AtmOption({ atm, active, ...props }) {
    return (
        <button
            type="button"
            className={cx('flex w-full items-center gap-3 px-4 py-2.5 text-left transition-colors', active ? 'bg-brand-50' : 'hover:bg-surface')}
            {...props}
        >
            <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-brand-50 text-brand-600">
                <Building2 className="size-4" />
            </span>
            <span className="min-w-0 flex-1">
                <span className="flex items-center gap-2">
                    <span className="font-semibold text-ink tabular">{atm.atm_number}</span>
                    {atm.agency_code && <span className="text-xs text-muted">Cód. {atm.agency_code}</span>}
                </span>
                <span className="block truncate text-sm text-body">{atm.agency_name}</span>
                <span className="block truncate text-xs text-muted">{[atm.state, atm.model, atm.bank].filter(Boolean).join(' · ')}</span>
            </span>
            {atm.open_tickets > 0 && (
                <span className="inline-flex shrink-0 items-center gap-1 rounded-md bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-800 ring-1 ring-amber-200">
                    <AlertTriangle className="size-3" /> {atm.open_tickets} abierto{atm.open_tickets > 1 ? 's' : ''}
                </span>
            )}
        </button>
    );
}

/** Ventana con todos los cajeros cargados, con buscador, filtro por estado y páginas. */
function AtmBrowser({ open, onClose, onPick, initialQuery = '' }) {
    const { data: catalogs } = useCatalogs();
    const [q, setQ] = useState(initialQuery);
    const [state, setState] = useState('');
    const [page, setPage] = useState(1);
    const term = useDebounced(q);

    useEffect(() => setPage(1), [term, state]);
    useEffect(() => {
        if (open) {
            setQ(initialQuery);
        }
    }, [open, initialQuery]);

    const { data, isLoading, isFetching } = useQuery({
        queryKey: ['atm-browser', term, state, page],
        queryFn: () => api('atms', { query: { q: term, state_id: state, page, per_page: 10 } }),
        placeholderData: keepPreviousData,
        enabled: open,
    });

    return (
        <Modal open={open} onClose={onClose} title="Seleccionar cajero" size="lg">
            <div className="grid gap-3 sm:grid-cols-[1fr_220px]">
                <div className="relative">
                    <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-faint" />
                    <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Filtrar por N° de ATM, serial, agencia o código" className="pl-9" autoFocus />
                </div>
                <Select value={state} onChange={(e) => setState(e.target.value)} aria-label="Estado">
                    <option value="">Todos los estados</option>
                    {catalogs?.states.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                </Select>
            </div>
            <div className="-mx-5 mt-4 border-t border-line">
                {isLoading ? (
                    <LoadingBlock />
                ) : !data?.data.length ? (
                    <EmptyState icon={Building2} title="No hay cajeros con ese filtro" />
                ) : (
                    <>
                        <p className="flex items-center gap-2 px-5 pt-3 pb-1 text-xs text-muted">
                            {data.meta.total} cajero(s) {isFetching && <Spinner className="size-3.5" />}
                        </p>
                        <div className="divide-y divide-line">
                            {data.data.map((atm) => (
                                <AtmOption key={atm.id} atm={atm} onClick={() => onPick(atm)} />
                            ))}
                        </div>
                        <Pagination meta={data.meta} onPage={setPage} />
                    </>
                )}
            </div>
        </Modal>
    );
}

/**
 * Buscador de cajero con sugerencias mientras se escribe.
 * "Buscar" con el campo vacío abre la lista completa de cajeros.
 */
export default function AtmSearch({ onSelect, onNotFound }) {
    const [text, setText] = useState('');
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(0);
    const [browser, setBrowser] = useState(false);
    const [looking, setLooking] = useState(false);
    const [selected, setSelected] = useState(null);
    const wrapper = useRef(null);
    const listId = useId();
    const term = useDebounced(text.trim(), 200);

    const { data, isFetching } = useQuery({
        queryKey: ['atm-suggest', term],
        queryFn: () => api('atms', { query: { q: term, per_page: 8 } }),
        enabled: term.length > 0,
        placeholderData: keepPreviousData,
        staleTime: 60000,
    });
    const suggestions = term ? data?.data ?? [] : [];

    useEffect(() => setActive(0), [term]);
    useEffect(() => {
        const onDown = (e) => wrapper.current && !wrapper.current.contains(e.target) && setOpen(false);
        document.addEventListener('mousedown', onDown);
        return () => document.removeEventListener('mousedown', onDown);
    }, []);

    function pick(atm) {
        setSelected(atm);
        setText(atm.atm_number);
        setOpen(false);
        setBrowser(false);
        onSelect(atm);
    }

    /** Buscar / Enter: vacío → lista completa; con texto → la sugerencia marcada o búsqueda exacta. */
    async function submit(e) {
        e?.preventDefault();
        const value = text.trim();
        if (!value) {
            setBrowser(true);
            return;
        }
        if (open && suggestions[active]) {
            pick(suggestions[active]);
            return;
        }
        setLooking(true);
        try {
            pick((await api(`atms/lookup/${encodeURIComponent(value)}`)).data);
        } catch (err) {
            onNotFound(err.status === 404 ? `No existe el ATM "${value.toUpperCase()}" en el maestro de cajeros.` : err.message);
        } finally {
            setLooking(false);
        }
    }

    function onKeyDown(e) {
        if (!open || !suggestions.length) {
            return;
        }
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActive((i) => (i + 1) % suggestions.length);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive((i) => (i - 1 + suggestions.length) % suggestions.length);
        } else if (e.key === 'Escape') {
            setOpen(false);
        }
    }

    return (
        <>
            <form onSubmit={submit} className="flex gap-2" ref={wrapper}>
                <div className="relative flex-1">
                    <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-faint" />
                    <Input
                        value={text}
                        onChange={(e) => {
                            setText(e.target.value);
                            setOpen(true);
                            // Si cambia el texto después de elegir, se deja de mostrar el cajero anterior.
                            if (selected && e.target.value.trim().toUpperCase() !== selected.atm_number) {
                                setSelected(null);
                                onSelect(null);
                            }
                        }}
                        onFocus={() => text && setOpen(true)}
                        onKeyDown={onKeyDown}
                        placeholder="N° de ATM, agencia o serial (ej. A1918035)"
                        className="h-11 pl-9 text-base font-semibold tracking-wide"
                        autoFocus
                        autoComplete="off"
                        role="combobox"
                        aria-expanded={open && suggestions.length > 0}
                        aria-controls={listId}
                        aria-label="Buscar cajero"
                    />
                    {isFetching && term && <Spinner className="absolute top-1/2 right-3 size-4 -translate-y-1/2" />}

                    {open && term && (
                        <div id={listId} role="listbox" className="absolute inset-x-0 top-12 z-20 max-h-[360px] overflow-y-auto rounded-xl border border-line bg-white py-1 shadow-xl">
                            {suggestions.length ? (
                                suggestions.map((atm, i) => (
                                    <AtmOption
                                        key={atm.id}
                                        atm={atm}
                                        active={i === active}
                                        role="option"
                                        aria-selected={i === active}
                                        onMouseEnter={() => setActive(i)}
                                        onClick={() => pick(atm)}
                                    />
                                ))
                            ) : !isFetching ? (
                                <p className="px-4 py-3 text-sm text-muted">Sin coincidencias para “{term}”.</p>
                            ) : null}
                            {data?.meta?.total > suggestions.length && (
                                <button type="button" onClick={() => { setOpen(false); setBrowser(true); }} className="w-full border-t border-line px-4 py-2.5 text-left text-sm font-semibold text-brand-600 hover:bg-surface">
                                    Ver las {data.meta.total} coincidencias…
                                </button>
                            )}
                        </div>
                    )}
                </div>
                <Button type="submit" size="lg" variant="secondary" loading={looking} icon={text.trim() ? Search : List}>
                    {text.trim() ? 'Buscar' : 'Ver cajeros'}
                </Button>
            </form>
            <AtmBrowser open={browser} onClose={() => setBrowser(false)} onPick={pick} initialQuery={text.trim()} />
        </>
    );
}
