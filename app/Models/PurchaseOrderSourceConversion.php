<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderSourceConversion extends Model
{
    protected $fillable = ['purchase_order_item_source_id', 'purchase_item_id', 'converted_quantity'];
    protected $casts = ['converted_quantity' => 'decimal:4'];

    public function source(): BelongsTo { return $this->belongsTo(PurchaseOrderItemSource::class, 'purchase_order_item_source_id'); }
    public function purchaseItem(): BelongsTo { return $this->belongsTo(PurchaseItem::class); }
}
