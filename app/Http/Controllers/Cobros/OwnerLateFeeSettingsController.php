<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 9B.3 — configuración GENERAL del reparto de mora (solo prestamista).
 *
 * "¿Qué porcentaje de la mora gana normalmente el cobrador?" Default 50%.
 * No altera deuda, mora del cliente, CxC ni cuotas; es participación interna.
 */
class OwnerLateFeeSettingsController extends Controller
{
    public function edit(Request $request): View
    {
        $company = Company::findOrFail((int) $request->session()->get('active_company_id'));

        return view('cobros.owner.late-fee-settings', [
            'company' => $company,
            'generalPct' => (string) ($company->collector_late_fee_pct ?? '50.00'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $company = Company::findOrFail((int) session('active_company_id'));

        $data = $request->validate([
            'collector_late_fee_pct' => ['required', 'string', 'regex:/^\d{1,3}(\.\d{1,2})?$/D', 'lte:100'],
        ], [
            'collector_late_fee_pct.required' => 'Escriba el porcentaje de mora del cobrador.',
            'collector_late_fee_pct.regex' => 'Escriba un porcentaje válido, por ejemplo 50 o 40.50.',
            'collector_late_fee_pct.lte' => 'El porcentaje no puede ser mayor a 100.',
        ]);

        $company->update(['collector_late_fee_pct' => $data['collector_late_fee_pct']]);

        return redirect()
            ->route('cobros.owner.latefee')
            ->with('success', 'Listo · Se guardó el porcentaje general de mora del cobrador.');
    }
}
