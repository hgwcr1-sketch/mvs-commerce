<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoyaltyAccount extends Model
{
    protected $fillable = [
        'company_id',
        'customer_id',
        'balance',
        'total_earned',
        'total_redeemed',
        'total_expired',
        'last_qualifying_purchase_at',
        'last_activity_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:4',
            'total_earned' => 'decimal:4',
            'total_redeemed' => 'decimal:4',
            'total_expired' => 'decimal:4',
            'last_qualifying_purchase_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(LoyaltyMovement::class);
    }
}
