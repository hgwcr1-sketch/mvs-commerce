<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PRE-19B-1 — bonificación del programa de referidos/crecimiento registrada
 * SIEMPRE como saldo a favor (status=credit). Nunca representa dinero
 * desembolsado automáticamente.
 */
class CobrosReferralBonusCredit extends Model
{
    public const TYPE_REFERRAL_FIRST_PAYMENT = 'referral_first_payment';

    public const TYPE_GROWTH_COLLABORATOR = 'growth_collaborator';

    /** Único estado V1: saldo a favor del beneficiario. */
    public const STATUS_CREDIT = 'credit';

    protected $table = 'cobros_referral_bonus_credits';

    protected $fillable = [
        'company_id',
        'beneficiary_user_id',
        'referred_user_id',
        'type',
        'source_contract_id',
        'source_confirmation_id',
        'percent',
        'amount',
        'currency',
        'status',
        'event_key',
    ];

    protected function casts(): array
    {
        return [
            'percent' => 'decimal:4',
            'amount' => 'decimal:4',
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

    public function referred(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(CobrosBillingContract::class, 'source_contract_id');
    }

    public function confirmation(): BelongsTo
    {
        return $this->belongsTo(CobrosPaymentConfirmation::class, 'source_confirmation_id');
    }
}
