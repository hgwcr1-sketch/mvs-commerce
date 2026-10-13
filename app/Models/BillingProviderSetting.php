<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * BLOQUE 18E-1A — elección persistente de proveedor de pago por producto+país.
 *
 * Solo guarda la configuración comercial (proveedor, modo, habilitado).
 * NUNCA credenciales: las claves viven en variables de entorno separadas
 * por entorno (STRIPE_*_TEST / STRIPE_*_LIVE) y no se persisten aquí.
 */
class BillingProviderSetting extends Model
{
    protected $table = 'billing_provider_settings';

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public const MODE_TEST = 'test';

    public const MODE_LIVE = 'live';

    public const MODES = [
        self::MODE_TEST,
        self::MODE_LIVE,
    ];

    /** Normaliza el país a ISO-3166 alfa-2 en mayúsculas (CR, CO, ...). */
    public function setCountryIsoAttribute(string $value): void
    {
        $this->attributes['country_iso'] = strtoupper(trim($value));
    }

    /** @return Builder<self> elección explícita para un producto+país. */
    public function scopeForProductCountry(Builder $query, string $product, string $countryIso): Builder
    {
        return $query
            ->where('product', $product)
            ->where('country_iso', strtoupper($countryIso));
    }
}
