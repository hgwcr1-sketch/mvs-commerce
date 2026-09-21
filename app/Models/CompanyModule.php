<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyModule extends Model
{
    protected $fillable = [
        'company_id', 'module_key', 'is_enabled',
        'license_mode', 'collector_limit', 'admin_license_charged',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'admin_license_charged' => 'boolean',
            'collector_limit' => 'integer',
        ];
    }

    public const LICENSE_MODES = ['individual', 'company_cupo'];

    public function isCobros(): bool
    {
        return $this->module_key === 'cobros';
    }
}
