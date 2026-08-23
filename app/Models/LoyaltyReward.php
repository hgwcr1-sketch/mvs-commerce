<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyReward extends Model
{
    public const MODE_UNLIMITED = 'unlimited';

    public const MODE_LIMITED = 'limited';

    public const MODE_PRODUCT = 'product';

    public const MODES = [
        self::MODE_UNLIMITED,
        self::MODE_LIMITED,
        self::MODE_PRODUCT,
    ];

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

    protected $fillable = ['company_id', 'name', 'type', 'availability_mode', 'description', 'product_id', 'stock_quantity', 'points_cost', 'is_active'];

    protected function casts(): array
    {
        return ['points_cost' => 'decimal:4', 'stock_quantity' => 'decimal:4', 'is_active' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
