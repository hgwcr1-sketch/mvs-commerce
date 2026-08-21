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

    protected $fillable = [
        'company_id',
        'branch_id',
        'user_id',
        'customer_id',
        'quote_number',
        'status',
        'converted',
        'converted_sale_id',
        'converted_at',
        'cancelled',
        'cancellation_enabled',
        'cancellation_reason',
        'cancelled_by',
        'cancelled_at',
        'expires_at',
        'notes',
        'subtotal',
        'discount_total',
        'tax_total',
        'total',
    ];

    protected function casts(): array
    {
        return [
            'converted' => 'boolean',
            'converted_at' => 'datetime',
            'cancelled' => 'boolean',
            'cancellation_enabled' => 'boolean',
            'cancelled_at' => 'datetime',
            'expires_at' => 'datetime',
            'subtotal' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'total' => 'decimal:4',
        ];
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

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function convertedSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'converted_sale_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class);
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && ! $this->converted
            && ! $this->cancelled
            && $this->expires_at !== null
            && $this->expires_at->isPast();
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE && ! $this->converted && ! $this->cancelled;
    }

    public function canBeConverted(): bool
    {
        if (! $this->isActive() || $this->isExpired()) {
            return false;
        }

        return true;
    }

    public function canBeCancelled(): bool
    {
        return $this->isActive() && $this->cancellation_enabled;
    }
}