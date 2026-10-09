<?php

namespace App\Http\Controllers;

use App\Services\CompanySwitchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * BLOQUE 13-2B — selector de empresa/prestamista para usuarios vinculados
 * a varias empresas. Cambia el contexto de sesión sin cerrarla; la validación
 * de acceso, operaciones sensibles y limpieza de contexto viven en el servicio.
 */
class CompanySwitchController extends Controller
{
    public function index(Request $request, CompanySwitchService $service): View
    {
        return view('company.switch', [
            'memberships' => $service->switchable($request->user()),
            'activeCompanyId' => (int) session('active_company_id', 0),
        ]);
    }

    public function store(Request $request, CompanySwitchService $service): RedirectResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer'],
        ], [
            'company_id.required' => 'Seleccione una empresa.',
            'company_id.integer' => 'Seleccione una empresa válida.',
        ]);

        $companyId = (int) $data['company_id'];
        $currentCompanyId = (int) session('active_company_id', 0);

        // Idempotente: si ya opera ahí, no hay cambio ni validación extra.
        if ($companyId === $currentCompanyId) {
            return redirect()->route('dashboard')
                ->with('success', 'Ya opera en esta empresa.');
        }

        $company = $service->switchTo($request->user(), $companyId);

        return redirect()->route('dashboard')
            ->with('success', 'Empresa activa: '.$company->trade_name);
    }
}
