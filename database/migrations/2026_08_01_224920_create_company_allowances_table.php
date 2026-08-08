<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'user_id',
    'allowed_companies',
])]

class CompanyAllowance extends Model
{
    /**
     * Usuario propietario de esta autorización.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}