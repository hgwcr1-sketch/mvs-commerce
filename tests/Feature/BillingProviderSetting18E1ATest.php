<?php

namespace Tests\Feature;

use App\Models\BillingProviderSetting;
use App\Services\Billing\Contracts\PaymentProviderInterface;
use App\Services\Billing\DTOs\PaymentEvent;
use App\Services\Billing\DTOs\PaymentRequest;
use App\Services\Billing\Exceptions\PaymentProviderUnavailableException;
use App\Services\Billing\PaymentProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BLOQUE 18E-1A — configuración persistente de proveedores por producto+país.
 *
 * Cubre: prioridad de la elección persistida sobre config/billing.php,
 * aislamiento por producto y país, fallback cuando no hay elección,
 * indisponible seguro (sin caer a otro proveedor) cuando la elección está
 * deshabilitada o el proveedor no está disponible, normalización de país y
 * ausencia de columnas de credenciales en la tabla.
 */
class BillingProviderSetting18E1ATest extends TestCase
{
    use RefreshDatabase;

    private PaymentProviderRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = app(PaymentProviderRegistry::class);
        $this->registry->flush();
    }

    /** Registra proveedores de prueba en config (fallback = primero disponible). */
    private function registerFakes(): void
    {
        config(['billing.providers' => [
            'fake_a' => FakeProviderA18E1A::class,
            'fake_b' => FakeProviderB18E1A::class,
            'fake_off' => FakeProviderOff18E1A::class,
        ]]);
        $this->registry->flush();
    }

    public function test_persisted_setting_takes_priority_over_config_fallback(): void
    {
        $this->registerFakes();

        BillingProviderSetting::create([
            'product' => 'cobros',
            'country_iso' => 'CR',
            'provider' => 'fake_b',
            'mode' => BillingProviderSetting::MODE_TEST,
            'is_active' => true,
        ]);

        $provider = $this->registry->forProduct('cobros', 'CR');

        $this->assertSame('fake_b', $provider->key());
    }

    public function test_selection_is_isolated_by_product_and_country(): void
    {
        $this->registerFakes();

        BillingProviderSetting::create([
            'product' => 'cobros',
            'country_iso' => 'CR',
            'provider' => 'fake_b',
            'mode' => BillingProviderSetting::MODE_TEST,
            'is_active' => true,
        ]);

        // Mismo país, otro producto: sin elección → fallback.
        $this->assertSame('fake_a', $this->registry->forProduct('academia', 'CR')->key());

        // Mismo producto, otro país: sin elección → fallback.
        $this->assertSame('fake_a', $this->registry->forProduct('cobros', 'CO')->key());
    }

    public function test_falls_back_to_config_when_no_persisted_setting_exists(): void
    {
        $this->registerFakes();

        $this->assertSame('fake_a', $this->registry->forProduct('cobros', 'CR')->key());
    }

    public function test_disabled_selection_blocks_without_falling_back_to_another_provider(): void
    {
        $this->registerFakes();

        BillingProviderSetting::create([
            'product' => 'cobros',
            'country_iso' => 'CR',
            'provider' => 'fake_b',
            'mode' => BillingProviderSetting::MODE_TEST,
            'is_active' => false,
        ]);

        try {
            $this->registry->forProduct('cobros', 'CR');
            $this->fail('Una elección deshabilitada debe devolver indisponible, no otro proveedor.');
        } catch (PaymentProviderUnavailableException $e) {
            $this->assertStringContainsString('deshabilitada', $e->getMessage());
        }
    }

    public function test_unavailable_explicit_provider_blocks_without_falling_back(): void
    {
        $this->registerFakes();

        BillingProviderSetting::create([
            'product' => 'cobros',
            'country_iso' => 'CR',
            'provider' => 'fake_off',
            'mode' => BillingProviderSetting::MODE_TEST,
            'is_active' => true,
        ]);

        try {
            $this->registry->forProduct('cobros', 'CR');
            $this->fail('Un proveedor explícito no disponible debe bloquear sin seleccionar otro.');
        } catch (PaymentProviderUnavailableException $e) {
            $this->assertStringContainsString('no está configurado', $e->getMessage());
        }
    }

    public function test_country_iso_is_normalized_and_unique_per_product_country(): void
    {
        $this->registerFakes();

        BillingProviderSetting::create([
            'product' => 'cobros',
            'country_iso' => 'cr',
            'provider' => 'fake_b',
            'mode' => BillingProviderSetting::MODE_TEST,
            'is_active' => true,
        ]);

        $this->assertSame('CR', BillingProviderSetting::query()->sole()->country_iso);
        $this->assertSame('fake_b', $this->registry->forProduct('cobros', 'cr')->key());

        $this->expectException(\Illuminate\Database\QueryException::class);
        BillingProviderSetting::create([
            'product' => 'cobros',
            'country_iso' => 'CR',
            'provider' => 'fake_a',
            'mode' => BillingProviderSetting::MODE_TEST,
            'is_active' => true,
        ]);
    }

    public function test_table_never_stores_credentials(): void
    {
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing('billing_provider_settings');

        foreach (['secret', 'key', 'token', 'credential', 'password'] as $forbidden) {
            foreach ($columns as $column) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $column,
                    "La tabla de configuración no debe almacenar credenciales (columna «{$column}»)."
                );
            }
        }

        $this->assertEqualsCanonicalizing(
            ['id', 'product', 'country_iso', 'provider', 'mode', 'is_active', 'created_at', 'updated_at'],
            $columns
        );
    }
}

/**
 * Proveedores de prueba para 18E-1A: disponibles (A/B) y no disponible (Off).
 * Sin integración real: solo sostienen la resolución del registro.
 */
class FakeProviderA18E1A implements PaymentProviderInterface
{
    public function key(): string
    {
        return 'fake_a';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function createPayment(PaymentRequest $request): PaymentEvent
    {
        throw PaymentProviderUnavailableException::notImplemented($this->key());
    }

    public function confirmPayment(string $externalId): PaymentEvent
    {
        throw PaymentProviderUnavailableException::notImplemented($this->key());
    }

    public function refundPayment(string $externalId): PaymentEvent
    {
        throw PaymentProviderUnavailableException::notImplemented($this->key());
    }
}

class FakeProviderB18E1A implements PaymentProviderInterface
{
    public function key(): string
    {
        return 'fake_b';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function createPayment(PaymentRequest $request): PaymentEvent
    {
        throw PaymentProviderUnavailableException::notImplemented($this->key());
    }

    public function confirmPayment(string $externalId): PaymentEvent
    {
        throw PaymentProviderUnavailableException::notImplemented($this->key());
    }

    public function refundPayment(string $externalId): PaymentEvent
    {
        throw PaymentProviderUnavailableException::notImplemented($this->key());
    }
}

class FakeProviderOff18E1A implements PaymentProviderInterface
{
    public function key(): string
    {
        return 'fake_off';
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function createPayment(PaymentRequest $request): PaymentEvent
    {
        throw PaymentProviderUnavailableException::notImplemented($this->key());
    }

    public function confirmPayment(string $externalId): PaymentEvent
    {
        throw PaymentProviderUnavailableException::notImplemented($this->key());
    }

    public function refundPayment(string $externalId): PaymentEvent
    {
        throw PaymentProviderUnavailableException::notImplemented($this->key());
    }
}
