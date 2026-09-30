<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    private const LOCKOUT_SECONDS = 300;

    public function login(Request $request): UserResource
    {
        // El inicio de sesión solo funciona desde el portal (sesión con cookie); peticiones
        // de otros orígenes o scripts sin sesión se rechazan sin revelar nada.
        abort_unless($request->hasSession(), 400, 'Inicie sesión desde el portal.');

        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        // Bloqueo por cuenta: 5 intentos fallidos seguidos bloquean ese correo 5 minutos (desde esa IP).
        $key = 'login:'.Str::lower($credentials['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            Log::warning('Inicio de sesión bloqueado por intentos', ['email' => $credentials['email'], 'ip' => $request->ip()]);
            throw ValidationException::withMessages(['email' => "Demasiados intentos fallidos. Intente de nuevo en {$minutes} minuto(s)."]);
        }

        if (! Auth::guard('web')->attempt($credentials + ['active' => true], $request->boolean('remember'))) {
            RateLimiter::hit($key, self::LOCKOUT_SECONDS);
            Log::notice('Inicio de sesión fallido', ['email' => $credentials['email'], 'ip' => $request->ip()]);
            throw ValidationException::withMessages(['email' => 'Correo o contraseña incorrectos.']);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();

        return new UserResource($request->user());
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('states'));
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }
}
