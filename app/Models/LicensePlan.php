<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LicensePlan extends Model
{
    protected $fillable = ['code', 'name', 'branch_limit', 'user_limit', 'modules', 'is_active', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['branch_limit' => 'integer', 'user_limit' => 'integer', 'modules' => 'array', 'is_active' => 'boolean'];
    }

    public function licenses()
    {
        return $this->hasMany(CompanyLicense::class);
    }
}
