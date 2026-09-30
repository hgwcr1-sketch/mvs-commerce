<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Cobros\CollectorAccessService;
use App\Services\Cobros\SupervisorPanelService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CobrosDashboardController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        foreach ([
            'cobros.portal_dueno' => 'cobros.owner.dashboard',
            'cobros.supervisar' => 'cobros.supervisor.index',
            'cobros.cobrar' => 'cobros.collector.index',
        ] as $permission => $route) {
            if ($request->user()->hasPermission($permission, $company)) {
                return redirect()->route($route);
            }
        }

        abort(403, 'No tiene un permiso operativo de CobrOS.');
    }

    /**
     * Fase 4A — panel operativo del supervisor: solo lectura, sin acciones
     * financieras nuevas. Todo acotado a la empresa activa de la sesión.
     */
    public function supervisor(Request $request, SupervisorPanelService $panel): View
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $filters = $request->validate(['branch_id' => ['nullable', 'integer', 'min:1']]);
        $branchId = isset($filters['branch_id']) ? (int) $filters['branch_id'] : null;

        $branches = $panel->filterableBranches($request->user(), $company);
        if ($branchId !== null && ! $branches->contains('id', $branchId)) {
            // Sucursal ajena a la empresa o fuera del alcance: 404 seguro.
            abort(404);
        }

        $data = $panel->panel($company, (int) $request->user()->id, $branchId);

        return view('cobros.supervisor.panel', [
            'company' => $company,
            'branches' => $branches,
            'branchId' => $branchId,
            ...$data,
        ]);
    }

    public function collector(Request $request, CollectorAccessService $access): View
    {
        $company = Company::findOrFail((int) session('active_company_id'));

        return view('cobros.operational-entry', [
            'company' => $company,
            'branches' => $access->branchesForCollecting($request->user(), $company),
            'title' => 'Portal del cobrador',
            'notice' => 'Mi cartera se construirá en la Fase 2A, después de la asignación de cartera de la Fase 1E.',
            'branchLabel' => 'Sucursales operativas asignadas',
        ]);
    }
}
