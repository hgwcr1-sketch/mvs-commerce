<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryCountItem extends Model
{
    protected $fillable = [
        'inventory_count_id',
        'product_id',
        'theoretical_quantity',
        'counted_quantity',
        'recount_quantity',
        'final_quantity',
        'difference',
        'notes',
    ];

    protected $casts = [
        'theoretical_quantity' => 'decimal:4',
        'counted_quantity' => 'decimal:4',
        'recount_quantity' => 'decimal:4',
        'final_quantity' => 'decimal:4',
        'difference' => 'decimal:4',
    ];

    public function inventoryCount(): BelongsTo
    {
        return $this->belongsTo(InventoryCount::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function hasDifference(): bool
    {
        return bccomp((string) $this->difference, '0', 4) !== 0;
    }

    public function isShortage(): bool
    {
        return bccomp((string) $this->difference, '0', 4) < 0;
    }

    public function isSurplus(): bool
    {
        return bccomp((string) $this->difference, '0', 4) > 0;
    }

    public function getEffectiveQuantity(): string
    {
        if ($this->recount_quantity !== null) {
            return $this->recount_quantity;
        }
        return $this->counted_quantity ?? '0';
    }

    public function recalculateDifference(): void
    {
        $effective = $this->getEffectiveQuantity();
        $this->difference = bcsub($effective, (string) $this->theoretical_quantity, 4);
        $this->final_quantity = $effective;
        $this->save();
    }
}