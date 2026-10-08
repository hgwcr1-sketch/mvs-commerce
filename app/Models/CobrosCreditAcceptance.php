<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCobrosTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BLOQUE 9A.2 — aceptación firmada del préstamo (evidencia inmutable).
 * terms_snapshot son las condiciones EXACTAS aceptadas; terms_hash permite
 * comprobar posteriormente que no fueron alteradas. Sin updated_at.
 */
class CobrosCreditAcceptance extends Model
{
    use BelongsToCobrosTenant;

    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id',
        'credit_id',
        'customer_id',
        'delivered_by',
        'collector_user_id',
        'cobros_route_id',
        'accepted_at',
        'signature_path',
        'reference',
        'terms_snapshot',
        'terms_hash',
    ];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
            'terms_snapshot' => 'array',
        ];
    }

    public function credit(): BelongsTo
    {
        return $this->belongsTo(CobrosCredit::class, 'credit_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function deliveredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivered_by');
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collector_user_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(CobrosRoute::class, 'cobros_route_id');
    }
}
