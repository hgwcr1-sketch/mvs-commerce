<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountReceivablePayment extends Model
{
    protected $table = 'accounts_receivable_payments';
    protected $fillable = ['account_receivable_id', 'company_id', 'branch_id', 'customer_id', 'user_id', 'cash_session_id', 'payment_method_id', 'amount', 'affects_cash_snapshot', 'cash_effect_amount', 'reference', 'notes', 'paid_at'];
    protected function casts(): array { return ['amount' => 'decimal:4', 'affects_cash_snapshot' => 'boolean', 'cash_effect_amount' => 'decimal:4', 'paid_at' => 'datetime']; }
    public function accountReceivable(): BelongsTo { return $this->belongsTo(AccountReceivable::class); }
    public function paymentMethod(): BelongsTo { return $this->belongsTo(PaymentMethod::class); }
    public function cashSession(): BelongsTo { return $this->belongsTo(CashSession::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
