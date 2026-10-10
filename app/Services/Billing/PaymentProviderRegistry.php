<?php

namespace App\Services\Billing;

use App\Models\BillingProviderSetting;
use App\Services\Billing\Contracts\PaymentProviderInterface;
use App\Services\Billing\Exceptions\PaymentProviderUnavailableException;
use Illuminate\Support\Facades\App;

/**
 * BLOQUE 17B-1 — registro de proveedores de pago por producto y país.
 *
 * Resuelve el proveedor adecuado para cada producto+país a partir de la
 * configuración (config/billing.php), permitiendo reemplazar Stripe por
 * Tilopay/Fygaro sin tocar código. Aísla por producto: cada entrada es
 * independiente.
 */
class PaymentProviderRegistry
{
    /** @var array<string, PaymentProviderInterface> */
    private array $resolved = [];

    /**
     * Registra o reemplaza un proveedor en tiempo de ejecución (tests,
     * sobrecarga por entorno). No persiste en configuración.
     */
    public function register(PaymentProviderInterface $provider): void
    {
        $this->resolved[$provider->key()] = $provider;
    }

    /** Devuelve el proveedor por su clave, o lanza si no existe. */
    public function get(string $providerKey): PaymentProviderInterface
    {
        if (isset($this->resolved[$providerKey])) {
            return $this->resolved[$providerKey];
        }

        $class = config("billing.providers.{$providerKey}");

        if ($class === null || ! class_exists($class)) {
            throw PaymentProviderUnavailableException::forProvider(
                $providerKey,
                'No está registrado en la configuración de pagos.',
            );
        }

        $provider = App::make($class);
        $this->resolved[$providerKey] = $provider;

        return $provider;
    }

    /**
     * Resuelve el proveedor por defecto para un producto+país.
     *
     * 18E-1A: prioriza la elección explícita persistida por Platform Admin
     * (billing_provider_settings). Si la elección existe pero está
     * deshabilitada o el proveedor no está disponible (no configurado),
     * se devuelve indisponible de forma segura SIN caer a otro proveedor.
     * Si no hay elección persistida, se conserva el comportamiento previo:
     * config(billing.product_providers) y, en su defecto, el primer
     * proveedor disponible registrado.
     */
    public function forProduct(string $product, string $countryIso): PaymentProviderInterface
    {
        $country = strtoupper($countryIso);

        $setting = BillingProviderSetting::query()
            ->forProductCountry($product, $country)
            ->first();

        if ($setting !== null) {
            if (! $setting->is_active) {
                throw PaymentProviderUnavailableException::forProvider(
                    $product,
                    "Elección explícita deshabilitada para {$country}; no se selecciona otro proveedor automáticamente."
                );
            }

            // get() lanza si el proveedor no está registrado en la configuración.
            $provider = $this->get($setting->provider);

            if (! $provider->isAvailable()) {
                throw PaymentProviderUnavailableException::forProvider(
                    $setting->provider,
                    "Proveedor elegido para {$product}/{$country} no está configurado; no se selecciona otro proveedor automáticamente."
                );
            }

            return $provider;
        }

        $configured = config("billing.product_providers.{$product}.{$country}");

        if (is_string($configured) && $configured !== '') {
            return $this->get($configured);
        }

        foreach (array_keys((array) config('billing.providers', [])) as $key) {
            try {
                $provider = $this->get((string) $key);
                if ($provider->isAvailable()) {
                    return $provider;
                }
            } catch (PaymentProviderUnavailableException) {
                continue;
            }
        }

        throw PaymentProviderUnavailableException::forProvider(
            $product,
            "No hay proveedor habilitado para el país {$countryIso}.",
        );
    }

    /** Limpia la caché de proveedores resueltos (tests). */
    public function flush(): void
    {
        $this->resolved = [];
    }
}
