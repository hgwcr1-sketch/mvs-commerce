<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalProfile extends Model
{
    protected $fillable = ['fiscal_catalog_version_id', 'tax_code', 'tax_rate_code', 'name', 'treatment', 'rate', 'factor_iva', 'tax_rate_other', 'document_types', 'valid_from', 'valid_until', 'is_active'];

    protected $casts = ['rate' => 'decimal:4', 'factor_iva' => 'decimal:6', 'document_types' => 'array', 'valid_from' => 'date', 'valid_until' => 'date', 'is_active' => 'boolean'];

    public function catalogVersion(): BelongsTo
    {
        return $this->belongsTo(FiscalCatalogVersion::class, 'fiscal_catalog_version_id');
    }

    public function productTaxes(): HasMany
    {
        return $this->hasMany(ProductTax::class);
    }
}