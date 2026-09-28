<?php

namespace App\Services\Purchases;

use App\Models\AccountPayable;
use App\Models\AccountPayablePayment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Cash\CashSessionResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountsPayableService
{
    public function __construct(private readonly CashSessionResolver $cashSessionResolver) {}

    public function pay(AccountPayable $account, array $data, User $user, int $companyId, int $branchId): AccountPayablePayment
    {
        return DB::transaction(function () use ($account, $data, $user, $companyId, $branchId) {
            $account = AccountPayable::query()->forCompany($companyId)->forBranch($branchId)->whereKey($account->id)->lockForUpdate()->firstOrFail();

            if (in_array($account->status, [AccountPayable::STATUS_PAID, AccountPayable::STATUS_CANCELLED], true) || (float) $account->balance_due <= 0) {
                throw ValidationException::withMessages(['account' => 'La cuenta por pagar ya no admite abonos.']);
            }

            $amount = round((float) ($data['amount'] ?? 0), 4);
            if ($amount <= 0 || $amount > (float) $account->balance_due) {
                throw ValidationException::withMessages(['amount' => 'El abono debe ser positivo y no superar el saldo pendiente.']);
            }

            $method = PaymentMethod::query()->forCompany($companyId)->active()->whereKey($data['payment_method_id'] ?? null)->lockForUpdate()->first();
            if (! $method || in_array($method->type, [PaymentMethod::TYPE_CREDIT, PaymentMethod::TYPE_LOYALTY_POINTS], true)) {
                throw ValidationException::withMessages(['payment_method_id' => 'La forma de pago no está disponible para abonos de CxP.']);
            }
            if ($method->requires_reference && empty($data['reference'])) {
                throw ValidationException::withMessages(['reference' => "La referencia es obligatoria para {$method->name}."]);
            }

            $sessions = $this->cashSessionResolver->applicable($user, $companyId, $branchId, true);
            $requestedSessionId = isset($data['cash_session_id']) ? (int) $data['cash_session_id'] : null;
            $session = $requestedSessionId ? $sessions->firstWhere('id', $requestedSessionId) : ($sessions->count() === 1 ? $sessions->first() : null);
            if (! $session) {
                throw ValidationException::withMessages(['cash_session_id' => 'Seleccione una sesión de caja abierta y disponible.']);
            }
            $payment = AccountPayablePayment::create([
                'account_payable_id' => $account->id,
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'supplier_id' => $account->supplier_id,
                'user_id' => $user->id,
                'payment_method_id' => $method->id,
                'cash_session_id' => $session->id,
                'amount' => $amount,
                'affects_cash_snapshot' => (bool) $method->affects_cash,
                'cash_effect_amount' => $method->affects_cash ? $amount : 0,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'paid_at' => now(),
            ]);

            $paid = round((float) $account->paid_amount + $amount, 4);
            $balance = max(0, round((float) $account->original_amount - $paid, 4));
            $account->update([
                'paid_amount' => $paid,
                'balance_due' => $balance,
                'status' => $balance <= 0 ? AccountPayable::STATUS_PAID : AccountPayable::STATUS_PARTIAL,
            ]);

            return $payment;
        }, 3);
    }
}
