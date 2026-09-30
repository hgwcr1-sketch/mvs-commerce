<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductTax extends Model
{
    protected $fillable = ['product_id', 'fiscal_profile_id', 'role', 'additional_tax_data', 'source', 'source_version', 'valid_from', 'valid_until', 'is_active'];

    protected $casts = ['additional_tax_data' => 'array', 'valid_from' => 'date', 'valid_until' => 'date', 'is_active' => 'boolean'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function fiscalProfile(): BelongsTo
    {
        return $this->belongsTo(FiscalProfile::class);
    }
}