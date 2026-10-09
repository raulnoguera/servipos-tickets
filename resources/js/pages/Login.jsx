import { useState } from 'react';
import { ArrowRight, BarChart3, Camera, Clock3, Lock, UserRound } from 'lucide-react';
import { useAuth } from '../lib/auth';
import { Alert, Button, Field, Input } from '../components/ui';
import { Logo } from '../components/Logo';

const FEATURES = [
    { icon: Clock3, title: 'Tiempos de atención medidos', text: 'Cada ticket registra su ciclo exacto, de la apertura al cierre.' },
    { icon: Camera, title: 'Constancia digital en sitio', text: 'El técnico sube la foto de la planilla y los repuestos desde el teléfono.' },
    { icon: BarChart3, title: 'Monitor en tiempo real', text: 'Tickets abiertos, pendientes por repuesto y consumo mensual.' },
];

export default function Login() {
    const { login } = useAuth();
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [remember, setRemember] = useState(false);
    const [error, setError] = useState(null);
    const [loading, setLoading] = useState(false);

    async function submit(e) {
        e.preventDefault();
        setLoading(true);
        setError(null);
        try {
            await login(email, password, remember);
        } catch (err) {
            setError(err.field?.('email') || err.message || 'No se pudo iniciar sesión.');
        } finally {
            setLoading(false);
        }
    }

    return (
        <div className="grid min-h-dvh lg:grid-cols-[1.1fr_1fr]">
            <section className="relative hidden overflow-hidden bg-navy-900 p-12 text-white lg:flex lg:flex-col lg:justify-between">
                <div className="absolute -top-40 -right-40 size-[520px] rounded-full bg-brand-500/25 blur-3xl" />
                <div className="absolute -bottom-48 -left-24 size-[420px] rounded-full bg-brand-400/15 blur-3xl" />
                <div className="relative"><Logo light large /></div>
                <div className="relative max-w-lg">
                    <p className="mb-4 text-sm font-semibold uppercase tracking-[0.2em] text-brand-300">Soporte técnico de cajeros automáticos</p>
                    <h1 className="font-display text-4xl leading-tight font-extrabold text-white xl:text-5xl">
                        Centro de control y monitoreo del soporte ATM en tiempo real.
                    </h1>
                    <ul className="mt-10 space-y-5">
                        {FEATURES.map((f) => (
                            <li key={f.title} className="flex gap-4">
                                <div className="grid size-10 shrink-0 place-items-center rounded-lg bg-white/10 text-brand-300">
                                    <f.icon className="size-5" />
                                </div>
                                <div>
                                    <p className="font-semibold text-white">{f.title}</p>
                                    <p className="text-sm text-white/60">{f.text}</p>
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
                <p className="relative text-xs text-white/40">© {new Date().getFullYear()} ServiPos 2019, C.A.</p>
            </section>

            <section className="flex items-center justify-center px-5 py-12 sm:px-10">
                <div className="w-full max-w-sm">
                    <div className="mb-10 flex justify-center">
                        <Logo large />
                    </div>
                    <h2 className="text-3xl font-extrabold tracking-tight">Iniciar sesión</h2>
                    <p className="mt-2 text-sm text-muted">Ingrese con su correo o con su número de teléfono.</p>

                    <form onSubmit={submit} className="mt-8 space-y-5">
                        {error && <Alert>{error}</Alert>}
                        <Field label="Correo o teléfono">
                            {(id) => (
                                <div className="relative">
                                    <UserRound className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-faint" />
                                    <Input id={id} type="text" inputMode="email" autoCapitalize="none" autoCorrect="off" spellCheck={false} autoComplete="username" required value={email} onChange={(e) => setEmail(e.target.value)} className="h-11 pl-9" placeholder="correo@empresa.com o 0414-1234567" />
                                </div>
                            )}
                        </Field>
                        <Field label="Contraseña">
                            {(id) => (
                                <div className="relative">
                                    <Lock className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-faint" />
                                    <Input id={id} type="password" autoComplete="current-password" required value={password} onChange={(e) => setPassword(e.target.value)} className="h-11 pl-9" />
                                </div>
                            )}
                        </Field>
                        <label className="flex items-center gap-2 text-sm text-body">
                            <input type="checkbox" checked={remember} onChange={(e) => setRemember(e.target.checked)} className="size-4 rounded border-line accent-brand-500" />
                            Mantener la sesión iniciada
                        </label>
                        <Button type="submit" size="lg" className="w-full" loading={loading}>
                            Entrar <ArrowRight className="size-4" />
                        </Button>
                        <p className="text-center text-xs text-muted">¿Olvidó su contraseña? Pida al administrador un enlace de acceso nuevo.</p>
                    </form>
                </div>
            </section>
        </div>
    );
}
