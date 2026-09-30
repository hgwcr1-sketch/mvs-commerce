<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LayawayItemTax extends Model
{
    protected $fillable = ['layaway_item_id', 'tax_code', 'tax_rate_code', 'description', 'treatment', 'rate', 'factor_iva', 'base_amount', 'tax_amount', 'specific_tax_data', 'exemption_snapshot', 'source', 'source_version', 'sequence'];

    protected $casts = ['rate' => 'decimal:4', 'factor_iva' => 'decimal:6', 'base_amount' => 'decimal:4', 'tax_amount' => 'decimal:4', 'specific_tax_data' => 'array', 'exemption_snapshot' => 'array'];

    public function layawayItem(): BelongsTo
    {
        return $this->belongsTo(LayawayItem::class);
    }
}
