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
    'whatsapp_enabled',
    'default_phone_country_code',
    'whatsapp_phone_country_code',
    'whatsapp_phone',
    'email',
    'country_id',
    'province_id',
    'canton_id',
    'district_id',
    'address',
    'logo',
    'currency',
    'timezone',
    'credit_alert_days',
    'layaway_validity_days',
    'layaway_alert_days',
    'payable_alert_days',
    'credit_note_expiration_policy',
    'credit_note_custom_expiration_days',
    'credit_note_consumer_final',
    'is_active',
])]

class Company extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'whatsapp_enabled' => 'boolean',
            'credit_alert_days' => 'integer',
            'layaway_validity_days' => 'integer',
            'layaway_alert_days' => 'integer',
            'payable_alert_days' => 'integer',
            'credit_note_custom_expiration_days' => 'integer',
            'credit_note_consumer_final' => 'boolean',
        ];
    }

    /**
     * Días efectivos de vigencia para notas de crédito.
     *
     * null = sin expiración.
     */
    public function ncExpirationDays(): ?int
    {
        return match ($this->credit_note_expiration_policy) {
            'none' => null,
            '30' => 30,
            '60' => 60,
            '90' => 90,
            'custom' => $this->credit_note_custom_expiration_days
                ? (int) $this->credit_note_custom_expiration_days
                : null,
            default => null,
        };
    }

    /**
     * Indica si la empresa está habilitada para emitir Notas de Crédito
     * para Consumer Final (ventas sin cliente identificado).
     *
     * Desactivado por defecto. Solo una empresa habilitada explícitamente
     * puede emitir NC Consumer Final desde una devolución válida.
     */
    public function consumerFinalCreditNotesEnabled(): bool
    {
        return (bool) $this->credit_note_consumer_final;
    }

    public function users()
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role_id')
            ->withTimestamps();
    }

    public function modules()
    {
        return $this->hasMany(CompanyModule::class);
    }

    public function license()
    {
        return $this->hasOne(CompanyLicense::class);
    }

    public function isModuleEnabled(string $moduleKey): bool
    {
        $module = $this->relationLoaded('modules')
            ? $this->modules->firstWhere('module_key', $moduleKey)
            : $this->modules()->where('module_key', $moduleKey)->first();

        return $module?->is_enabled ?? true;
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

    public function backupSetting()
    {
        return $this->hasOne(CompanyBackupSetting::class);
    }

    public function backupRecords()
    {
        return $this->hasMany(CompanyBackupRecord::class);
    }
}
