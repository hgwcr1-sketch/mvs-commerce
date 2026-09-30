<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyLicense extends Model
{
    public const STATUSES = ['trial', 'active', 'grace', 'expired', 'suspended', 'cancelled'];

    public const OPERABLE = ['trial', 'active', 'grace'];

protected $fillable = ['company_id', 'license_plan_id', 'contract_snapshot', 'status', 'plan', 'starts_at', 'expires_at', 'next_renewal_at', 'grace_until', 'user_limit', 'branch_limit', 'fiscal_enabled', 'fiscal_monthly_quota', 'fiscal_overage_enabled', 'fiscal_overage_unit_price', 'notes', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['contract_snapshot' => 'array', 'starts_at' => 'datetime', 'expires_at' => 'datetime', 'next_renewal_at' => 'datetime', 'grace_until' => 'datetime', 'user_limit' => 'integer', 'branch_limit' => 'integer', 'fiscal_enabled' => 'boolean', 'fiscal_monthly_quota' => 'integer', 'fiscal_overage_enabled' => 'boolean', 'fiscal_overage_unit_price' => 'decimal:4'];
    }

    /**
     * Snapshot contractual congelado en el alta. Las empresas anteriores a
     * esta versión NO tienen snapshot: su precio queda pendiente y jamás se
     * infiere desde la plantilla vigente.
     */
    public function contractSnapshot(): ?array
    {
        return is_array($this->contract_snapshot) ? $this->contract_snapshot : null;
    }

    public function hasContractSnapshot(): bool
    {
        return $this->contractSnapshot() !== null;
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function planTemplate()
    {
        return $this->belongsTo(LicensePlan::class, 'license_plan_id');
    }

    public function events()
    {
        return $this->hasMany(CompanyLicenseEvent::class)->latest();
    }

    public function isOperable(): bool
    {
        return in_array($this->status, self::OPERABLE, true);
    }
}
