<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyMovementLine extends Model
{
    protected $fillable = [
        'loyalty_movement_id',
        'sale_item_id',
        'product_id',
        'product_category_id',
        'eligible_amount',
        'earning_percentage',
        'multiplier',
        'points',
    ];

    protected function casts(): array
    {
        return [
            'eligible_amount' => 'decimal:4',
            'earning_percentage' => 'decimal:4',
            'multiplier' => 'decimal:4',
            'points' => 'decimal:4',
        ];
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(LoyaltyMovement::class, 'loyalty_movement_id');
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productCategory(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class);
    }
}
