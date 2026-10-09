<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CobrosRegistrationDraft extends Model
{
    protected $fillable = [
        'email',
        'payload',
        'token_hash',
        'token_expires_at',
        'token_used_at',
        'last_saved_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'token_expires_at' => 'datetime',
            'token_used_at' => 'datetime',
            'last_saved_at' => 'datetime',
        ];
    }
}
