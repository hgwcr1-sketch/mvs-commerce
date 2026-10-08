<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** BLOQUE 9 — cuota del calendario; genera SOLO al desembolsar. Sin mora capitalizada. */
class CobrosCreditInstallment extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_PAID = 'paid';
    public const STATUS_OVERDUE = 'overdue';

    protected $fillable = [
        'credit_id',
        'number',
        'due_date',
        'contractual_amount',
        'applied_amount',
        'status',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'contractual_amount' => 'decimal:4',
            'applied_amount' => 'decimal:4',
            'due_date' => 'date',
            'paid_at' => 'datetime',
        ];
    }

    public function credit(): BelongsTo
    {
        return $this->belongsTo(CobrosCredit::class, 'credit_id');
    }
}
