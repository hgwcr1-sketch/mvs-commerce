<?php

namespace App\Http\Middleware\Cobros;

use App\Models\CobrosBillingContract;
use App\Models\CobrosCollectorLicense;
use App\Models\CobrosRoute;
use App\Models\CobrosRouteUser;
use App\Models\Company;
use App\Models\User;
use App\Services\Cobros\CollectorLicenseService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CobrOS-specific company resolution.
 *
 * Reuses the general EnsureActiveCompany logic for session management
 * but NEVER redirects to Commerce routes (empresa.create, platform.index).
 * Instead aborts 403 if the user has no valid company.
 */
class EnsureActiveCompany
{
    /** 18C-2A/2B: único aviso de bloqueo — sin fechas, importes ni membresías. */
    public const SUSPENDED_MESSAGE = 'Acceso temporalmente suspendido. Contactá a tu administrador';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $activeCompanyId = session('active_company_id');

        if ($activeCompanyId) {
            $hasAccess = $user->companies()
                ->where('companies.id', $activeCompanyId)
                ->exists();

            if (! $hasAccess) {
                session()->forget(['active_company_id', 'active_branch_id']);
                $activeCompanyId = null;
            }
        }

        if (! $activeCompanyId) {
            $company = $user->companies()
                ->where('companies.is_active', true)
                ->orderBy('companies.id')
                ->first();

            if (! $company) {
                abort(403, 'No tiene una empresa activa para operar en CobrOS.');
            }

            $activeCompanyId = $company->id;
            session(['active_company_id' => $activeCompanyId]);
        }

        // Licenciamiento individual: sin licencia vigente no se opera CobrOS.
        // 18C-2A: la única excepción es la puerta de pago de la membresía
        // (cobros.payment.*), y solo para el pagador del contrato abierto de
        // ESTA empresa (payer_user_id persistido, nunca por rol). Auth,
        // permisos y la autorización por contrato siguen intactos en rutas y
        // en PaymentMethodSelectionController::authorizeContract.
        $company = $company ?? Company::find($activeCompanyId);
        if ($company !== null
            && ! $this->allowsMembershipPayment($request, $user, $company)) {
            $licenses = app(CollectorLicenseService::class);
            if (! $licenses->hasValidLicense($user, $company)) {
                // 18C-2A: sin fechas, importes ni detalles financieros.
                abort(403, self::SUSPENDED_MESSAGE);
            }

            // 18C-2B: licencia de la RUTA activa realmente vencida o
            // suspendida => no se opera por URL directa. La licencia
            // individual de arriba ya cubre al cobrador en modalidad
            // individual; aquí se cubre la ruta en cualquier modalidad.
            if ($this->routeLicenseSuspended($request, $user, $company, $licenses)) {
                abort(403, self::SUSPENDED_MESSAGE);
            }
        }

        return $next($request);
    }

    /**
     * 18C-2A — La puerta de membresía (cobros.payment.*) queda reservada al
     * pagador autorizado aunque su licencia individual esté vencida, para que
     * pueda regularizar el pago. Cualquier otro usuario conserva el bloqueo.
     */
    private function allowsMembershipPayment(Request $request, User $user, Company $company): bool
    {
        if (! $request->routeIs('cobros.payment.*')) {
            return false;
        }

        return CobrosBillingContract::query()
            ->where('company_id', $company->id)
            ->whereIn('status', CobrosBillingContract::OPEN_STATUSES)
            ->where('payer_user_id', $user->id)
            ->exists();
    }

    /**
     * 18C-2B — Vigencia de la licencia de la ruta seleccionada en sesión.
     *
     * Relaciones reales, sin inventar nada: sesión `active_cobros_route_id`
     * -> cobros_routes (empresa activa) -> pivot cobros_route_user activo
     * -> cobros_route_licenses (CollectorLicenseService::licenseForRoute).
     * Solo VENCIDA o SUSPENDIDA bloquea; ausente o pendiente no lo hace
     * (puede seguir operando por otra licencia válida).
     */
    private function routeLicenseSuspended(
        Request $request,
        User $user,
        Company $company,
        CollectorLicenseService $licenses,
    ): bool {
        // El selector de ruta queda accesible para cambiar a una vigente.
        if ($request->routeIs('cobros.routes.select', 'cobros.routes.store')) {
            return false;
        }

        // Solo el operador de cartera: el prestamista/dueño que administra
        // cobradores y el supervisor no dependen de la licencia de una ruta.
        if (! $user->hasPermission('cobros.cobrar', $company)
            || $user->hasPermission('cobros.portal_dueno', $company)
            || $user->hasPermission('cobros.supervisar', $company)) {
            return false;
        }

        $routeId = (int) $request->session()->get('active_cobros_route_id', 0);
        if ($routeId <= 0) {
            return false;
        }

        $route = CobrosRoute::forCompany($company->id)->whereKey($routeId)->first();
        if ($route === null
            || ! CobrosRouteUser::forCompany($company->id)
                ->where('cobros_route_id', $route->id)
                ->where('user_id', $user->id)
                ->where('active', true)
                ->exists()) {
            return false;
        }

        $license = $licenses->licenseForRoute($route, $company);
        if ($license === null) {
            return false;
        }

        $state = $licenses->stateOf($license, $company);

        return $state === CobrosCollectorLicense::STATE_EXPIRED
            || $state === CobrosCollectorLicense::STATE_SUSPENDED;
    }
}
