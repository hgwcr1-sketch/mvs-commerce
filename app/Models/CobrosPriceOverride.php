<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BLOQUE 17A-1 — precio personalizado POR CONTRATO (nunca global). Cada
 * fila lleva autorizador, fecha y motivo: los cambios son auditables y un
 * precio personalizado jamás altera otros contratos ni la tarifa estándar.
 */
class CobrosPriceOverride extends Model
{
    public const CONCEPTS = ['administration', 'route', 'collector', 'monthly_total'];

    protected $fillable = [
        'billing_contract_id',
        'company_id',
        'concept',
        'currency',
        'amount',
        'valid_from',
        'valid_until',
        'is_active',
        'authorized_by_user_id',
        'authorized_at',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'is_active' => 'boolean',
            'valid_from' => 'date',
            'valid_until' => 'date',
            'authorized_at' => 'datetime',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(CobrosBillingContract::class, 'billing_contract_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by_user_id');
    }
}
