<?php

namespace App\Http\Middleware\Cobros;

use App\Services\Cobros\CobrosDemoAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * INTEGRACIÓN-05 — bloqueo del DEMO cuando no está habilitado.
 *
 * 404 (no 403) para no revelar la existencia de las rutas DEMO.
 * Cubre rutas, accesos directos con URL y todo controlador detrás del alias
 * `cobros.demo`. Autenticación, modalidad, throttling y sesiones siguen
 * aplicándose por separado.
 */
class EnsureDemoEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(CobrosDemoAccess::enabled(), 404);

        return $next($request);
    }
}
