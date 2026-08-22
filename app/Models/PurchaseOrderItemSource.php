<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrderItemSource extends Model
{
    protected $fillable = ['purchase_order_item_id', 'order_item_id', 'allocated_quantity'];
    protected $casts = ['allocated_quantity' => 'decimal:4'];

    public function purchaseOrderItem(): BelongsTo { return $this->belongsTo(PurchaseOrderItem::class); }
    public function orderItem(): BelongsTo { return $this->belongsTo(OrderItem::class); }
    public function conversions(): HasMany { return $this->hasMany(PurchaseOrderSourceConversion::class); }
}
