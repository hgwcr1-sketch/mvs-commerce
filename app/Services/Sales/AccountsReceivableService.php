<?php

namespace App\Services\Sales;

use App\Models\AccountReceivable;
use App\Models\AccountReceivablePayment;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\User;
use App\Services\Cash\CashSessionResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountsReceivableService
{
    private const SCALE = 4;

    public function __construct(private readonly CashSessionResolver $cashSessionResolver) {}

    public function createForSale(Sale $sale, Customer $customer, ?string $creditAmount = null): AccountReceivable
    {
        $customer = Customer::query()->whereKey($customer->id)->where('company_id', $sale->company_id)->where('is_active', true)->lockForUpdate()->first();
        if (!$customer) throw ValidationException::withMessages(['customer_id' => 'Para vender a crédito debe seleccionar un cliente válido.']);
        if (bccomp((string) $customer->credit_limit, '0', self::SCALE) <= 0) throw ValidationException::withMessages(['credit' => 'Este cliente no tiene crédito autorizado.']);
        if ((int) $customer->credit_days <= 0) throw ValidationException::withMessages(['credit' => 'El cliente no tiene un plazo de crédito configurado.']);

        $amount = $creditAmount ?? (string) $sale->total;

        $used = (string) AccountReceivable::query()->forCompany($sale->company_id)->where('customer_id', $customer->id)
            ->whereNotIn('status', [AccountReceivable::STATUS_PAID, AccountReceivable::STATUS_CANCELLED])->lockForUpdate()->sum('balance_due');
        $available = bcsub((string) $customer->credit_limit, $used, self::SCALE);
        if (bccomp($amount, $available, self::SCALE) > 0) {
            throw ValidationException::withMessages(['credit' => 'El cliente no tiene crédito disponible suficiente.']);
        }

        $issued = ($sale->completed_at ?? now())->copy()->startOfDay();
        return AccountReceivable::firstOrCreate(['sale_id' => $sale->id], [
            'company_id' => $sale->company_id, 'branch_id' => $sale->branch_id, 'customer_id' => $customer->id,
            'issued_at' => $issued->toDateString(), 'due_date' => $issued->copy()->addDays((int) $customer->credit_days)->toDateString(),
            'original_amount' => $amount, 'balance_due' => $amount, 'status' => AccountReceivable::STATUS_PENDING, 'currency_code' => $sale->currency_code,
        ]);
    }

    public function pay(AccountReceivable $account, array $data, User $user, int $companyId, int $branchId): AccountReceivablePayment
    {
        return DB::transaction(function () use ($account, $data, $user, $companyId, $branchId) {
            $amount = bcadd(trim((string) $data['amount']), '0', self::SCALE);

            $method = PaymentMethod::query()->forCompany($companyId)->active()->whereKey($data['payment_method_id'])->lockForUpdate()->first();
            if (!$method || in_array($method->type, [PaymentMethod::TYPE_CREDIT, PaymentMethod::TYPE_LOYALTY_POINTS], true)) throw ValidationException::withMessages(['payment_method_id' => 'La forma de pago no está disponible para abonos.']);
            if ($method->requires_reference && empty($data['reference'])) throw ValidationException::withMessages(['reference' => "La referencia es obligatoria para {$method->name}."]);

            $session = $this->cashSessionResolver->resolve($user, $companyId, $branchId, $data['cash_session_id'] ?? null, true);

            $account = AccountReceivable::query()->forCompany($companyId)->forBranch($branchId)->whereKey($account->id)->lockForUpdate()->firstOrFail();
            if (in_array($account->status, [AccountReceivable::STATUS_PAID, AccountReceivable::STATUS_CANCELLED], true) || bccomp((string) $account->balance_due, '0', self::SCALE) <= 0) {
                throw ValidationException::withMessages(['account' => 'La cuenta ya no admite abonos.']);
            }
            if (bccomp($amount, '0', self::SCALE) <= 0 || bccomp($amount, (string) $account->balance_due, self::SCALE) > 0) throw ValidationException::withMessages(['amount' => 'El abono debe ser positivo y no superar el saldo pendiente.']);

            $payment = AccountReceivablePayment::create([
                'account_receivable_id' => $account->id, 'company_id' => $companyId, 'branch_id' => $branchId, 'customer_id' => $account->customer_id,
                'user_id' => $user->id, 'cash_session_id' => $session->id, 'payment_method_id' => $method->id, 'amount' => $amount,
                'affects_cash_snapshot' => $method->affects_cash, 'cash_effect_amount' => $method->affects_cash ? $amount : '0',
                'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null, 'paid_at' => now(),
            ]);
            $balance = bcsub((string) $account->balance_due, $amount, self::SCALE);
            $balance = bccomp($balance, '0', self::SCALE) < 0 ? '0' : $balance;
            $status = bccomp($balance, '0', self::SCALE) <= 0 ? AccountReceivable::STATUS_PAID : ($account->due_date->isBefore(today()) ? AccountReceivable::STATUS_OVERDUE : AccountReceivable::STATUS_PARTIAL);
            $account->update(['balance_due' => $balance, 'status' => $status]);
            return $payment;
        }, 3);
    }
}
