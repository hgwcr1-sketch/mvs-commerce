<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BLOQUE 17A-2 f1 — confirmación de pago de un contrato CobrOS.
 *
 * Independiente del proveedor (internal/stripe/sinpe). La activación es
 * idempotente por transaction_id. Nunca se borra: vencido/suspendido/rechazado
 * solo cambia de estado.
 *
 * 17B-5B: en rechazo manual SINPE, confirmed_by_user_id/confirmed_at registran
 * QUIÉN decidió rechazar y CUÁNDO (auditoría sin columnas nuevas); el motivo
 * se acumula en notes. Aprobación mantiene su semántica original.
 */
class CobrosPaymentConfirmation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_CONFIRMED,
        self::STATUS_EXPIRED,
        self::STATUS_SUSPENDED,
        self::STATUS_REJECTED,
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

    /**
     * 17B-5A — Solicitudes SINPE pendientes (preparación de consulta para el
     * Panel Maestro existente; sin pantallas nuevas).
     */
    public function scopeSinpePending(Builder $query, ?int $companyId = null): Builder
    {
        return $query
            ->where('provider', 'sinpe')
            ->where('status', self::STATUS_PENDING)
            ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId));
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
