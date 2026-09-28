<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SuspendedSaleItem extends Model
{
    protected $fillable = ['suspended_sale_id', 'product_id', 'product_code', 'barcode', 'cabys_code', 'description', 'unit_code', 'quantity', 'estimated_unit_price', 'estimated_gross_total', 'estimated_tax_rate', 'estimated_tax_total', 'estimated_total'];
    protected function casts(): array { return ['quantity' => 'decimal:4', 'estimated_unit_price' => 'decimal:4', 'estimated_gross_total' => 'decimal:4', 'estimated_tax_rate' => 'decimal:4', 'estimated_tax_total' => 'decimal:4', 'estimated_total' => 'decimal:4']; }
    public function suspendedSale(): BelongsTo { return $this->belongsTo(SuspendedSale::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class)->withTrashed(); }
}
