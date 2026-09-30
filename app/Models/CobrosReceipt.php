<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fase 3B CobrOS — recibo de un cobro confirmado.
 *
 * Un registro por pago: número único por empresa, token de idempotencia
 * único por empresa y vínculo único con el pago de CxC correspondiente.
 * El recibo es solo lectura: no modifica saldos ni caja.
 */
class CobrosReceipt extends Model
{
    protected $table = 'cobros_receipts';

    protected $fillable = [
        'company_id',
        'branch_id',
        'customer_id',
        'account_receivable_id',
        'payment_id',
        'collector_user_id',
        'payment_method_id',
        'cobros_visit_id',
        'receipt_number',
        'amount',
        'currency_code',
        'reference',
        'client_token',
        'payload_hash',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'paid_at' => 'datetime',
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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function accountReceivable(): BelongsTo
    {
        return $this->belongsTo(AccountReceivable::class, 'account_receivable_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(AccountReceivablePayment::class, 'payment_id');
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collector_user_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(CobrosVisit::class, 'cobros_visit_id');
    }
}
