<?php

namespace App\Services\Cobros;

use App\Models\CobrosRoute;
use App\Models\CobrosRouteUser;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Ruta activa del cobrador en sesión + resolución segura (tenant/activa/licenciada). */
class CobrosRouteContext
{
    /**
     * Rutas que el usuario puede operar en la empresa activa: asignadas con
     * pivot activo, ruta activa y licencia de ruta vigente. Nunca cruza tenant.
     */
    public function available(User $user): Collection
    {
        $companyId = (int) session('active_company_id', 0);
        $company = Company::find($companyId);
        if ($company === null || ! $user->is_active
            || ! $user->companies()->where('companies.id', $companyId)->exists()
            || ! $user->hasPermission('cobros.cobrar', $company)) {
            return collect();
        }

        $routeIds = CobrosRouteUser::forCompany($companyId)
            ->where('user_id', $user->id)->where('active', true)->pluck('cobros_route_id');
        if ($routeIds->isEmpty()) {
            return collect();
        }

        $licenses = app(CollectorLicenseService::class);

        return CobrosRoute::forCompany($companyId)->whereIn('id', $routeIds)
            ->where('active', true)->orderBy('name')->get()
            ->filter(fn (CobrosRoute $route) => $licenses->hasValidRouteLicense($route, $company))
            ->values();
    }

    public function select(User $user, int $routeId): CobrosRoute
    {
        $route = $this->resolve($user, $routeId);
        session(['active_cobros_route_id' => $route->id]);

        return $route;
    }

    public function current(User $user): CobrosRoute
    {
        return $this->resolve($user, (int) session('active_cobros_route_id', 0));
    }

    private function resolve(User $user, int $routeId): CobrosRoute
    {
        $companyId = (int) session('active_company_id', 0);
        $company = Company::find($companyId);
        $route = CobrosRoute::forCompany($companyId)->whereKey($routeId)->where('active', true)->first();
        if (! $company || ! $route || ! $user->is_active
            || ! $user->companies()->where('companies.id', $companyId)->exists()
            || ! $user->hasPermission('cobros.cobrar', $company)
            || ! CobrosRouteUser::forCompany($companyId)->where('cobros_route_id', $routeId)
                ->where('user_id', $user->id)->where('active', true)->exists()
            || ! app(CollectorLicenseService::class)->hasValidRouteLicense($route, $company)) {
            throw ValidationException::withMessages(['cobros_route_id' => 'No puede operar esta ruta en la empresa activa.']);
        }

        return $route;
    }
}
