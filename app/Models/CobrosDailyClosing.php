<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cierre diario enviado por un cobrador al dueño.
 *
 * `computed_totals` es un snapshot inmutable (JSON) de los totales reales por
 * medio de pago calculados al momento de enviar; las diferencias se derivan de
 * declarado − snapshot y nunca recalculan sobre pagos posteriores.
 */
class CobrosDailyClosing extends Model
{
    protected $fillable = [
        'company_id',
        'collector_user_id',
        'business_date',
        'declared_cash',
        'declared_sinpe',
        'declared_card',
        'declared_bank_transfer',
        'computed_totals',
        'payments_count',
        'notes',
        'evidence_deposit_path',
        'evidence_dataphone_path',
        'submitted_at',
        'reviewed_at',
        'reviewed_by_user_id',
        'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'declared_cash' => 'decimal:4',
            'declared_sinpe' => 'decimal:4',
            'declared_card' => 'decimal:4',
            'declared_bank_transfer' => 'decimal:4',
            'computed_totals' => 'array',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collector_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
