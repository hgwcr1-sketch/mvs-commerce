<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Cobros\CobrosRouteContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Selector móvil de ruta del cobrador. La validación (tenant, ruta activa,
 * asignación propia y licencia vigente) vive en CobrosRouteContext::select:
 * nunca se acepta un route_id ajeno, inactivo o sin licencia.
 */
class RouteSelectorController extends Controller
{
    public function index(Request $request, CobrosRouteContext $context): View|RedirectResponse
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $routes = $context->available($request->user());

        // Una sola ruta disponible: se entra directo, sin selector innecesario.
        if ($routes->count() === 1) {
            $context->select($request->user(), $routes->first()->id);

            return redirect()->route('cobros.collector.portfolio');
        }

        return view('cobros.collector.route-select', [
            'company' => $company,
            'routes' => $routes,
        ]);
    }

    public function store(Request $request, CobrosRouteContext $context): RedirectResponse
    {
        $data = $request->validate([
            'route_id' => ['required', 'integer'],
        ], [
            'route_id.required' => 'Seleccione una ruta.',
            'route_id.integer' => 'Seleccione una ruta válida.',
        ]);

        // Resuelve contra tenant + activa + asignada + licenciada (o ValidationException).
        $route = $context->select($request->user(), (int) $data['route_id']);

        return redirect()->route('cobros.collector.portfolio')
            ->with('success', "Ruta: {$route->name}");
    }
}