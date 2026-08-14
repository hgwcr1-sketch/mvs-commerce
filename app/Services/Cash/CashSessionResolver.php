<?php

namespace App\Services\Cash;

use App\Models\{CashSession, Company, CompanyCashSetting, User};
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use App\Services\CompanyCashSettingsProvisioner;

class CashSessionResolver
{
    public function __construct(private readonly CompanyCashSettingsProvisioner $settingsProvisioner) {}

    public function applicable(User $user, int $companyId, int $branchId, bool $lock = false): Collection
    {
        $company = Company::findOrFail($companyId);
        if (!$user->companies()->whereKey($companyId)->exists() || !$user->branches()->whereKey($branchId)->exists()) {
            throw ValidationException::withMessages(['cash_session_id' => 'No está autorizado para cobrar en esta sucursal.']);
        }
        $settings = $this->settingsProvisioner->provision($company);
        $query = CashSession::query()->forCompany($companyId)->forBranch($branchId)
            ->where('cash_sessions.status', CashSession::STATUS_OPEN)->where('open_guard', CashSession::OPEN_GUARD)
            ->whereHas('cashRegister', fn ($q) => $q->where('is_active', true));
        if ($settings->session_mode === CompanyCashSetting::SESSION_MODE_INDIVIDUAL) $query->where('opened_by', $user->id);
        if ($lock) $query->lockForUpdate();
        return $query->with('cashRegister:id,name,is_active')->orderBy('id')->get();
    }

    public function resolve(User $user, int $companyId, int $branchId, ?int $requestedId, bool $lock = false): ?CashSession
    {
        $company = Company::findOrFail($companyId);
        if (!$user->hasPermission('pos.acceder', $company) || !$user->hasPermission('ventas.crear', $company)) {
            throw ValidationException::withMessages(['cash_session_id' => 'No está autorizado para cobrar en esta sucursal.']);
        }
        $settings = $this->settingsProvisioner->provision($company);
        $sessions = $this->applicable($user, $companyId, $branchId, $lock);
        if ($requestedId !== null) {
            $selected = $sessions->firstWhere('id', $requestedId);
            if (!$selected) throw ValidationException::withMessages(['cash_session_id' => 'La sesión de caja seleccionada no está disponible.']);
            return $selected;
        }
        if ($sessions->count() > 1) throw ValidationException::withMessages(['cash_session_id' => $settings->session_mode === CompanyCashSetting::SESSION_MODE_SHARED ? 'Seleccione explícitamente una sesión de caja.' : 'Existen varias sesiones individuales inconsistentes.']);
        if ($sessions->isEmpty() && $settings->require_open_session) throw ValidationException::withMessages(['cash_session_id' => 'Debe abrir una caja antes de cobrar.']);
        return $sessions->first();
    }
}
