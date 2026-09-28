<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrderItem extends Model
{
    protected $fillable = ['purchase_order_id', 'product_id', 'description', 'supplier_product_code', 'unit_code', 'requested_quantity', 'ordered_quantity', 'unit_cost_snapshot', 'notes'];
    protected $casts = ['requested_quantity' => 'decimal:4', 'ordered_quantity' => 'decimal:4', 'unit_cost_snapshot' => 'decimal:4'];

    public function purchaseOrder(): BelongsTo { return $this->belongsTo(PurchaseOrder::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function sources(): HasMany { return $this->hasMany(PurchaseOrderItemSource::class); }

    public function getConvertedQuantityAttribute(): float
    {
        return (float) $this->sources->sum(fn ($source) => (float) ($source->converted_quantity ?? $source->conversions->sum('converted_quantity')));
    }

    public function getPendingQuantityAttribute(): float
    {
        return max(0, round((float) $this->ordered_quantity - $this->converted_quantity, 4));
    }
}
