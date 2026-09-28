<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyPortalPasskey extends Model
{
    protected $fillable = [
        'company_id',
        'customer_id',
        'credential_id_ref',
        'credential_id',
        'name',
        'algorithm',
        'public_key_jwk',
        'sign_count',
        'registered_ip',
        'registered_user_agent',
        'last_used_at',
        'last_used_ip',
        'revoked_at',
        'revoked_ip',
    ];

    protected function casts(): array
    {
        return [
            'public_key_jwk' => 'array',
            'sign_count' => 'integer',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
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

    public function credential(): BelongsTo
    {
        return $this->belongsTo(LoyaltyPortalCredential::class, 'credential_id_ref');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
