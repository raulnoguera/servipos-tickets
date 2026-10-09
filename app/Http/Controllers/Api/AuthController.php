<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** Intentos fallidos seguidos de un correo desde una misma IP antes de bloquearlo. */
    private const MAX_ATTEMPTS = 5;

    private const LOCKOUT_SECONDS = 300;

    /** Intentos fallidos de un correo desde cualquier IP (ataque distribuido) antes de bloquearlo. */
    private const ACCOUNT_MAX_ATTEMPTS = 30;

    private const ACCOUNT_LOCKOUT_SECONDS = 600;

    public function __construct(private AuditLogger $audit) {}

    public function login(Request $request): UserResource
    {
        // El inicio de sesión solo funciona desde el portal (sesión con cookie); peticiones
        // de otros orígenes o scripts sin sesión se rechazan sin revelar nada.
        abort_unless($request->hasSession(), 400, 'Inicie sesión desde el portal.');

        // "email" es el nombre histórico del campo: admite correo o teléfono (los técnicos entran con su número).
        $input = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);
        $login = trim($input['email']);
        $phone = str_contains($login, '@') ? null : User::normalizePhone($login);
        // Lo que no es un teléfono se busca como correo (y sencillamente no coincide si no lo es).
        $credentials = $phone ? ['phone_normalized' => $phone] : ['email' => Str::lower($login)];

        // Dos contadores: por usuario + IP (5 intentos) y por usuario desde cualquier IP (30).
        // El identificador se normaliza para que variantes con acentos, mayúsculas o formato cuenten igual.
        $id = $phone ?? Str::lower(Str::ascii($login));
        $key = 'login:'.$id.'|'.$request->ip();
        $accountKey = 'login-cuenta:'.$id;
        $blocked = match (true) {
            RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS) => $key,
            RateLimiter::tooManyAttempts($accountKey, self::ACCOUNT_MAX_ATTEMPTS) => $accountKey,
            default => null,
        };
        if ($blocked) {
            $minutes = (int) ceil(RateLimiter::availableIn($blocked) / 60);
            Log::warning('Inicio de sesión bloqueado por intentos', ['usuario' => $login, 'ip' => $request->ip()]);
            throw ValidationException::withMessages(['email' => "Demasiados intentos fallidos. Intente de nuevo en {$minutes} minuto(s)."]);
        }

        if (! Auth::guard('web')->attempt($credentials + ['password' => $input['password'], 'active' => true], $request->boolean('remember'))) {
            $attempts = RateLimiter::hit($key, self::LOCKOUT_SECONDS);
            $accountAttempts = RateLimiter::hit($accountKey, self::ACCOUNT_LOCKOUT_SECONDS);
            Log::notice('Inicio de sesión fallido', ['usuario' => $login, 'ip' => $request->ip()]);
            // Solo se audita el momento en que la cuenta queda bloqueada, no cada intento.
            if ($attempts === self::MAX_ATTEMPTS || $accountAttempts === self::ACCOUNT_MAX_ATTEMPTS) {
                $this->audit->record('auth.lockout', "Cuenta bloqueada por intentos fallidos: {$login}", meta: ['usuario' => $login]);
            }
            throw ValidationException::withMessages(['email' => 'Usuario o contraseña incorrectos.']);
        }
        RateLimiter::clear($key);
        RateLimiter::clear($accountKey);
        $request->session()->regenerate();
        $request->user()->forceFill(['last_login_at' => now()])->saveQuietly();
        $this->audit->record('auth.login', 'Inicio de sesión'.($request->boolean('remember') ? ' (mantener sesión iniciada)' : ''));

        return new UserResource($request->user());
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('states'));
    }

    /** El usuario cambia su propia contraseña; debe escribir la actual. */
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(10)->letters()->numbers()],
        ], [
            'current_password.current_password' => 'La contraseña actual no es correcta.',
            'password.different' => 'La nueva contraseña debe ser distinta de la actual.',
        ]);

        $user = $request->user();
        // Cierra sus otras sesiones y "recordarme" en otros equipos; esta sesión sigue abierta.
        $user->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save();
        self::keepSession($request, $user);
        $this->audit->record('user.password_changed', 'Cambió su propia contraseña');

        return response()->json(['ok' => true]);
    }

    /**
     * Tras cambiar la contraseña, Sanctum cierra las sesiones que guardan la anterior;
     * la sesión actual se actualiza para que quien la cambió no quede fuera.
     */
    public static function keepSession(Request $request, User $user): void
    {
        if ($request->hasSession()) {
            $guard = Auth::guard('web');
            $request->session()->put('password_hash_web', method_exists($guard, 'hashPasswordForCookie')
                ? $guard->hashPasswordForCookie($user->getAuthPassword())
                : $user->getAuthPassword());
        }
    }

    public function logout(Request $request): JsonResponse
    {
        $this->audit->record('auth.logout', 'Cierre de sesión');
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }
}
