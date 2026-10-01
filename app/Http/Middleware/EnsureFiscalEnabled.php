<?php

namespace App\Http\Middleware;

use App\Services\Fiscal\FiscalAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloquea el acceso directo a las rutas tenant de facturación electrónica
 * cuando la licencia de la empresa tiene fiscal_enabled = false.
 */
class EnsureFiscalEnabled
{
    public function __construct(private readonly FiscalAccessService $fiscalAccess) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->fiscalAccess->enabledForRequest($request), 403);

        return $next($request);
    }
}
