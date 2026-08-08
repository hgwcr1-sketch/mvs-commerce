<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'customer_type',
        'identification_type',
        'identification',
        'name',
        'commercial_name',
        'taxpayer_name',
        'phone',
        'mobile',
        'email',
        'accepts_email_invoice',
        'country_id',
        'province_id',
        'canton_id',
        'district_id',
        'address',
        'notes',
        'credit_limit',
        'credit_days',
        'points',
        'birth_date',
        'is_active',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'credit_limit' => 'decimal:2',
        'is_active' => 'boolean',
        'accepts_email_invoice' => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relaciones
    |--------------------------------------------------------------------------
    */

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function province()
    {
        return $this->belongsTo(Province::class);
    }

    public function canton()
    {
        return $this->belongsTo(Canton::class);
    }

    public function district()
    {
        return $this->belongsTo(District::class);
    }

    public function contacts()
    {
        return $this->hasMany(CustomerContact::class);
    }

    public function addresses()
    {
        return $this->hasMany(CustomerAddress::class);
    }
}