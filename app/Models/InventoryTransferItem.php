<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryTransferItem extends Model
{
    protected $fillable = [
        'inventory_transfer_id',
        'product_id',
        'quantity',
        'from_previous_stock',
        'from_new_stock',
        'to_previous_stock',
        'to_new_stock',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'from_previous_stock' => 'decimal:4',
        'from_new_stock' => 'decimal:4',
        'to_previous_stock' => 'decimal:4',
        'to_new_stock' => 'decimal:4',
    ];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(
            InventoryTransfer::class,
            'inventory_transfer_id'
        );
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
