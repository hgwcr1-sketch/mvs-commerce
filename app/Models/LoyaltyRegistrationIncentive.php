<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyRegistrationIncentive extends Model
{
    protected $fillable = ['company_id', 'is_enabled'];

    protected $casts = ['is_enabled' => 'boolean'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
