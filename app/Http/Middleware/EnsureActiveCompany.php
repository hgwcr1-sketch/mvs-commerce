<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveCompany
{
    /**
     * Garantiza que el usuario tenga una empresa
     * y una sucursal activa válidas.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return $next($request);
        }

        $activeCompanyId = session('active_company_id');

        /**
         * Verificar empresa activa.
         */
        if ($activeCompanyId) {

            $hasAccess = $user->companies()
                ->where('companies.id', $activeCompanyId)
                ->exists();

            if (!$hasAccess) {
                session()->forget([
                    'active_company_id',
                    'active_branch_id',
                ]);

                $activeCompanyId = null;
            }
        }

        /**
         * Seleccionar automáticamente una empresa
         * si todavía no existe una válida.
         */
        if (!$activeCompanyId) {

            $company = $user->companies()
                ->where('companies.is_active', true)
                ->orderBy('companies.id')
                ->first();

            if (!$company) {
                return $next($request);
            }

            $activeCompanyId = $company->id;

            session([
                'active_company_id' => $activeCompanyId,
            ]);
        }

        /**
         * Verificar sucursal activa.
         */
        $activeBranchId = session('active_branch_id');

        if ($activeBranchId) {

            $hasBranchAccess = $user->branches()
                ->where('branches.id', $activeBranchId)
                ->where('branches.company_id', $activeCompanyId)
                ->where('branches.is_active', true)
                ->exists();

            if (!$hasBranchAccess) {
                session()->forget('active_branch_id');
                $activeBranchId = null;
            }
        }

        /**
         * Seleccionar automáticamente la primera
         * sucursal disponible del usuario.
         */
        if (!$activeBranchId) {

            $branch = $user->branches()
                ->where('branches.company_id', $activeCompanyId)
                ->where('branches.is_active', true)
                ->orderBy('branches.id')
                ->first();

            if ($branch) {
                session([
                    'active_branch_id' => $branch->id,
                ]);
            }
        }

        return $next($request);
    }
}