<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleItem extends Model
{
    protected $fillable = [
        'sale_id',
        'product_id',
        'product_code',
        'barcode',
        'cabys_code',
        'description',
        'unit_code',
        'quantity',
        'unit_price',
        'is_offer',
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
        'unit_cost',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'is_offer' => 'boolean',
            'gross_total' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'subtotal' => 'decimal:4',
            'tax_rate' => 'decimal:4',
            'fiscal_snapshot' => 'array',
            'tax_total' => 'decimal:4',
            'total' => 'decimal:4',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function taxes(): HasMany
    {
        return $this->hasMany(SaleItemTax::class);
    }
}
