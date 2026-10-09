<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BLOQUE 17A-1 — tarifa estándar por país y concepto (Panel Maestro).
 * La fila vigente es la activa más reciente; un precio personalizado vive
 * en cobros_price_overrides y jamás altera estas filas ni otros contratos.
 */
class CobrosPricePlan extends Model
{
    protected $fillable = [
        'country_iso',
        'concept',
        'currency',
        'amount',
        'is_active',
        'starts_at',
        'ends_at',
        'reason',
        'authorized_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by_user_id');
    }
}
