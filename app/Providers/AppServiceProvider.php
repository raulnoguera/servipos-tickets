<?php

namespace App\Providers;

use App\Services\SlaService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

/**
 * ServiPos Tickets · Sistema de monitoreo de tickets ATM.
 * Diseñado y desarrollado por Raúl Noguera · 2026.
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SlaService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Nonce de la política de contenido (CSP) para las etiquetas que genera Vite.
        Vite::useCspNonce();

        // Inicio de sesión: además del bloqueo por correo (AuthController), máximo 20 intentos por minuto por IP.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));

        // API en general: 240 solicitudes por minuto por usuario (o por IP si no hay sesión).
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(240)->by($request->user()?->id ?: $request->ip()));

        // Operaciones pesadas (reportes, exportaciones, importación).
        RateLimiter::for('heavy', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));

        // En producción, los enlaces generados siempre usan HTTPS.
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }
    }
}
