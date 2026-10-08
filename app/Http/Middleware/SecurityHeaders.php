<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad para todas las respuestas (portal, API y app).
 * - CSP: solo se ejecuta JavaScript del propio sitio (bloquea XSS e inyección
 *   de scripts de terceros); nada puede mostrar el sitio dentro de un iframe.
 * - HSTS: el navegador usa siempre HTTPS.
 * - Otras: sin detección de tipo, referer mínimo, permisos del navegador
 *   limitados a cámara y ubicación del propio sitio.
 */
class SecurityHeaders
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        header_remove('X-Powered-By');
        $headers->remove('X-Powered-By');

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(self), geolocation=(self), microphone=(), payment=(), usb=(), interest-cohort=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // En desarrollo con el servidor de Vite (recarga en caliente) no se aplica la CSP.
        if (! Vite::isRunningHot()) {
            $headers->set('Content-Security-Policy', self::policy($request->isSecure(), nonce: Vite::cspNonce()));
        }

        // Nada de la API (datos, descargas de Excel, fotos de constancias) debe quedar en
        // cachés intermedias ni en el disco del navegador después de cerrar sesión.
        if ($request->is('api/*')) {
            $headers->set('Cache-Control', 'no-store, private');
            $headers->remove('Expires');
            $headers->remove('Pragma');
        }

        return $response;
    }

    /**
     * Política de contenido. También se incluye como <meta> en la página, porque
     * la CDN de Hostinger (opción "Forzar HTTPS") reemplaza la cabecera por la suya.
     * En <meta> no se admite frame-ancestors; X-Frame-Options cubre ese caso.
     *
     * @param  string|null  $nonce  nonce de Vite (Vite::cspNonce()) para la hoja de estilos en línea de las fuentes
     */
    public static function policy(bool $secure, bool $meta = false, ?string $nonce = null): string
    {
        return implode('; ', array_filter([
            "default-src 'self'",
            "script-src 'self'",
            // Hojas de estilo solo del sitio; los estilos en línea se limitan a atributos (gráficos, anchos).
            "style-src 'self' 'unsafe-inline'",
            // La única hoja en línea es la de las fuentes (@fonts), autorizada con el nonce de esta respuesta.
            "style-src-elem 'self'".($nonce ? " 'nonce-{$nonce}'" : ''),
            "style-src-attr 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self'",
            "connect-src 'self'",
            "worker-src 'self'",
            "manifest-src 'self'",
            "media-src 'self' blob:",
            "frame-src 'none'",
            $meta ? null : "frame-ancestors 'none'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            $secure ? 'upgrade-insecure-requests' : null,
        ]));
    }
}
