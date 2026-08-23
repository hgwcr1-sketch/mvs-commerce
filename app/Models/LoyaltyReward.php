<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyReward extends Model
{
    public const TYPE_PRODUCT = 'product';

    public const TYPE_DISCOUNT = 'discount';

    public const TYPE_SERVICE = 'service';

    public const TYPE_GIFT = 'gift';

    public const TYPES = [
        self::TYPE_PRODUCT,
        self::TYPE_DISCOUNT,
        self::TYPE_SERVICE,
        self::TYPE_GIFT,
    ];

    protected $fillable = ['company_id', 'name', 'type', 'description', 'points_cost', 'is_active'];

    protected function casts(): array
    {
        return ['points_cost' => 'decimal:4', 'is_active' => 'boolean'];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
