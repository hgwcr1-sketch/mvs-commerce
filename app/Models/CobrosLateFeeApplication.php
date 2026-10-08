<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCobrosTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 9B.3 - mora cobrada en un recibo (snapshot inmutable, una fila por cuota/recibo). */
class CobrosLateFeeApplication extends Model
{
    use BelongsToCobrosTenant;

    protected $fillable = [
        'company_id',
        'credit_id',
        'installment_id',
        'account_receivable_id',
        'cobros_receipt_id',
        'collector_user_id',
        'late_fee_charged',
        'effective_pct',
        'collector_amount',
        'lender_amount',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'late_fee_charged' => 'decimal:4',
            'effective_pct' => 'decimal:2',
            'collector_amount' => 'decimal:4',
            'lender_amount' => 'decimal:4',
            'paid_at' => 'datetime',
        ];
    }

    public function credit(): BelongsTo
    {
        return $this->belongsTo(CobrosCredit::class, 'credit_id');
    }

    public function installment(): BelongsTo
    {
        return $this->belongsTo(CobrosCreditInstallment::class, 'installment_id');
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(CobrosReceipt::class, 'cobros_receipt_id');
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collector_user_id');
    }
}
