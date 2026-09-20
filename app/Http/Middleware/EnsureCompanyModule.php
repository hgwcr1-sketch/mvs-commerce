<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCompanyModule
{
    public function handle(Request $request, Closure $next, string $moduleKey): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $companyId = session('active_company_id');

        if (! $companyId) {
            return $next($request);
        }

        $company = Company::find($companyId);

        if (! $company) {
            return $next($request);
        }

        if (! $company->isModuleEnabled($moduleKey)) {
            abort(403, 'Este módulo no está habilitado para la empresa.');
        }

        return $next($request);
    }
}
