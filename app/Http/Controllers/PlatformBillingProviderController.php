<?php

namespace App\Http\Controllers;

use App\Models\BillingProviderSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * BLOQUE 18E-1B — administración de proveedores de pago desde el Panel
 * Maestro existente (grupo platform., auth + platform.admin + CSRF).
 *
 * Guarda SOLO la elección comercial (producto, país, proveedor, modo,
 * habilitado) en BillingProviderSetting. Nunca recibe ni muestra
 * credenciales: la UI únicamente indica si están configuradas. Bloquea
 * el modo LIVE si faltan credenciales del entorno o el adaptador sigue
 * siendo un stub sin integración real.
 */
class PlatformBillingProviderController extends Controller
{
    /**
     * Upsert de la elección explícita por producto+país. La selección
     * persistida manda en PaymentProviderRegistry::forProduct() (sin cambio
     * automático de proveedor si queda deshabilitada o no disponible).
     */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9][a-z0-9_-]*$/'],
            'country_iso' => ['required', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'provider' => ['required', 'string', Rule::in(array_keys((array) config('billing.providers', [])))],
            'mode' => ['required', Rule::in(BillingProviderSetting::MODES)],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'product.regex' => 'El producto solo admite minúsculas, números y guiones (ej: cobros).',
            'country_iso.size' => 'El país debe ser el código ISO alfa-2 de dos letras (ej: CR).',
            'country_iso.regex' => 'El país debe ser el código ISO alfa-2 de dos letras (ej: CR).',
            'provider.in' => 'El proveedor no está registrado en el motor de pagos MVS Billing.',
        ]);

        $country = strtoupper($data['country_iso']);

        // 18E-1B: LIVE exige credenciales del entorno presentes y adaptador
        // real; un stub (Tilopay/Fygaro/SINPE) nunca tiene esquema y queda
        // bloqueado. No se simula disponibilidad.
        if ($data['mode'] === BillingProviderSetting::MODE_LIVE
            && ! self::credentialsConfigured($data['provider'], BillingProviderSetting::MODE_LIVE)) {
            return back()->withErrors([
                'mode' => "No se puede habilitar LIVE para «{$data['provider']}»: faltan credenciales LIVE en el entorno o el adaptador aún no tiene integración real.",
            ])->withInput();
        }

        BillingProviderSetting::query()->updateOrCreate(
            ['product' => $data['product'], 'country_iso' => $country],
            [
                'provider' => $data['provider'],
                'mode' => $data['mode'],
                'is_active' => (bool) ($data['is_active'] ?? false),
            ],
        );

        return redirect()
            ->route('platform.index')
            ->with('success', "Proveedor de pago actualizado para {$data['product']}/{$country}.");
    }

    /**
     * ¿Existen las credenciales del proveedor para el modo dado? Solo
     * consulta PRESENCIA en config (env), nunca devuelve valores. Los
     * adaptadores stub no declaran credenciales: false.
     */
    public static function credentialsConfigured(string $providerKey, string $mode): bool
    {
        if ($providerKey !== 'stripe') {
            return false;
        }

        $suffix = $mode === BillingProviderSetting::MODE_LIVE ? 'live' : 'test';

        return ! empty(config("billing.stripe.secret_key_{$suffix}"))
            && ! empty(config("billing.stripe.publishable_key_{$suffix}"))
            && ! empty(config("billing.stripe.webhook_secret_{$suffix}"));
    }
}
