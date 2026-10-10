<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PRE-19B-4A — reserva idempotente de un bono de referidos hacia una
 * mensualidad/período. NO descuenta el saldo definitivamente: el bono
 * conserva status=credit hasta la liquidación legítima (PRE-19B-4B).
 */
class CobrosReferralCreditReservation extends Model
{
    public const STATUS_RESERVED = 'reserved';

    public const STATUS_SETTLED = 'settled';

    protected $table = 'cobros_referral_credit_reservations';

    protected $fillable = [
        'company_id',
        'beneficiary_user_id',
        'cobros_billing_contract_id',
        'bonus_credit_id',
        'period_year',
        'period_month',
        'amount',
        'currency',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'period_year' => 'integer',
            'period_month' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(User::class, 'beneficiary_user_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(CobrosBillingContract::class, 'cobros_billing_contract_id');
    }

    public function bonusCredit(): BelongsTo
    {
        return $this->belongsTo(CobrosReferralBonusCredit::class, 'bonus_credit_id');
    }
}
