<?php

namespace App\Models;

use App\Models\Concerns\HasCobrosLicenseLifecycle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Licencia individual de un cobrador (o del dueño si `admin_license_charged`)
 * en modalidad `individual`. En modalidad `company_cupo` no se crean filas:
 * el contrato de empresa y el cupo rigen el acceso.
 */
class CobrosCollectorLicense extends Model
{
    use HasCobrosLicenseLifecycle;

    protected $fillable = [
        'company_id',
        'user_id',
        'status',
        'starts_at',
        'expires_at',
        'suspended_at',
        'suspended_by_user_id',
        'renewed_at',
        'notes',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function suspendedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'suspended_by_user_id');
    }
}
