<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Services\Cobros\CollectorAccessService;
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

    public function supervisor(): View
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $branches = Branch::query()->where('company_id', $company->id)->orderBy('name')->get();

        return view('cobros.operational-entry', [
            'company' => $company, 'branches' => $branches,
            'title' => 'Portal del supervisor',
            'notice' => 'La supervisión operativa se construirá en la Fase 4A.',
            'branchLabel' => 'Sucursales de la empresa',
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
