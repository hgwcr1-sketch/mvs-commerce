<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCobrosTenant;
use App\Models\Concerns\HasCobrosRoute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/** Reuses the existing CobrOS state, validity, suspension and renewal contract. */
class CobrosRouteLicense extends Model
{
    use \App\Models\Concerns\HasCobrosLicenseLifecycle, BelongsToCobrosTenant, HasCobrosRoute;

    protected $table = 'cobros_route_licenses';

    protected $fillable = [
        'company_id', 'cobros_route_id', 'status', 'starts_at', 'expires_at',
        'suspended_at', 'suspended_by_user_id', 'renewed_at', 'notes',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function suspendedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'suspended_by_user_id');
    }

    protected static function booted(): void
    {
        static::saving(function (self $license) {
            if (($license->exists && $license->isDirty('cobros_route_id'))
                || ! in_array($license->status, [self::STATUS_ACTIVE, self::STATUS_SUSPENDED], true)) {
                throw ValidationException::withMessages(['license' => 'La licencia no puede transferirse ni usar un estado desconocido.']);
            }
        });
    }
}
