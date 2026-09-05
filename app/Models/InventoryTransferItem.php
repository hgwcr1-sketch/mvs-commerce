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
        'sent_quantity',
        'received_quantity',
        'difference',
        'from_previous_stock',
        'from_new_stock',
        'to_previous_stock',
        'to_new_stock',
        'item_notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'sent_quantity' => 'decimal:4',
        'received_quantity' => 'decimal:4',
        'difference' => 'decimal:4',
        'from_previous_stock' => 'decimal:4',
        'from_new_stock' => 'decimal:4',
        'to_previous_stock' => 'decimal:4',
        'to_new_stock' => 'decimal:4',
        'item_notes' => 'string',
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

    public function hasDifference(): bool
    {
        return $this->received_quantity !== null
            && bccomp((string) $this->received_quantity, (string) $this->sent_quantity, 4) !== 0;
    }

    public function isQuantityExact(): bool
    {
        return $this->received_quantity !== null
            && bccomp((string) $this->received_quantity, (string) $this->sent_quantity, 4) === 0;
    }

    public function isQuantityShort(): bool
    {
        return $this->received_quantity !== null
            && bccomp((string) $this->received_quantity, (string) $this->sent_quantity, 4) < 0;
    }

    public function isQuantitySurplus(): bool
    {
        return $this->received_quantity !== null
            && bccomp((string) $this->received_quantity, (string) $this->sent_quantity, 4) > 0;
    }
}