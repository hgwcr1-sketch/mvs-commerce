<?php

namespace App\Models;

use App\Services\CustomerPublicCodeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'customer_code',
        'customer_type',
        'identification_type',
        'identification',
        'name',
        'commercial_name',
        'taxpayer_name',
        'phone',
        'phone_country_code',
        'mobile',
        'phone_verified_at',
        'email',
        'email_verified_at',
        'accepts_email_invoice',
        'country_id',
        'province_id',
        'canton_id',
        'district_id',
        'address',
        'latitude',
        'longitude',
        'location_reference',
        'notes',
        'credit_limit',
        'credit_days',
        'price_level',
        'points',
        'birth_date',
        'is_active',
        'public_code',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'credit_limit' => 'decimal:2',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'location_validated_at' => 'datetime',
        'is_active' => 'boolean',
        'accepts_email_invoice' => 'boolean',
        'phone_verified_at' => 'datetime',
        'email_verified_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Customer $customer) {
            if (empty($customer->public_code)) {
                $customer->public_code = app(CustomerPublicCodeService::class)->randomCode();
                // Reserva: reintentar si colisión dentro del mismo request (único por empresa)
                $attempts = 0;
                while ($attempts < 5 && static::query()->where('company_id', $customer->company_id)->where('public_code', $customer->public_code)->exists()) {
                    $customer->public_code = app(CustomerPublicCodeService::class)->randomCode();
                    $attempts++;
                }
            }

            if (empty($customer->customer_code) && !empty($customer->company_id)) {
                $customer->customer_code = \App\Models\CompanySequence::nextCustomerCode($customer->company_id);
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Relaciones
    |--------------------------------------------------------------------------
    */

    public function getFormattedCustomerCodeAttribute(): ?string
    {
        return $this->customer_code;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
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

    public function contacts()
    {
        return $this->hasMany(CustomerContact::class);
    }

    public function addresses()
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function accountsReceivable(): HasMany
    {
        return $this->hasMany(AccountReceivable::class);
    }

    public function loyaltyContacts(): HasMany
    {
        return $this->hasMany(LoyaltyCustomerContact::class);
    }

    /**
     * Usuario que validó la ubicación del cliente (R01 RouteOS).
     */
    public function locationValidatedBy()
    {
        return $this->belongsTo(User::class, 'location_validated_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Ubicación (R01 MVS RouteOS)
    |--------------------------------------------------------------------------
    */

    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function isLocationValidated(): bool
    {
        return $this->location_validated_at !== null;
    }

    /**
     * Enlace a Google Maps con coordenadas reales (no textual).
     * URL gratuita universal; no requiere API key ni servicio pagado.
     */
    public function getGoogleMapsUrlAttribute(): ?string
    {
        if (! $this->hasLocation()) {
            return null;
        }

        return 'https://www.google.com/maps/search/?api=1&query='
            .$this->latitude.','.$this->longitude;
    }

    /**
     * Enlace a Waze con navegación directa a las coordenadas.
     */
    public function getWazeUrlAttribute(): ?string
    {
        if (! $this->hasLocation()) {
            return null;
        }

        return 'https://waze.com/ul?ll='
            .$this->latitude.','.$this->longitude.'&navigate=yes';
    }
}

