<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CobrosLaborInvitation extends Model
{
    protected $fillable = [
        'company_id', 'lender_user_id', 'recipient_email', 'token_hash',
        'expires_at', 'revoked_at', 'used_at',
    ];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'expires_at' => 'datetime', 'revoked_at' => 'datetime', 'used_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function lender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lender_user_id');
    }
}
