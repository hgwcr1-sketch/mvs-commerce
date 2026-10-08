<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCobrosTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Bitácora append-only de la solicitud de crédito (sin updated_at). */
class CobrosCreditRequestLog extends Model
{
    use BelongsToCobrosTenant;

    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id',
        'credit_request_id',
        'actor_user_id',
        'from_status',
        'to_status',
        'notes',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(CobrosCreditRequest::class, 'credit_request_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
