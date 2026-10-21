import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { ArrowRight, KeyRound } from 'lucide-react';
import { api } from '../lib/api';
import { useAuth } from '../lib/auth';
import { Alert, Button, LoadingBlock } from '../components/ui';
import { DevelopedBy, Logo } from '../components/Logo';
import { NewPasswordFields, passwordOk } from '../components/PasswordFields';

/**
 * Página del enlace de acceso que envía el administrador: el usuario crea su propia
 * contraseña y entra. El código viaja después de "#" y nunca llega a los registros del servidor.
 */
export default function Access() {
    const { adopt } = useAuth();
    const navigate = useNavigate();
    const [token] = useState(() => window.location.hash.replace(/^#/, ''));
    const [who, setWho] = useState(undefined); // undefined = verificando · null = enlace inválido
    const [invalid, setInvalid] = useState(null);
    const [password, setPassword] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [error, setError] = useState(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        // El código se quita de la barra de direcciones en cuanto se lee.
        window.history.replaceState(null, '', '/acceso');
        api('access/check', { method: 'POST', body: { token } })
            .then(setWho)
            .catch((e) => {
                setInvalid(e.offline ? 'Sin conexión. Abra el enlace de nuevo cuando tenga señal.' : e.field?.('token') || 'Este enlace no es válido. Pida uno nuevo al administrador.');
                setWho(null);
            });
    }, [token]);

    async function submit(e) {
        e.preventDefault();
        setSaving(true);
        setError(null);
        try {
            const r = await api('access/complete', { method: 'POST', body: { token, password, password_confirmation: confirmation } });
            await adopt(r.data);
            navigate('/', { replace: true });
        } catch (err) {
            if (err.field?.('token')) {
                setInvalid(err.field('token'));
                setWho(null);
            } else {
                setError(err.field?.('password') || err.message);
            }
        } finally {
            setSaving(false);
        }
    }

    return (
        <div className="flex min-h-dvh items-center justify-center bg-surface px-5 py-10">
            <div className="w-full max-w-sm">
                <div className="mb-8 flex justify-center"><Logo large /></div>
                {who === undefined ? (
                    <LoadingBlock label="Verificando el enlace…" />
                ) : who === null ? (
                    <>
                        <h1 className="text-2xl font-extrabold tracking-tight">Enlace no disponible</h1>
                        <Alert tone="warn" className="mt-4">{invalid}</Alert>
                        <Button as="a" href="/" variant="secondary" size="lg" className="mt-6 w-full">Ir al inicio de sesión</Button>
                    </>
                ) : (
                    <>
                        <h1 className="text-2xl font-extrabold tracking-tight">Hola, {who.name.split(' ')[0]}</h1>
                        <p className="mt-2 text-sm text-body">Cree su contraseña para entrar a ServiPos Tickets. Solo usted la conocerá.</p>
                        <p className="mt-4 flex items-center gap-2 rounded-xl border border-line bg-white px-3.5 py-3 text-sm text-body">
                            <KeyRound className="size-4 shrink-0 text-brand-500" />
                            <span>Su usuario para ingresar: <b className="text-ink tabular">{who.login}</b></span>
                        </p>
                        <form onSubmit={submit} className="mt-6 space-y-5">
                            {error && <Alert>{error}</Alert>}
                            <NewPasswordFields password={password} confirmation={confirmation} onPassword={setPassword} onConfirmation={setConfirmation} autoFocus />
                            <Button type="submit" size="lg" className="w-full" loading={saving} disabled={!passwordOk(password) || password !== confirmation}>
                                Crear contraseña y entrar <ArrowRight className="size-4" />
                            </Button>
                        </form>
                    </>
                )}
                <DevelopedBy className="mt-12 text-center" />
            </div>
        </div>
    );
}
