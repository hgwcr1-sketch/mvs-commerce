<?php

namespace App\Http\Middleware\Cobros;

use App\Models\Company;
use App\Models\User;
use App\Services\Cobros\CobrosModalityCatalog;
use App\Services\Cobros\CobrosModalityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * BLOQUE 21D-FIX-3G1 ÔÇö autorizaci├│n de servidor por modalidad CobrOS.
 *
 * Control ├ÜNICO y reutilizable aplicado al grupo autenticado /cobros/*.
 * Reglas (decisiones globales):
 *  - Prestamista: administra; NO opera rutas collector/cobro/cierre/visitas.
 *  - Cobrador (vinculado, independiente, multiprestamista, prestamista-cobrador):
 *    opera su cartera/cobros; NO accede a rutas administrativas owner/supervisor.
 *  - Membres├¡as SIN modalidad: sin restricci├│n nueva (compatibilidad legacy;
 *    los permisos existentes siguen aplicando).
 *
 * NO otorga permisos: SOLO deniega (403) cuando la modalidad vigente contradice
 * la familia de ruta. No modifica roles, membres├¡as ni datos.
 */
class EnsureModalityAuthorization
{
    /** Familias operativas: exigen modalidad que COBRA (collects=true). */
    private const OPERATIONAL_PREFIXES = [
        'cobros.collector.',
        'cobros.closing.',
        'cobros.visits.',
    ];

    private const OPERATIONAL_EXACT = [
        'cobros.routes.select',
        'cobros.routes.store',
    ];

    /** Familias administrativas: exigen modalidad administrativa. */
    private const ADMINISTRATIVE_PREFIXES = [
        'cobros.owner.',
        'cobros.supervisor.',
    ];

    public function __construct(private readonly CobrosModalityService $modalities)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $companyId = (int) $request->session()->get('active_company_id', 0);

        if (! $user instanceof User || $companyId <= 0) {
            return $next($request);
        }

        $modality = $this->modalities->currentModality(
            Company::findOrFail($companyId),
            $user
        );

        // Sin modalidad definida: compatibilidad legacy, permisos existentes.
        if ($modality === null) {
            return $next($request);
        }

        $routeName = (string) $request->route()?->getName();
        if ($routeName === '') {
            return $next($request);
        }

        if ($this->isOperational($routeName) && ! CobrosModalityCatalog::collects($modality)) {
            abort(403, 'La modalidad actual no permite operaciones de cobro.');
        }

        if ($this->isAdministrative($routeName) && ! CobrosModalityCatalog::isAdministrative($modality)) {
            abort(403, 'La modalidad actual no permite funciones administrativas.');
        }

        return $next($request);
    }

    private function isOperational(string $routeName): bool
    {
        if (in_array($routeName, self::OPERATIONAL_EXACT, true)) {
            return true;
        }

        foreach (self::OPERATIONAL_PREFIXES as $prefix) {
            if (str_starts_with($routeName, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function isAdministrative(string $routeName): bool
    {
        foreach (self::ADMINISTRATIVE_PREFIXES as $prefix) {
            if (str_starts_with($routeName, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
