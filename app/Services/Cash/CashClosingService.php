<?php

namespace App\Services\Cash;

use App\Models\Branch;
use App\Models\CashCountDetail;
use App\Models\CashPaymentReconciliation;
use App\Models\CashSession;
use App\Models\CashSessionEvent;
use App\Models\CashSessionMailNotification;
use App\Models\Company;
use App\Models\CompanyCashSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashClosingService
{
    public function __construct(
        private readonly CashExpectedAmountService $cashExpected,
        private readonly CashPaymentExpectedAmountService $paymentExpected,
        private readonly CashSessionMailNotificationService $mailNotifications,
    ) {}

    public function start(User $user, int $companyId, int $branchId, int $sessionId, string $token): CashSession
    {
        return DB::transaction(function () use ($user, $companyId, $branchId, $sessionId, $token) {
            [$session, $settings] = $this->lockedContext($user, $companyId, $branchId, $sessionId, 'caja.cerrar');
            if ($session->closing_request_token === $token && $session->status === CashSession::STATUS_CLOSING) return $session;
            if ($session->status !== CashSession::STATUS_OPEN || $session->open_guard !== CashSession::OPEN_GUARD) {
                throw ValidationException::withMessages(['cash_session_id' => 'La sesión ya no está disponible para iniciar el cierre.']);
            }
            $this->assertCanOperate($session, $settings, $user, false);
            $now = now();
            $session->update(['status' => CashSession::STATUS_CLOSING, 'closing_started_at' => $now, 'closing_started_by' => $user->id, 'closing_request_token' => $token]);
            CashSessionEvent::create(['cash_session_id' => $session->id, 'event_type' => CashSessionEvent::TYPE_CLOSING_STARTED, 'user_id' => $user->id, 'occurred_at' => $now, 'payload' => ['request_token' => $token]]);
            return $session->fresh();
        });
    }

    /** @return array{session: CashSession, duplicate: bool, requires_authorization: bool} */
    public function submit(User $user, int $companyId, int $branchId, int $sessionId, array $data): array
    {
        return DB::transaction(function () use ($user, $companyId, $branchId, $sessionId, $data) {
            [$session, $settings] = $this->lockedContext($user, $companyId, $branchId, $sessionId, 'caja.cerrar');
            if ($session->closing_confirmation_token === $data['request_token'] && $session->closing_submitted_at !== null) {
                return ['session' => $session, 'duplicate' => true, 'requires_authorization' => $session->status === CashSession::STATUS_CLOSING];
            }
            if ($session->status !== CashSession::STATUS_CLOSING || $session->open_guard !== CashSession::OPEN_GUARD || $session->closing_submitted_at !== null) {
                throw ValidationException::withMessages(['cash_session_id' => 'La sesión no está disponible para confirmar el cierre.']);
            }
            $this->assertCanOperate($session, $settings, $user, true);
            $denominations = \App\Models\CashDenomination::query()->forCompany($companyId)->forCurrency('CRC')->active()->orderBy('sort_order')->get();
            if ($denominations->count() !== 11) throw ValidationException::withMessages(['denominations' => 'La empresa debe tener las 11 denominaciones CRC activas.']);
            $submittedIds = collect(array_keys($data['denominations']))->map(fn ($id) => (int) $id)->sort()->values();
            $requiredIds = $denominations->pluck('id')->map(fn ($id) => (int) $id)->sort()->values();
            if ($submittedIds->all() !== $requiredIds->all()) throw ValidationException::withMessages(['denominations' => 'Debe indicar exactamente todas las denominaciones CRC activas.']);
            $methods = $this->paymentExpected->methods($session);
            $submittedMethodIds = collect(array_keys($data['payments'] ?? []))->map(fn ($id) => (int) $id)->sort()->values();
            $requiredMethodIds = $methods->pluck('id')->map(fn ($id) => (int) $id)->sort()->values();
            if ($submittedMethodIds->all() !== $requiredMethodIds->all()) throw ValidationException::withMessages(['payments' => 'Debe reportar exactamente todas las formas de pago requeridas.']);

            $now = now(); $countedCash = 0.0;
            foreach ($denominations as $denomination) {
                $quantity = (int) $data['denominations'][$denomination->id];
                $total = (float) $denomination->value * $quantity; $countedCash += $total;
                CashCountDetail::create(['cash_session_id' => $session->id, 'cash_denomination_id' => $denomination->id, 'count_type' => CashCountDetail::TYPE_CLOSING, 'quantity' => $quantity, 'denomination_value' => $denomination->value, 'total_amount' => $total, 'counted_by' => $user->id, 'counted_at' => $now]);
            }
            $expectedCash = $this->cashExpected->calculate($session); $cashDifference = $countedCash - $expectedCash;
            $expectedMethods = $this->paymentExpected->expectedAmounts($session); $differences = [abs($cashDifference)];
            foreach ($methods as $method) {
                $reported = (float) $data['payments'][$method->id]['reported_amount']; $expected = (float) $expectedMethods->get($method->id, 0); $difference = $reported - $expected; $differences[] = abs($difference);
                CashPaymentReconciliation::create(['cash_session_id' => $session->id, 'payment_method_id' => $method->id, 'payment_method_code_snapshot' => $method->code, 'payment_method_name_snapshot' => $method->name, 'payment_method_type_snapshot' => $method->type, 'expected_amount' => $expected, 'reported_amount' => $reported, 'difference_amount' => $difference, 'reference' => $data['payments'][$method->id]['reference'] ?? null, 'notes' => $data['payments'][$method->id]['notes'] ?? null, 'reconciled_by' => $user->id, 'reconciled_at' => $now]);
            }
            $requiresAuthorization = (bool) $settings->require_difference_authorization && collect($differences)->contains(fn ($difference) => $difference > (float) $session->tolerance_snapshot);
            $updates = ['expected_cash' => $expectedCash, 'counted_cash' => $countedCash, 'difference_amount' => $cashDifference, 'closing_confirmation_token' => $data['request_token'], 'closing_submitted_at' => $now, 'closing_notes' => $data['closing_notes'] ?? null];
            if (! $requiresAuthorization) $updates += ['status' => CashSession::STATUS_CLOSED, 'open_guard' => null, 'closed_by' => $user->id, 'closed_at' => $now];
            $session->update($updates);
            if (! $requiresAuthorization) {
                CashSessionEvent::create(['cash_session_id' => $session->id, 'event_type' => CashSessionEvent::TYPE_CLOSED, 'user_id' => $user->id, 'occurred_at' => $now, 'payload' => $this->closingPayload($session->fresh())]);
                $this->mailNotifications->create($session->fresh(), CashSessionMailNotification::TYPE_CLOSED, $settings);
            }
            return ['session' => $session->fresh(), 'duplicate' => false, 'requires_authorization' => $requiresAuthorization];
        });
    }

    public function cancel(User $user, int $companyId, int $branchId, int $sessionId): CashSession
    {
        return DB::transaction(function () use ($user, $companyId, $branchId, $sessionId) {
            [$session, $settings] = $this->lockedContext($user, $companyId, $branchId, $sessionId, 'caja.cerrar');
            if ($session->status !== CashSession::STATUS_CLOSING || $session->closing_submitted_at !== null || $session->countDetails()->closing()->exists() || $session->paymentReconciliations()->exists()) throw ValidationException::withMessages(['cash_session_id' => 'El cierre ya fue confirmado y no puede cancelarse.']);
            $this->assertCanOperate($session, $settings, $user, true); $now = now();
            $session->update(['status' => CashSession::STATUS_OPEN, 'closing_started_at' => null, 'closing_started_by' => null, 'closing_request_token' => null]);
            CashSessionEvent::create(['cash_session_id' => $session->id, 'event_type' => CashSessionEvent::TYPE_CLOSING_CANCELLED, 'user_id' => $user->id, 'occurred_at' => $now]);
            return $session->fresh();
        });
    }

    public function authorize(User $user, int $companyId, int $branchId, int $sessionId): CashSession
    {
        return DB::transaction(function () use ($user, $companyId, $branchId, $sessionId) {
            [$session, $settings] = $this->lockedContext($user, $companyId, $branchId, $sessionId, 'caja.autorizar_diferencia');
            if ($session->status === CashSession::STATUS_CLOSED && $session->difference_authorized_by !== null) return $session;
            if ($session->status !== CashSession::STATUS_CLOSING || $session->closing_submitted_at === null) throw ValidationException::withMessages(['cash_session_id' => 'No existe un cierre pendiente de autorización.']);
            $now = now(); $session->update(['difference_authorized_by' => $user->id, 'difference_authorized_at' => $now, 'status' => CashSession::STATUS_CLOSED, 'open_guard' => null, 'closed_by' => $user->id, 'closed_at' => $now]);
            CashSessionEvent::create(['cash_session_id' => $session->id, 'event_type' => CashSessionEvent::TYPE_DIFFERENCE_AUTHORIZED, 'user_id' => $user->id, 'occurred_at' => $now]);
            CashSessionEvent::create(['cash_session_id' => $session->id, 'event_type' => CashSessionEvent::TYPE_CLOSED, 'user_id' => $user->id, 'occurred_at' => $now, 'payload' => $this->closingPayload($session->fresh())]);
            $this->mailNotifications->create($session->fresh(), CashSessionMailNotification::TYPE_CLOSED, $settings);
            return $session->fresh();
        });
    }

    private function lockedContext(User $user, int $companyId, int $branchId, int $sessionId, string $permission): array
    {
        $company = Company::query()->where('is_active', true)->find($companyId); $branch = Branch::query()->where('company_id', $companyId)->where('is_active', true)->find($branchId);
        if (! $company || ! $branch || ! $user->companies()->whereKey($companyId)->exists() || ! $user->branches()->whereKey($branchId)->exists() || ! $user->hasPermission($permission, $company)) throw ValidationException::withMessages(['cash_session_id' => 'La empresa o sucursal activa no está autorizada.']);
        $session = CashSession::query()->forCompany($companyId)->forBranch($branchId)->whereKey($sessionId)->lockForUpdate()->first();
        if (! $session || ! $session->cashRegister()->where('company_id', $companyId)->where('branch_id', $branchId)->where('is_active', true)->exists()) throw ValidationException::withMessages(['cash_session_id' => 'La sesión o caja no está disponible.']);
        return [$session, CompanyCashSetting::query()->where('company_id', $companyId)->firstOrFail()];
    }

    private function assertCanOperate(CashSession $session, CompanyCashSetting $settings, User $user, bool $continuing): void
    {
        if ($settings->session_mode === CompanyCashSetting::SESSION_MODE_INDIVIDUAL && $session->opened_by !== $user->id) throw ValidationException::withMessages(['cash_session_id' => 'Solo el cajero que abrió la sesión puede cerrarla.']);
        if ($continuing && $settings->session_mode === CompanyCashSetting::SESSION_MODE_SHARED && $session->closing_started_by !== $user->id && ! $user->hasPermission('caja.administrar', $session->company)) throw ValidationException::withMessages(['cash_session_id' => 'Solo quien inició el cierre o un administrador puede continuarlo.']);
    }

    private function closingPayload(CashSession $session): array
    {
        return ['expected_cash_crc' => $session->expected_cash, 'counted_cash_crc' => $session->counted_cash, 'difference_crc' => $session->difference_amount];
    }
}
