<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PermissionMiddleware
{
    /**
     * Acepta uno o varios permisos separados por coma (ej. permission:a,b):
     * basta con poseer AL MENOS UNO. Con un solo permiso se comporta igual
     * que antes.
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
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

        $authorized = false;
        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission, $companyModel)) {
                $authorized = true;
                break;
            }
        }

        if (!$authorized) {
            abort(403, 'No tiene permiso para acceder a este módulo.');
        }

        return $next($request);
    }
}