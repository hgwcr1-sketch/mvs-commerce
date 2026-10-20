<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PRE-19B-1 — atribución permanente de un alta a su referente comercial.
 * Una sola fila por usuario referido (referred_user_id único): la
 * atribución nunca se pisa ni se duplica.
 */
class CobrosReferralAttribution extends Model
{
    protected $table = 'cobros_referral_attributions';

    protected $fillable = [
        'company_id',
        'referred_user_id',
        'referrer_user_id',
        'code',
        'captured_at',
        'maestro_referral_attribution_id',
        'maestro_referred_client_id',
        'maestro_installation_id',
    ];

    protected function casts(): array
    {
        return ['captured_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function referred(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }
}
