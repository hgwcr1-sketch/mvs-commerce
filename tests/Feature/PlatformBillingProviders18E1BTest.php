<?php

namespace Tests\Feature;

use App\Models\BillingProviderSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BLOQUE 18E-1B — administración de proveedores de pago en el Panel Maestro
 * existente (sección en platform.index, PATCH platform.billing-providers).
 *
 * Cubre: autorización (solo platform admin), persistencia/upsert, validación
 * de producto/país/proveedor/modo, bloqueo de LIVE sin credenciales o con
 * adaptador stub, y privacidad: solo «Configurado/No configurado», jamás
 * valores de credenciales.
 */
class PlatformBillingProviders18E1BTest extends TestCase
{
    use RefreshDatabase;

    private function platformAdmin(): User
    {
        return User::factory()->create(['is_active' => true, 'is_platform_admin' => true]);
    }

    private function patchSetting(array $overrides = [])
    {
        return $this->actingAs($this->platformAdmin())->patch(
            route('platform.billing-providers.update'),
            array_merge([
                'product' => 'cobros',
                'country_iso' => 'CR',
                'provider' => 'stripe',
                'mode' => 'test',
                'is_active' => '1',
            ], $overrides)
        );
    }

    public function test_non_platform_admin_is_blocked(): void
    {
        $user = User::factory()->create(['is_active' => true, 'is_platform_admin' => false]);

        $this->actingAs($user)
            ->patch(route('platform.billing-providers.update'), [
                'product' => 'cobros', 'country_iso' => 'CR',
                'provider' => 'stripe', 'mode' => 'test', 'is_active' => '1',
            ])
            ->assertForbidden();

        $this->assertSame(0, BillingProviderSetting::query()->count());
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->patch(route('platform.billing-providers.update'), [
            'product' => 'cobros', 'country_iso' => 'CR',
            'provider' => 'stripe', 'mode' => 'test',
        ])->assertRedirect(route('login'));
    }

    public function test_platform_admin_persists_setting_and_shows_section(): void
    {
        $this->patchSetting()->assertRedirect(route('platform.index'));

        $setting = BillingProviderSetting::query()->sole();
        $this->assertSame('cobros', $setting->product);
        $this->assertSame('CR', $setting->country_iso);
        $this->assertSame('stripe', $setting->provider);
        $this->assertSame('test', $setting->mode);
        $this->assertTrue($setting->is_active);

        $this->actingAs($this->platformAdmin())
            ->get(route('platform.index'))
            ->assertOk()
            ->assertSee('MVS Billing — Proveedores de pago')
            ->assertSee('cobros')
            ->assertSee('Habilitado');
    }

    public function test_upsert_updates_single_row_per_product_country(): void
    {
        $this->patchSetting()->assertRedirect(route('platform.index'));
        $this->patchSetting(['provider' => 'sinpe', 'is_active' => '0'])->assertRedirect(route('platform.index'));

        $this->assertSame(1, BillingProviderSetting::query()->count());
        $setting = BillingProviderSetting::query()->sole();
        $this->assertSame('sinpe', $setting->provider);
        $this->assertFalse($setting->is_active);
    }

    public function test_validation_rejects_invalid_product_country_provider_and_mode(): void
    {
        $this->patchSetting(['product' => 'Cobros!'])->assertSessionHasErrors('product');
        $this->patchSetting(['country_iso' => 'CRI'])->assertSessionHasErrors('country_iso');
        $this->patchSetting(['provider' => 'no_existe'])->assertSessionHasErrors('provider');
        $this->patchSetting(['mode' => 'demo'])->assertSessionHasErrors('mode');

        $this->assertSame(0, BillingProviderSetting::query()->count());
    }

    public function test_live_mode_is_blocked_without_environment_credentials(): void
    {
        config([
            'billing.stripe.secret_key_live' => null,
            'billing.stripe.publishable_key_live' => null,
            'billing.stripe.webhook_secret_live' => null,
        ]);

        $this->patchSetting(['mode' => 'live'])->assertSessionHasErrors('mode');
        $this->assertSame(0, BillingProviderSetting::query()->count());
    }

    public function test_live_mode_is_allowed_when_credentials_are_configured(): void
    {
        config([
            'billing.stripe.secret_key_live' => 'sk_live_configured',
            'billing.stripe.publishable_key_live' => 'pk_live_configured',
            'billing.stripe.webhook_secret_live' => 'whsec_live_configured',
        ]);

        $this->patchSetting(['mode' => 'live'])->assertRedirect(route('platform.index'));

        $setting = BillingProviderSetting::query()->sole();
        $this->assertSame('live', $setting->mode);
        $this->assertTrue($setting->is_active);
    }

    public function test_stub_provider_cannot_enable_live_mode(): void
    {
        // Tilopay es stub sin integración real: nunca tiene credenciales.
        $this->patchSetting(['provider' => 'tilopay', 'mode' => 'live'])
            ->assertSessionHasErrors('mode');

        $this->assertSame(0, BillingProviderSetting::query()->count());
    }

    public function test_credentials_are_never_rendered_only_configured_status(): void
    {
        config([
            'billing.stripe.secret_key_test' => 'sk_test_NOEXPOSE123',
            'billing.stripe.publishable_key_test' => 'pk_test_NOEXPOSE123',
            'billing.stripe.webhook_secret_test' => 'whsec_NOEXPOSE123',
        ]);

        $this->patchSetting()->assertRedirect(route('platform.index'));

        $this->actingAs($this->platformAdmin())
            ->get(route('platform.index'))
            ->assertOk()
            ->assertSee('Configurado')
            ->assertDontSee('NOEXPOSE')
            ->assertDontSee('sk_test_')
            ->assertDontSee('whsec_');
    }
}
