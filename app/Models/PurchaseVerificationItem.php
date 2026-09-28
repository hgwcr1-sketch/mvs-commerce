<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseVerificationItem extends Model
{
    protected $fillable = ['purchase_verification_id', 'purchase_item_id', 'product_id', 'expected_quantity', 'received_quantity', 'difference', 'is_checked', 'observation', 'verified_by', 'verified_at'];
    protected $casts = ['expected_quantity' => 'decimal:4', 'received_quantity' => 'decimal:4', 'difference' => 'decimal:4', 'is_checked' => 'boolean', 'verified_at' => 'datetime'];
    public function verification(): BelongsTo { return $this->belongsTo(PurchaseVerification::class, 'purchase_verification_id'); }
    public function purchaseItem(): BelongsTo { return $this->belongsTo(PurchaseItem::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
