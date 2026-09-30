/** Autoría del sistema. */
export const SIGNATURE = 'ServiPos Tickets · Diseñado y desarrollado por Raúl Noguera · 2026';

/** Firma en la consola del navegador (herramientas de desarrollador). */
export function printSignature() {
    console.info(
        '%cServiPos Tickets%c\nDiseñado y desarrollado por Raúl Noguera · 2026',
        'font: 800 16px sans-serif; color: #0055ff',
        'font: 500 12px sans-serif; color: #687799',
    );
}
