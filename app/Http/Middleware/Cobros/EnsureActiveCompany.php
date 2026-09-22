<?php

namespace App\Http\Middleware\Cobros;

use App\Models\Company;
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

        return $next($request);
    }
}
