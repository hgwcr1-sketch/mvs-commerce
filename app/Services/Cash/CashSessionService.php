<?php

namespace App\Services\Cash;

use App\Models\Branch;
use App\Models\CashCountDetail;
use App\Models\CashDenomination;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\CashSessionEvent;
use App\Models\CashSessionMailNotification;
use App\Models\Company;
use App\Models\CompanyCashSetting;
use App\Models\CompanySequence;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashSessionService
{
    public function __construct(private readonly CashSessionMailNotificationService $mailNotifications) {}

    public function open(array $data, User $user, int $companyId, int $branchId): CashSession
    {
        try {
            return DB::transaction(function () use ($data, $user, $companyId, $branchId) {
                $company = Company::where('is_active', true)->find($companyId);
                $branch = Branch::where('company_id', $companyId)->where('is_active', true)->find($branchId);
                if (! $company || ! $branch || ! $user->companies()->whereKey($companyId)->exists() || ! $user->branches()->whereKey($branchId)->exists()) {
                    throw ValidationException::withMessages(['context' => 'La empresa o sucursal activa no está autorizada.']);
                }
                if (strtoupper(trim((string) $company->currency)) !== 'CRC') {
                    throw ValidationException::withMessages(['currency' => 'La apertura de Caja sólo admite empresas con moneda CRC.']);
                }
                $openingDetails = collect();
                if (array_key_exists('denominations', $data)) {
                    $denominations = CashDenomination::forCompany($companyId)->forCurrency('CRC')->active()->orderBy('id')->get();
                    $ids = collect(array_keys($data['denominations']))->map(fn ($id) => (int) $id)->sort()->values();
                    if ($denominations->isEmpty() || $ids->all() !== $denominations->pluck('id')->all()) {
                        throw ValidationException::withMessages(['denominations' => 'Complete exactamente las denominaciones activas de esta empresa.']);
                    }
                    $data['opening_amount'] = '0.0000';
                    foreach ($denominations as $denomination) {
                        $quantity = $data['denominations'][$denomination->id];
                        if (filter_var($quantity, FILTER_VALIDATE_INT) === false || $quantity < 0 || $quantity > 1000000) {
                            throw ValidationException::withMessages(['denominations' => 'Las cantidades deben ser enteros entre 0 y 1000000.']);
                        }
                        $total = bcmul($denomination->value, (string) $quantity, 4);
                        $data['opening_amount'] = bcadd($data['opening_amount'], $total, 4);
                        $openingDetails->push(['cash_denomination_id' => $denomination->id, 'quantity' => $quantity, 'denomination_value' => $denomination->value, 'total_amount' => $total]);
                    }
                }
                $settings = CompanyCashSetting::where('company_id', $companyId)->lockForUpdate()->firstOrFail();
                $register = CashRegister::where('company_id', $companyId)->where('branch_id', $branchId)->lockForUpdate()->find($data['cash_register_id']);
                if (! $register || ! $register->is_active) {
                    throw ValidationException::withMessages(['cash_register_id' => 'La caja no está disponible en la sucursal activa.']);
                }
                if (CashSession::where('cash_register_id', $register->id)->whereIn('status', [CashSession::STATUS_OPEN, CashSession::STATUS_CLOSING])->exists()) {
                    throw ValidationException::withMessages(['cash_register_id' => 'Esta caja ya tiene una sesión abierta.']);
                }
                if ($settings->session_mode === CompanyCashSetting::SESSION_MODE_INDIVIDUAL && CashSession::forCompany($companyId)->forBranch($branchId)->where('opened_by', $user->id)->whereIn('status', [CashSession::STATUS_OPEN, CashSession::STATUS_CLOSING])->exists()) {
                    throw ValidationException::withMessages(['cash_register_id' => 'Ya tiene una sesión de caja abierta en esta sucursal.']);
                }
                $accepts = (bool) $settings->accepts_usd;
                $rate = $accepts ? (float) $data['usd_exchange_rate'] : null;
                $usd = $accepts ? (float) ($data['opening_amount_usd'] ?? 0) : 0;
                $now = now();
                $session = CashSession::create(['company_id' => $companyId, 'branch_id' => $branchId, 'cash_register_id' => $register->id, 'session_number' => CompanySequence::nextCashSessionNumber($companyId), 'opened_by' => $user->id, 'status' => CashSession::STATUS_OPEN, 'open_guard' => CashSession::OPEN_GUARD, 'currency_code' => 'CRC', 'opening_amount' => $data['opening_amount'], 'tolerance_snapshot' => $settings->difference_tolerance, 'opened_at' => $now, 'blind_closing_snapshot' => $settings->blind_closing, 'accepts_usd_snapshot' => $accepts, 'usd_exchange_rate' => $rate, 'exchange_rate_entered_by' => $accepts ? $user->id : null, 'opening_amount_usd' => $usd, 'usd_change_policy_snapshot' => $accepts ? $settings->usd_change_policy : CompanyCashSetting::USD_CHANGE_CRC_ONLY]);
                foreach ($openingDetails as $detail) {
                    $session->countDetails()->create($detail + ['count_type' => CashCountDetail::TYPE_OPENING, 'counted_by' => $user->id, 'counted_at' => $now]);
                }
                CashSessionEvent::create(['cash_session_id' => $session->id, 'event_type' => CashSessionEvent::TYPE_OPENED, 'user_id' => $user->id, 'occurred_at' => $now, 'payload' => ['cash_register_id' => $register->id, 'cash_register' => $register->name, 'opening_amount_crc' => bcadd((string) $data['opening_amount'], '0', 4), 'accepts_usd' => $accepts, 'usd_exchange_rate' => $rate, 'opening_amount_usd' => number_format($usd, 4, '.', '')]]);
                $this->mailNotifications->create($session, CashSessionMailNotification::TYPE_OPENED, $settings);

                return $session;
            });
        } catch (QueryException $e) {
            if (str_contains(strtolower($e->getMessage()), 'open_guard') || str_contains(strtolower($e->getMessage()), 'unique')) {
                throw ValidationException::withMessages(['cash_register_id' => 'Esta caja ya tiene una sesión abierta.']);
            } throw $e;
        }
    }
}
