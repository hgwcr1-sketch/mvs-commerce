<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductBarcode extends Model
{
    protected $fillable = [
        'product_id',
        'barcode',
        'barcode_type',
        'is_primary',
        'is_active',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Producto al que pertenece este código de barras.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}