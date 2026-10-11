<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BLOQUE 21D-4A — registro de una sesión DEMO de visitante.
 *
 * Solo guarda el HASH SHA-256 del token opaco (nunca el token en claro). La
 * sesión apunta a una empresa-propietaria aislada creada para ese visitante.
 */
class CobrosDemoSession extends Model
{
    protected $table = 'cobros_demo_sessions';

    protected $fillable = [
        'token_hash', 'company_id', 'owner_user_id', 'expires_at', 'last_seen_at', 'tour_step', 'tour_answers', 'demo_payment',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'tour_answers' => 'array',
        'demo_payment' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
