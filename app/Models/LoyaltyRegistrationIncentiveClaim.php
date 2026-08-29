<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyRegistrationIncentiveClaim extends Model
{
    protected $fillable = ['company_id', 'customer_id', 'loyalty_movement_id', 'benefit_type', 'benefit_value', 'awarded_points', 'discount_amount', 'branch_id', 'sale_id', 'configured_by'];

    protected $casts = ['benefit_value' => 'decimal:4', 'awarded_points' => 'decimal:4', 'discount_amount' => 'decimal:4'];

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

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function configurator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'configured_by');
    }
}
