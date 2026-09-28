<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateCompanyCashSettingRequest;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\CompanyCashSetting;
use App\Services\CompanyCashSettingsProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CompanyCashSettingController extends Controller
{
    public function edit(CompanyCashSettingsProvisioner $provisioner): View
    {
        $company = $this->activeCompany();
        $cashSetting = $provisioner->provision($company);

        return view('settings.cash.edit', [
            'cashSetting' => $cashSetting,
            'branchCount' => $company->branches()->count(),
            'activeRegisterCount' => CashRegister::forCompany($company->id)->active()->count(),
        ]);
    }

    public function update(
        UpdateCompanyCashSettingRequest $request,
        CompanyCashSettingsProvisioner $provisioner,
    ): RedirectResponse {
        $company = $this->activeCompany();
        $data = $request->safe()->only([
            'allow_multiple_registers',
            'require_open_session',
            'session_mode',
            'blind_closing',
            'accepts_usd',
            'usd_exchange_rate_min',
            'usd_exchange_rate_max',
            'usd_change_policy',
            'difference_tolerance',
            'require_difference_authorization',
            'auto_print_closure',
            'closure_email_recipients',
        ]);

        DB::transaction(function () use ($company, $data, $provisioner): void {
            $provisioner->provision($company);
            $cashSetting = CompanyCashSetting::query()
                ->where('company_id', $company->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (!$cashSetting->require_open_session && $data['require_open_session']) {
                $missingBranches = $company->branches()->where('is_active', true)
                    ->whereDoesntHave('cashRegisters', fn ($query) => $query->where('is_active', true))
                    ->pluck('name');
                if ($missingBranches->isNotEmpty()) {
                    throw ValidationException::withMessages(['require_open_session' => 'Falta una caja activa en: '.$missingBranches->join(', ').'.']);
                }
            }

            if ($cashSetting->allow_multiple_registers && !$data['allow_multiple_registers']) {
                $hasMultipleActive = CashRegister::query()
                    ->forCompany($company->id)
                    ->active()
                    ->select('branch_id')
                    ->groupBy('branch_id')
                    ->havingRaw('COUNT(*) > 1')
                    ->exists();

                if ($hasMultipleActive) {
                    throw ValidationException::withMessages([
                        'allow_multiple_registers' => 'No puede desactivar múltiples cajas mientras una sucursal tenga más de una caja activa.',
                    ]);
                }
            }

            if ($cashSetting->session_mode !== $data['session_mode']) {
                $hasOpenSessions = CashSession::query()
                    ->forCompany($company->id)
                    ->whereIn('status', [CashSession::STATUS_OPEN, CashSession::STATUS_CLOSING])
                    ->exists();

                if ($hasOpenSessions) {
                    throw ValidationException::withMessages([
                        'session_mode' => 'No puede cambiar el modo de sesión mientras existan sesiones de caja abiertas.',
                    ]);
                }
            }

            $cashSetting->update($data);
        });

        return redirect()->route('settings.cash.edit')
            ->with('success', 'Configuración de Caja actualizada correctamente.');
    }

    private function activeCompany(): Company
    {
        $companyId = session('active_company_id');
        abort_unless($companyId, 404);

        return Company::query()->findOrFail((int) $companyId);
    }
}
