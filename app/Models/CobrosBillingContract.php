<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * BLOQUE 17A-1 — contrato de facturación CobrOS (individual/empresarial).
 * UN único responsable de pago (payer_user_id) por contrato y período.
 * En estado draft no factura ni activa licencias: eso es 17A-2.
 */
class CobrosBillingContract extends Model
{
    public const MODE_INDIVIDUAL = 'individual';

    public const MODE_ENTERPRISE = 'enterprise';

    public const MODE_MIXED = 'mixed';

    public const STATUSES = ['draft', 'active', 'cancelled'];

    public const OPEN_STATUSES = ['draft', 'active'];

    protected $fillable = [
        'company_id',
        'billing_mode',
        'modality',
        'country_iso',
        'currency',
        'status',
        'payer_user_id',
        'seats_total',
        'monthly_amount',
        'admin_included',
        'valid_from',
        'valid_until',
        'authorized_by_user_id',
        'authorized_at',
        'authorization_reason',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'seats_total' => 'integer',
            'monthly_amount' => 'decimal:4',
            'admin_included' => 'boolean',
            'valid_from' => 'date',
            'valid_until' => 'date',
            'authorized_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payer_user_id');
    }

    public function seats(): HasMany
    {
        return $this->hasMany(CobrosContractSeat::class, 'billing_contract_id');
    }

    public function priceOverrides(): HasMany
    {
        return $this->hasMany(CobrosPriceOverride::class, 'billing_contract_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }
}
