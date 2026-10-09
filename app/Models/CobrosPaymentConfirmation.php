<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BLOQUE 17A-2 f1 — confirmación de pago de un contrato CobrOS.
 *
 * Independiente del proveedor (internal/stripe/sinpe). La activación es
 * idempotente por transaction_id. Nunca se borra: vencido/suspendido solo
 * cambia de estado.
 */
class CobrosPaymentConfirmation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_CONFIRMED,
        self::STATUS_EXPIRED,
        self::STATUS_SUSPENDED,
    ];

    protected $fillable = [
        'transaction_id',
        'cobros_billing_contract_id',
        'company_id',
        'period_year',
        'period_month',
        'amount',
        'currency',
        'status',
        'provider',
        'confirmed_by_user_id',
        'confirmed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'period_year' => 'integer',
            'period_month' => 'integer',
            'amount' => 'decimal:4',
            'confirmed_at' => 'datetime',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(CobrosBillingContract::class, 'cobros_billing_contract_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }
}
