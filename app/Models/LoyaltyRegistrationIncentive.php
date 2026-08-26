<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyRegistrationIncentive extends Model
{
    public const TYPE_POINTS = 'points';

    public const TYPE_PERCENTAGE = 'percentage';

    public const TYPE_FIXED = 'fixed';

    public const TYPES = [self::TYPE_POINTS, self::TYPE_PERCENTAGE, self::TYPE_FIXED];

    public const TIMING_REGISTRATION = 'registration';

    public const TIMING_AFTER_FIRST_VALID_PURCHASE = 'after_first_valid_purchase';

    public const TIMINGS = [self::TIMING_REGISTRATION, self::TIMING_AFTER_FIRST_VALID_PURCHASE];

    protected $fillable = [
        'company_id', 'is_enabled', 'benefit_type', 'benefit_value',
        'minimum_purchase_enabled', 'minimum_purchase_amount', 'award_timing',
        'allow_on_first_purchase', 'bypass_redemption_minimum',
        'expiration_enabled', 'expiration_days',
        'participating_branch_ids', 'allow_offer_products',
        'maximum_discount_enabled', 'maximum_discount_amount', 'stacking_allowed',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'benefit_value' => 'decimal:4',
        'minimum_purchase_enabled' => 'boolean',
        'minimum_purchase_amount' => 'decimal:4',
        'allow_on_first_purchase' => 'boolean',
        'bypass_redemption_minimum' => 'boolean',
        'expiration_enabled' => 'boolean',
        'expiration_days' => 'integer',
        'participating_branch_ids' => 'array',
        'allow_offer_products' => 'boolean',
        'maximum_discount_enabled' => 'boolean',
        'maximum_discount_amount' => 'decimal:4',
        'stacking_allowed' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
