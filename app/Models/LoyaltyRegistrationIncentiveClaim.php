<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyRegistrationIncentiveClaim extends Model
{
    protected $fillable = [
        'company_id', 'customer_id', 'incentive_rule_id', 'loyalty_movement_id', 'benefit_type', 'benefit_value',
        'award_timing', 'minimum_purchase_amount', 'allow_on_first_purchase',
        'bypass_redemption_minimum', 'awarded_points', 'discount_amount', 'branch_id',
        'participating_branch_ids', 'allow_offer_products', 'maximum_discount_amount', 'stacking_allowed',
        'required_verified_phone', 'required_verified_email',
        'sale_id', 'qualification_sale_id', 'configured_by', 'awarded_at', 'available_at', 'expires_at',
        'expired_at', 'used_at',
    ];

    protected $casts = [
        'benefit_value' => 'decimal:4',
        'minimum_purchase_amount' => 'decimal:4',
        'allow_on_first_purchase' => 'boolean',
        'bypass_redemption_minimum' => 'boolean',
        'participating_branch_ids' => 'array',
        'allow_offer_products' => 'boolean',
        'maximum_discount_amount' => 'decimal:4',
        'stacking_allowed' => 'boolean',
        'required_verified_phone' => 'boolean',
        'required_verified_email' => 'boolean',
        'awarded_points' => 'decimal:4',
        'discount_amount' => 'decimal:4',
        'available_at' => 'datetime',
        'expires_at' => 'datetime',
        'expired_at' => 'datetime',
        'used_at' => 'datetime',
        'awarded_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function loyaltyMovement(): BelongsTo
    {
        return $this->belongsTo(LoyaltyMovement::class);
    }

    public function incentiveRule(): BelongsTo
    {
        return $this->belongsTo(LoyaltyRegistrationIncentive::class, 'incentive_rule_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function qualificationSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'qualification_sale_id');
    }

    public function configurator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'configured_by');
    }
}
