<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
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

        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        // Dos contadores: por correo + IP (5 intentos) y por correo desde cualquier IP (30).
        // El correo se normaliza para que variantes con acentos o mayúsculas cuenten igual.
        $email = Str::lower(Str::ascii($credentials['email']));
        $key = 'login:'.$email.'|'.$request->ip();
        $accountKey = 'login-cuenta:'.$email;
        $blocked = match (true) {
            RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS) => $key,
            RateLimiter::tooManyAttempts($accountKey, self::ACCOUNT_MAX_ATTEMPTS) => $accountKey,
            default => null,
        };
        if ($blocked) {
            $minutes = (int) ceil(RateLimiter::availableIn($blocked) / 60);
            Log::warning('Inicio de sesión bloqueado por intentos', ['email' => $credentials['email'], 'ip' => $request->ip()]);
            throw ValidationException::withMessages(['email' => "Demasiados intentos fallidos. Intente de nuevo en {$minutes} minuto(s)."]);
        }

        if (! Auth::guard('web')->attempt($credentials + ['active' => true], $request->boolean('remember'))) {
            $attempts = RateLimiter::hit($key, self::LOCKOUT_SECONDS);
            $accountAttempts = RateLimiter::hit($accountKey, self::ACCOUNT_LOCKOUT_SECONDS);
            Log::notice('Inicio de sesión fallido', ['email' => $credentials['email'], 'ip' => $request->ip()]);
            // Solo se audita el momento en que la cuenta queda bloqueada, no cada intento.
            if ($attempts === self::MAX_ATTEMPTS || $accountAttempts === self::ACCOUNT_MAX_ATTEMPTS) {
                $this->audit->record('auth.lockout', "Cuenta bloqueada por intentos fallidos: {$credentials['email']}", meta: ['email' => $credentials['email']]);
            }
            throw ValidationException::withMessages(['email' => 'Correo o contraseña incorrectos.']);
        }
        RateLimiter::clear($key);
        RateLimiter::clear($accountKey);
        $request->session()->regenerate();
        $this->audit->record('auth.login', 'Inicio de sesión'.($request->boolean('remember') ? ' (mantener sesión iniciada)' : ''));

        return new UserResource($request->user());
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('states'));
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
