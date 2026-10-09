import { Field, Input } from './ui';

export const PASSWORD_HINT = 'Mínimo 10 caracteres, con letras y números.';

/** La contraseña cumple la política del sistema (se valida de nuevo en el servidor). */
export const passwordOk = (value) => value.length >= 10 && /\p{L}/u.test(value) && /\d/.test(value);

/** Par "contraseña nueva + repetir", con la misma ayuda en todas las pantallas. */
export function NewPasswordFields({ password, confirmation, onPassword, onConfirmation, error, autoFocus = false }) {
    const mismatch = confirmation !== '' && password !== confirmation;
    return (
        <>
            <Field label="Nueva contraseña" required hint={PASSWORD_HINT} error={error}>
                {(id) => <Input id={id} type="password" autoComplete="new-password" value={password} onChange={(e) => onPassword(e.target.value)} className="h-11" autoFocus={autoFocus} />}
            </Field>
            <Field label="Repita la contraseña" required error={mismatch ? 'Las dos contraseñas no coinciden.' : null}>
                {(id) => <Input id={id} type="password" autoComplete="new-password" value={confirmation} onChange={(e) => onConfirmation(e.target.value)} className="h-11" />}
            </Field>
        </>
    );
}
