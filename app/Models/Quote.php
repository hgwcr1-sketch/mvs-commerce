<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quote extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_CONVERTED = 'converted';

    public const STATUS_CANCELLED = 'cancelled';

    public function getEffectiveStatusAttribute(): string
    {
        if ($this->status === self::STATUS_ACTIVE && $this->expires_at?->isBefore(today())) {
            return 'expired';
        }

        return $this->status;
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->effective_status) {
            self::STATUS_ACTIVE => 'Activa',
            'expired' => 'Vencida',
            self::STATUS_CONVERTED => 'Convertida',
            self::STATUS_CANCELLED => 'Anulada',
            default => ucfirst($this->effective_status),
        };
    }

    protected $fillable = ['company_id', 'branch_id', 'user_id', 'customer_id', 'quote_number', 'status', 'currency_code', 'subtotal', 'discount_total', 'tax_total', 'total', 'expires_at', 'notes', 'converted_sale_id', 'converted_at', 'cancelled_by', 'cancelled_at', 'cancellation_reason'];

    protected function casts(): array
    {
        return ['subtotal' => 'decimal:4', 'discount_total' => 'decimal:4', 'tax_total' => 'decimal:4', 'total' => 'decimal:4', 'expires_at' => 'date', 'converted_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function convertedSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'converted_sale_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
