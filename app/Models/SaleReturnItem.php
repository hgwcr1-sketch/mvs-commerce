<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleReturnItem extends Model
{
    protected $fillable = [
        'sale_return_id',
        'sale_item_id',
        'product_id',
        'quantity',
        'unit_price',
        'gross_total',
        'discount_total',
        'subtotal',
        'tax_rate',
        'tax_code',
        'tax_rate_code',
        'tax_treatment',
        'fiscal_source',
        'fiscal_source_version',
        'fiscal_snapshot',
        'tax_total',
        'total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'gross_total' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'subtotal' => 'decimal:4',
            'tax_rate' => 'decimal:4',
            'fiscal_snapshot' => 'array',
            'tax_total' => 'decimal:4',
            'total' => 'decimal:4',
        ];
    }

    public function taxes(): HasMany
    {
        return $this->hasMany(SaleReturnItemTax::class);
    }

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}