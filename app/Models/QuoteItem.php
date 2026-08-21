<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuoteItem extends Model
{
    protected $fillable = [
        'quote_id',
        'product_id',
        'product_code',
        'barcode',
        'cabys_code',
        'description',
        'unit_code',
        'quantity',
        'unit_price',
        'gross_total',
        'discount_total',
        'subtotal',
        'tax_rate',
        'tax_total',
        'total',
        'unit_cost',
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
            'tax_total' => 'decimal:4',
            'total' => 'decimal:4',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}