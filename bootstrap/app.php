<?php

use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SecurityHeaders;
use App\Services\AuditLogger;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Solo se atiende el dominio configurado en APP_URL (evita enlaces con un Host falsificado).
        $middleware->trustHosts(at: fn () => array_filter([parse_url((string) config('app.url'), PHP_URL_HOST)]), subdomains: false);
        $middleware->statefulApi();
        $middleware->append(SecurityHeaders::class);
        $middleware->throttleApi('api');
        $middleware->alias(['role' => EnsureUserHasRole::class, 'active' => EnsureUserIsActive::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // Un registro inexistente responde igual que cualquier 404: sin nombres de clases ni ids.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            return $request->is('api/*') ? response()->json(['message' => 'No se encontró lo solicitado.'], 404) : null;
        });
        // Los accesos denegados (403) quedan en la auditoría: suelen ser el primer rastro de un abuso.
        // (Laravel no los reporta al log; se anotan al responder y la respuesta sigue su curso normal.)
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() === 403 && $request->user()) {
                app(AuditLogger::class)->record('auth.denied', 'Acceso denegado: '.$request->method().' /'.$request->path());
            }

            return null;
        });
    })->create();
