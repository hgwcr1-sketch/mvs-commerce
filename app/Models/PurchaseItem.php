<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseItem extends Model
{
    protected $fillable = [
        'purchase_id',
        'product_id',
        'lot_number',
        'expires_at',
        'quantity',
        'unit_cost',
        'previous_sale_price',
        'new_sale_price',
        'subtotal',
        'discount',
        'tax_rate',
        'tax',
        'total',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'date',
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'previous_sale_price' => 'decimal:2',
            'new_sale_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax_rate' => 'decimal:4',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function inventoryLots(): HasMany
    {
        return $this->hasMany(InventoryLot::class);
    }

    public function purchaseOrderSourceConversions(): HasMany
    {
        return $this->hasMany(PurchaseOrderSourceConversion::class);
    }
}
