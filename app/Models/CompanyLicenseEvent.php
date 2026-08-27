<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyLicenseEvent extends Model
{
    protected $fillable = ['company_license_id', 'company_id', 'actor_id', 'action', 'from_status', 'to_status', 'snapshot', 'notes'];

    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
