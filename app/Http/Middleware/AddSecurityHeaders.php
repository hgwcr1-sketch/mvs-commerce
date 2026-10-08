<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AddSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Vista previa embebida del comprobante POS (modal post-venta):
        // solo esa ruta, solo lectura (GET/HEAD) y solo con embed=1 se
        // permite framing del MISMO origen. Cualquier otra ruta, método o
        // parámetro conserva DENY + frame-ancestors 'none'.
        $embedReceipt = $request->route() !== null
            && $request->routeIs('pos.receipt')
            && ($request->isMethod('get') || $request->isMethod('head'))
            && $request->boolean('embed');

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', $embedReceipt ? 'SAMEORIGIN' : 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(self), geolocation=(), microphone=()');
        $response->headers->set(
            'Content-Security-Policy',
            ($embedReceipt ? "frame-ancestors 'self'; " : "frame-ancestors 'none'; ")
                ."base-uri 'self'; form-action 'self'; object-src 'none'; worker-src 'self' blob:"
        );

        if (app()->environment('production') && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
