/** Marca "SP" inspirada en el logo de ServiPos 2019 (cuadros rojo, amarillo y verde). */
export function LogoMark({ className = 'size-9' }) {
    return (
        <svg viewBox="0 0 48 48" className={className} aria-hidden>
            <rect x="2" y="2" width="44" height="44" rx="11" fill="#0055ff" />
            <text x="24" y="27" textAnchor="middle" fontFamily="Urbanist, sans-serif" fontWeight="800" fontStyle="italic" fontSize="21" fill="#fff">
                SP
            </text>
            <rect x="11" y="33" width="7" height="6" rx="1.5" fill="#ff3c3c" />
            <rect x="20.5" y="33" width="7" height="6" rx="1.5" fill="#ffb930" />
            <rect x="30" y="33" width="7" height="6" rx="1.5" fill="#2ec46b" />
        </svg>
    );
}

export function Logo({ light = false }) {
    return (
        <div className="flex items-center gap-2.5">
            <LogoMark />
            <div className="leading-none">
                <div className={`font-display text-lg font-extrabold tracking-tight ${light ? 'text-white' : 'text-ink'}`}>
                    ServiPos <span className={light ? 'text-brand-300' : 'text-brand-500'}>2019</span>
                </div>
                <div className={`mt-1 text-[10px] font-semibold uppercase tracking-[0.18em] ${light ? 'text-white/55' : 'text-muted'}`}>
                    Monitor de tickets ATM
                </div>
            </div>
        </div>
    );
}
