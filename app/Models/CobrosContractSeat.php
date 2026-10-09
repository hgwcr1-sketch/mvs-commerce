<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BLOQUE 17A-1 — cupo (seat) de un contrato empresarial. El responsable de
 * pago es el del contrato; el cupo solo registra posición y, si se asigna,
 * al cobrador que lo ocupa. No crea licencias por sí mismo.
 */
class CobrosContractSeat extends Model
{
    public const STATUS_AVAILABLE = 'available';

    public const STATUS_ASSIGNED = 'assigned';

    protected $fillable = [
        'billing_contract_id',
        'position',
        'status',
        'user_id',
        'assigned_at',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'assigned_at' => 'datetime',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(CobrosBillingContract::class, 'billing_contract_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
