const dateFmt = new Intl.DateTimeFormat('es-VE', { day: '2-digit', month: 'short', year: 'numeric' });
const dateTimeFmt = new Intl.DateTimeFormat('es-VE', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', hour12: true });
const numberFmt = new Intl.NumberFormat('es-VE');

export const formatDate = (iso) => (iso ? dateFmt.format(new Date(iso.length === 10 ? `${iso}T00:00:00` : iso)) : '—');
export const formatDateTime = (iso) => (iso ? dateTimeFmt.format(new Date(iso)) : '—');
export const formatNumber = (n) => numberFmt.format(n ?? 0);

/** Duración legible: "45 min", "2 h 15 min", "1 d 04 h". */
export function formatDuration(ms) {
    if (ms === null || ms === undefined) {
        return '—';
    }
    const minutes = Math.floor(ms / 60000);
    if (minutes < 60) {
        return `${Math.max(0, minutes)} min`;
    }
    const hours = Math.floor(minutes / 60);
    if (hours < 24) {
        return `${hours} h ${String(minutes % 60).padStart(2, '0')} min`;
    }
    return `${Math.floor(hours / 24)} d ${String(hours % 24).padStart(2, '0')} h`;
}

/** Duración exacta con milisegundos (RF-3.4), para el detalle del ticket. */
export function formatDurationExact(ms) {
    if (ms === null || ms === undefined) {
        return '—';
    }
    const pad = (n, l = 2) => String(n).padStart(l, '0');
    const totalSeconds = Math.floor(ms / 1000);
    const days = Math.floor(totalSeconds / 86400);
    const h = Math.floor((totalSeconds % 86400) / 3600);
    const m = Math.floor((totalSeconds % 3600) / 60);
    const s = totalSeconds % 60;
    return `${days ? `${days} d ` : ''}${pad(h)}:${pad(m)}:${pad(s)}.${pad(ms % 1000, 3)}`;
}

export const initials = (name = '') =>
    name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0].toUpperCase()).join('');

/** Enlace a Google Maps con la mejor dirección disponible del cajero. */
export function mapsUrl(atm) {
    const query = [atm?.address, atm?.agency_name, atm?.state, 'Venezuela'].filter(Boolean).join(', ');
    return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(query)}`;
}

export const formatPct = (value) => (value === null || value === undefined ? '—' : `${String(value).replace('.', ',')} %`);

/** Primer día del mes y hoy, en formato AAAA-MM-DD (hora local). */
export function monthToDate() {
    const now = new Date();
    const pad = (n) => String(n).padStart(2, '0');
    const today = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
    return [`${now.getFullYear()}-${pad(now.getMonth() + 1)}-01`, today];
}
