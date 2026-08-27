<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyLicense extends Model
{
    public const STATUSES = ['trial', 'active', 'grace', 'expired', 'suspended', 'cancelled'];

    public const OPERABLE = ['trial', 'active', 'grace'];

    protected $fillable = ['company_id', 'status', 'plan', 'starts_at', 'expires_at', 'next_renewal_at', 'grace_until', 'user_limit', 'branch_limit', 'notes', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'expires_at' => 'datetime', 'next_renewal_at' => 'datetime', 'grace_until' => 'datetime', 'user_limit' => 'integer', 'branch_limit' => 'integer'];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
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
