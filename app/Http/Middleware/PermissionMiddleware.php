<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PermissionMiddleware
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = auth()->user();

        $company = session('active_company_id');

        if (!$user || !$company) {
            abort(403, 'Acceso no autorizado.');
        }

        $companyModel = \App\Models\Company::find($company);

        if (!$companyModel) {
            abort(403, 'Empresa no encontrada.');
        }

        if (!$user->hasPermission($permission, $companyModel)) {
            abort(403, 'No tiene permiso para acceder a este módulo.');
        }

        return $next($request);
    }
}