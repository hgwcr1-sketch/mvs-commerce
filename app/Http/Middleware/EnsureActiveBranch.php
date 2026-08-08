<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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

        /**
         * Verificar si la sucursal activa sigue siendo
         * válida y pertenece al usuario y empresa activa.
         */
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
         * Seleccionar automáticamente una sucursal válida.
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