<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\Exports\DataExportService;
use App\Services\Reports\EssentialReportQuery;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DataCenterController extends Controller
{
    private const ENTRY_PERMISSIONS = [
        'compras.crear',
        'clientes.crear',
        'productos.crear',
        'ventas.crear',
        'inventario.ver',
        'reportes.exportar',
        'reportes.ver',
    ];

    public function index(Request $request): View
    {
        $company = $this->company();
        $this->authorizeAny($request, $company, self::ENTRY_PERMISSIONS);

        return view('data-center.index');
    }

    public function imports(Request $request): View
    {
        $company = $this->company();
        $this->authorizeAny($request, $company, ['compras.crear', 'clientes.crear', 'productos.crear', 'ventas.crear', 'inventario.ver']);

        return view('data-center.imports');
    }

    public function exports(Request $request): View
    {
        $company = $this->company();
        $this->authorizeAny($request, $company, ['reportes.exportar']);

        $datasets = collect(DataExportService::DATASETS)->filter(
            fn (array $definition) => $request->user()->hasPermission($definition['permission'], $company),
        );
        $branches = $request->user()->branches()->where('company_id', $company->id)
            ->where('is_active', true)->orderBy('name')->get();
        $inventoryBranches = $request->user()->hasPermission('inventario.ver_otras_sucursales', $company)
            ? $branches
            : $branches->where('id', (int) session('active_branch_id'));

        return view('data-center.exports', compact('datasets', 'branches', 'inventoryBranches'));
    }

    public function reports(Request $request): View
    {
        $company = $this->company();
        $this->authorizeAny($request, $company, ['reportes.ver']);

        $categories = collect(EssentialReportQuery::CATEGORIES)->filter(
            fn (array $definition) => $request->user()->hasPermission($definition['permission'], $company),
        );

        return view('data-center.reports', compact('categories'));
    }

    private function company(): Company
    {
        return Company::query()->findOrFail((int) session('active_company_id'));
    }

    private function authorizeAny(Request $request, Company $company, array $permissions): void
    {
        abort_unless(
            collect($permissions)->contains(
                fn (string $permission) => $request->user()->hasPermission($permission, $company),
            ),
            403,
        );
    }
}
