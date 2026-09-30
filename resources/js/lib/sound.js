/** Sonido de aviso de ServiPos (dos tonos cortos), generado sin archivos de audio. */
const KEY = 'servipos:sound';

export function soundEnabled() {
    try {
        return localStorage.getItem(KEY) !== 'off';
    } catch {
        return true;
    }
}

export function setSoundEnabled(on) {
    try {
        localStorage.setItem(KEY, on ? 'on' : 'off');
    } catch {
        // sin almacenamiento local
    }
}

let context;

export function playChime() {
    if (!soundEnabled()) {
        return;
    }
    try {
        context ??= new (window.AudioContext || window.webkitAudioContext)();
        if (context.state === 'suspended') {
            context.resume();
        }
        const start = context.currentTime + 0.02;
        [
            [880, 0],
            [1318.5, 0.16],
        ].forEach(([frequency, offset]) => {
            const osc = context.createOscillator();
            const gain = context.createGain();
            osc.type = 'sine';
            osc.frequency.value = frequency;
            gain.gain.setValueAtTime(0.0001, start + offset);
            gain.gain.exponentialRampToValueAtTime(0.35, start + offset + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, start + offset + 0.35);
            osc.connect(gain).connect(context.destination);
            osc.start(start + offset);
            osc.stop(start + offset + 0.4);
        });
    } catch {
        // El navegador bloquea el audio hasta que el usuario interactúa con la página.
    }
}
