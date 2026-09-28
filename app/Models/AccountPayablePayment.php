<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class AccountPayablePayment extends Model
{
    protected $table = 'accounts_payable_payments';
    protected $fillable = ['account_payable_id', 'company_id', 'branch_id', 'supplier_id', 'user_id', 'payment_method_id', 'cash_session_id', 'amount', 'affects_cash_snapshot', 'cash_effect_amount', 'reference', 'notes', 'paid_at'];
    protected function casts(): array { return ['amount' => 'decimal:4', 'affects_cash_snapshot' => 'boolean', 'cash_effect_amount' => 'decimal:4', 'paid_at' => 'datetime']; }

    protected static function booted(): void
    {
        static::creating(function (AccountPayablePayment $payment): void {
            $account = AccountPayable::query()->find($payment->account_payable_id);
            $amount = (float) $payment->amount;

            $alreadyPaid = $account?->payments()->sum('amount') ?? 0;
            $available = $account ? min((float) $account->balance_due, max(0, (float) $account->original_amount - (float) $alreadyPaid)) : 0;

            if (! $account || $amount <= 0 || $amount > $available) {
                throw ValidationException::withMessages(['amount' => 'El pago debe ser mayor que cero y no puede superar el saldo pendiente.']);
            }

            if ((int) $account->company_id !== (int) $payment->company_id || (int) $account->branch_id !== (int) $payment->branch_id || (int) $account->supplier_id !== (int) $payment->supplier_id) {
                throw ValidationException::withMessages(['account_payable_id' => 'La cuenta por pagar no pertenece al contexto indicado.']);
            }

            if (! PaymentMethod::query()->forCompany((int) $account->company_id)->whereKey($payment->payment_method_id)->exists()) {
                throw ValidationException::withMessages(['payment_method_id' => 'El método de pago no pertenece a la empresa.']);
            }

            if ($payment->cash_session_id !== null && ! CashSession::query()->forCompany((int) $account->company_id)->forBranch((int) $account->branch_id)->whereKey($payment->cash_session_id)->exists()) {
                throw ValidationException::withMessages(['cash_session_id' => 'La sesión de caja no pertenece al contexto indicado.']);
            }
        });
    }

    public function accountPayable(): BelongsTo { return $this->belongsTo(AccountPayable::class); }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function paymentMethod(): BelongsTo { return $this->belongsTo(PaymentMethod::class); }
    public function cashSession(): BelongsTo { return $this->belongsTo(CashSession::class); }
}
