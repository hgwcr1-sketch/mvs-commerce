<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Company;

class EnsureActiveBranch
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return $next($request);
        }

        $companyId = session('active_company_id');
        $branchId = session('active_branch_id');

        if (!$companyId) {
            return $next($request);
        }

        $company = Company::find($companyId);

        /**
         * Determinar si el usuario tiene permiso de administrador global
         * (platform admin O usuario con permiso dashboard.admin para la empresa).
         * Estos usuarios NO deben auto-seleccionar branch y permanecerán
         * con active_branch_id = null para solicitar selección explícita.
         */
        $hasGlobalAdminPermission = $user->hasPermission('dashboard.admin', $company);
        $isGlobalAdmin = $user->isPlatformAdmin() || $hasGlobalAdminPermission;

        if ($branchId) {

            $hasAccess = $user->branches()
                ->where('branches.id', $branchId)
                ->where('branches.company_id', $companyId)
                ->where('branches.is_active', true)
                ->exists();

            if ($hasAccess) {
                return $next($request);
            }

            session()->forget('active_branch_id');
        }

        /**
         * Si el admin global (platform o dashboard.admin) no tiene branch seleccionada,
         * no auto-seleccionar. Dejará active_branch_id como null y el contexto
         * POS solicitará elección explícita.
         */
        if ($isGlobalAdmin && !$branchId) {
            return $next($request);
        }

        /**
         * Seleccionar automáticamente una sucursal válida
         * para usuarios no-admin (cajeros y roles operativos).
         */
        $branch = $user->branches()
            ->where('branches.company_id', $companyId)
            ->where('branches.is_active', true)
            ->orderBy('branches.id')
            ->first();

        if ($branch) {

            session([
                'active_branch_id' => $branch->id,
            ]);

            return $next($request);
        }

        /**
         * El usuario no tiene sucursales asignadas.
         */
        abort(403, 'No tiene una sucursal asignada para esta empresa.');
    }
}