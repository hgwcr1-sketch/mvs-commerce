<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashPaymentReconciliation extends Model
{
    protected $fillable = ['cash_session_id', 'payment_method_id', 'payment_method_code_snapshot', 'payment_method_name_snapshot', 'payment_method_type_snapshot', 'expected_amount', 'reported_amount', 'difference_amount', 'reference', 'notes', 'reconciled_by', 'reconciled_at'];
    protected function casts(): array { return ['expected_amount' => 'decimal:4', 'reported_amount' => 'decimal:4', 'difference_amount' => 'decimal:4', 'reconciled_at' => 'datetime']; }
    public function cashSession(): BelongsTo { return $this->belongsTo(CashSession::class); }
    public function paymentMethod(): BelongsTo { return $this->belongsTo(PaymentMethod::class); }
    public function reconciledBy(): BelongsTo { return $this->belongsTo(User::class, 'reconciled_by'); }
    public function scopeForSession($query, int $sessionId) { return $query->where('cash_session_id', $sessionId); }
}
