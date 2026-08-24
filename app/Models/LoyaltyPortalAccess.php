<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyPortalAccess extends Model
{
    protected $fillable = ['company_id', 'customer_id', 'user_id', 'token_hash', 'revoked_at', 'last_used_at'];

    protected function casts(): array
    {
        return ['revoked_at' => 'datetime', 'last_used_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive($query)
    {
        return $query->whereNull('revoked_at');
    }
}
