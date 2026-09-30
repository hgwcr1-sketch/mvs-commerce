<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LicensePlan extends Model
{
    protected $fillable = ['code', 'name', 'base_price_usd', 'extra_branch_price_usd', 'extra_user_price_usd', 'fiscal_plan_code', 'fiscal_monthly_price_crc', 'fiscal_included_quota', 'is_custom', 'branch_limit', 'user_limit', 'modules', 'is_active', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'branch_limit' => 'integer',
            'user_limit' => 'integer',
            'base_price_usd' => 'decimal:2',
            'extra_branch_price_usd' => 'decimal:2',
            'extra_user_price_usd' => 'decimal:2',
            'fiscal_monthly_price_crc' => 'decimal:2',
            'fiscal_included_quota' => 'integer',
            'is_custom' => 'boolean',
            'modules' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function hasBasePrice(): bool
    {
        return $this->base_price_usd !== null;
    }

    public function licenses()
    {
        return $this->hasMany(CompanyLicense::class);
    }
}
