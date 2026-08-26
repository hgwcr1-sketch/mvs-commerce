<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DataCenterController extends Controller
{
    private const ENTRY_PERMISSIONS = [
        'compras.crear',
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
        $this->authorizeAny($request, $company, ['compras.crear', 'inventario.ver']);

        return view('data-center.imports');
    }

    public function exports(Request $request): View
    {
        $company = $this->company();
        $this->authorizeAny($request, $company, ['reportes.exportar']);

        return view('data-center.exports');
    }

    public function reports(Request $request): View
    {
        $company = $this->company();
        $this->authorizeAny($request, $company, ['reportes.ver']);

        return view('data-center.reports');
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
