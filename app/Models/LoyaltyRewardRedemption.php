<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyRewardRedemption extends Model
{
    protected $fillable = [
        'company_id',
        'branch_id',
        'customer_id',
        'user_id',
        'reward_id',
        'product_id',
        'loyalty_movement_id',
        'event_key',
        'reward_name',
        'reward_type',
        'availability_mode',
        'product_name',
        'points_cost',
    ];

    protected function casts(): array
    {
        return ['points_cost' => 'decimal:4'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reward(): BelongsTo
    {
        return $this->belongsTo(LoyaltyReward::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function loyaltyMovement(): BelongsTo
    {
        return $this->belongsTo(LoyaltyMovement::class);
    }
}
