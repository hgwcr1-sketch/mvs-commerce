<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = ['order_id', 'product_id', 'description', 'internal_code', 'barcode', 'unit_code', 'allows_decimals_snapshot', 'requested_quantity', 'stock_snapshot', 'sale_price_snapshot', 'cost_snapshot', 'last_cost_snapshot', 'approved_quantity', 'supplier_id', 'item_status', 'request_note', 'review_note'];

    protected function casts(): array
    {
        return ['allows_decimals_snapshot' => 'boolean', 'requested_quantity' => 'decimal:4', 'stock_snapshot' => 'decimal:4', 'sale_price_snapshot' => 'decimal:4', 'cost_snapshot' => 'decimal:4', 'last_cost_snapshot' => 'decimal:4', 'approved_quantity' => 'decimal:4'];
    }

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function purchaseOrderSources(): HasMany { return $this->hasMany(PurchaseOrderItemSource::class); }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->item_status) {
            self::STATUS_PENDING => 'Pendiente',
            self::STATUS_APPROVED => 'Aprobada',
            self::STATUS_PARTIAL => 'Parcial',
            self::STATUS_REJECTED => 'Rechazada',
            default => ucfirst($this->item_status),
        };
    }
}
