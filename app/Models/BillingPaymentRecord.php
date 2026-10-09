<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * BLOQUE 17B-1 — registro genérico de pago multi-proveedor.
 *
 * Modelo neutro sin ámbito empresarial CobrOS: cada fila se aísla por
 * product + company_id + contract_reference. La idempotencia se garantiza
 * con el índice único (provider, external_id).
 */
class BillingPaymentRecord extends Model
{
    protected $table = 'billing_payment_records';

    protected $guarded = [];

    protected $casts = [
        'amount' => 'string',
        'period_year' => 'integer',
        'period_month' => 'integer',
        'raw_payload' => 'array',
        'confirmed_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_CONFIRMED,
        self::STATUS_FAILED,
        self::STATUS_REFUNDED,
    ];
}
