<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'owner_user_id',
    'trade_name',
    'legal_name',
    'identification_type',
    'identification_number',
    'phone',
    'email',
    'country_id',
    'province_id',
    'canton_id',
    'district_id',
    'address',
    'logo',
    'currency',
    'timezone',
    'is_active',
])]

class Company extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function users()
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role_id')
            ->withTimestamps();
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

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

    public function roles()
    {
        return $this->hasMany(Role::class);
    }

    public function branches()
    {
        return $this->hasMany(Branch::class);
    }

    public function customers()
    {
        return $this->hasMany(Customer::class);
    }

    /**
     * Productos pertenecientes a la empresa.
     */
    public function products()
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Categorías pertenecientes a la empresa.
     */
    public function productCategories()
    {
        return $this->hasMany(ProductCategory::class);
    }

    /**
     * Marcas pertenecientes a la empresa.
     */
    public function brands()
    {
        return $this->hasMany(Brand::class);
    }

    /**
     * Unidades pertenecientes a la empresa.
     */
    public function units()
    {
        return $this->hasMany(Unit::class);
    }

    /**
     * Configuración de compras de la empresa.
     */
    public function purchaseSetting()
    {
        return $this->hasOne(CompanyPurchaseSetting::class);
    }

    public function cashSetting()
    {
        return $this->hasOne(CompanyCashSetting::class);
    }

    public function cashRegisters()
    {
        return $this->hasMany(CashRegister::class);
    }

    public function cashSessions()
    {
        return $this->hasMany(CashSession::class);
    }

    public function cashDenominations()
    {
        return $this->hasMany(CashDenomination::class);
    }
}
