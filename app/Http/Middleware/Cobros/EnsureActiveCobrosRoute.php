<?php

namespace App\Http\Middleware\Cobros;

use App\Models\Company;
use App\Services\Cobros\CobrosRouteContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * El cobrador opera siempre dentro de UNA ruta activa de la empresa activa.
 * - 1 ruta disponible: se selecciona sola (sin selector innecesario).
 * - >1: exige selección explícita previa (o "Cambiar ruta").
 * - 0: sin contexto que imponer (cartera legada sigue operativa).
 * Dueño/supervisor nunca queda atrapado en el selector.
 */
class EnsureActiveCobrosRoute
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null || $request->routeIs('cobros.routes.select', 'cobros.routes.store')) {
            return $next($request);
        }

        $company = Company::find((int) session('active_company_id', 0));
        if ($company === null
            || ! $user->hasPermission('cobros.cobrar', $company)
            || $user->hasPermission('cobros.portal_dueno', $company)
            || $user->hasPermission('cobros.supervisar', $company)) {
            return $next($request);
        }

        $context = app(CobrosRouteContext::class);
        $available = $context->available($user);

        if ($available->isEmpty()) {
            $request->session()->forget('active_cobros_route_id');

            return $next($request);
        }

        $selectedId = (int) session('active_cobros_route_id', 0);
        $isSelected = $available->contains(fn ($route) => $route->id === $selectedId);

        if (! $isSelected && $available->count() === 1) {
            $context->select($user, $available->first()->id);

            return $next($request);
        }

        if (! $isSelected) {
            return redirect()->route('cobros.routes.select');
        }

        return $next($request);
    }
}