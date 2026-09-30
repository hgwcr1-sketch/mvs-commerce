<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fase 3C CobrOS — reverso auditable de un cobro anulado.
 *
 * El pago original nunca se borra ni se modifica: el reverso registra la
 * anulación con actor, motivo, saldo antes/después, número por empresa y
 * token de idempotencia. UNIQUE(payment_id) impide anular dos veces.
 */
class CobrosPaymentReversal extends Model
{
    protected $table = 'cobros_payment_reversals';

    protected $fillable = [
        'company_id',
        'branch_id',
        'receipt_id',
        'payment_id',
        'account_receivable_id',
        'collector_user_id',
        'annulled_by_user_id',
        'reversal_number',
        'amount',
        'currency_code',
        'balance_before',
        'balance_after',
        'reason',
        'client_token',
        'payload_hash',
        'annulled_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'balance_before' => 'decimal:4',
            'balance_after' => 'decimal:4',
            'annulled_at' => 'datetime',
        ];
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where($this->getTable().'.company_id', $companyId);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(CobrosReceipt::class, 'receipt_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(AccountReceivablePayment::class, 'payment_id');
    }

    public function accountReceivable(): BelongsTo
    {
        return $this->belongsTo(AccountReceivable::class, 'account_receivable_id');
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collector_user_id');
    }

    public function annulledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'annulled_by_user_id');
    }
}
