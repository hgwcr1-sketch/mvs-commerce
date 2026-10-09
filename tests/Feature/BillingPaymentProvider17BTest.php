<?php

namespace Tests\Feature;

use App\Models\BillingPaymentRecord;
use App\Services\Billing\Adapters\StripePaymentProvider;
use App\Services\Billing\Contracts\PaymentConfirmedHandlerInterface;
use App\Services\Billing\Contracts\PaymentEventTypes;
use App\Services\Billing\DTOs\PaymentEvent;
use App\Services\Billing\DTOs\PaymentRequest;
use App\Services\Billing\Exceptions\PaymentProviderUnavailableException;
use App\Services\Billing\PaymentDispatcher;
use App\Services\Billing\PaymentProviderRegistry;
use App\Services\Billing\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BLOQUE 17B-1 — pruebas focales del motor de pagos multi-proveedor MVS.
 */
class BillingPaymentProvider17BTest extends TestCase
{
    use RefreshDatabase;

    private PaymentProviderRegistry $registry;

    private PaymentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new PaymentProviderRegistry();
        $dispatcher = new PaymentDispatcher($this->app);
        $this->service = new PaymentService($this->registry, $dispatcher);
    }

    private function makeRequest(array $overrides = []): PaymentRequest
    {
        return new PaymentRequest(
            product: $overrides['product'] ?? 'cobros',
            companyId: $overrides['company_id'] ?? '1',
            contractReference: $overrides['contract_reference'] ?? 'CTR-001',
            currency: $overrides['currency'] ?? 'CRC',
            amount: $overrides['amount'] ?? '10000.0000',
            periodYear: $overrides['period_year'] ?? 2026,
            periodMonth: $overrides['period_month'] ?? 10,
        );
    }

    private function makeEvent(array $overrides = []): PaymentEvent
    {
        return new PaymentEvent(
            type: $overrides['type'] ?? PaymentEventTypes::PENDING,
            provider: $overrides['provider'] ?? 'stripe',
            externalId: $overrides['external_id'] ?? 'ext-001',
            product: $overrides['product'] ?? 'cobros',
            companyId: $overrides['company_id'] ?? '1',
            contractReference: $overrides['contract_reference'] ?? 'CTR-001',
            currency: $overrides['currency'] ?? 'CRC',
            amount: $overrides['amount'] ?? '10000.0000',
            periodYear: $overrides['period_year'] ?? 2026,
            periodMonth: $overrides['period_month'] ?? 10,
        );
    }

    // ------------------------------------------------------------------
    // Contrato genérico
    // ------------------------------------------------------------------

    public function test_provider_interface_and_event_types_are_defined(): void
    {
        $provider = new StripePaymentProvider();

        $this->assertSame('stripe', $provider->key());
        $this->assertFalse($provider->isAvailable());

        $this->assertSame('pending', PaymentEventTypes::PENDING);
        $this->assertSame('confirmed', PaymentEventTypes::CONFIRMED);
        $this->assertSame('failed', PaymentEventTypes::FAILED);
        $this->assertSame('refunded', PaymentEventTypes::REFUNDED);
        $this->assertSame('renewal', PaymentEventTypes::RENEWAL);
        $this->assertTrue(PaymentEventTypes::isValid('confirmed'));
        $this->assertFalse(PaymentEventTypes::isValid('unknown'));
    }

    public function test_payment_event_rejects_unknown_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PaymentEvent(
            type: 'bogus',
            provider: 'stripe',
            externalId: 'x',
            product: 'cobros',
            companyId: '1',
            contractReference: 'CTR',
            currency: 'CRC',
            amount: '1000.0000',
        );
    }

    // ------------------------------------------------------------------
    // Proveedor no disponible — nunca simula éxito
    // ------------------------------------------------------------------

    public function test_unavailable_provider_throws_explicitly_and_never_simulates_success(): void
    {
        $provider = $this->registry->get('stripe');

        $this->assertFalse($provider->isAvailable());

        foreach ([
            fn () => $provider->createPayment($this->makeRequest()),
            fn () => $provider->confirmPayment('ext-001'),
            fn () => $provider->refundPayment('ext-001'),
        ] as $callable) {
            try {
                $callable();
                $this->fail('El proveedor no disponible no debe devolver resultado.');
            } catch (PaymentProviderUnavailableException $e) {
                $this->assertStringContainsString('Stripe no est', $e->getMessage());
            }
        }

        $this->assertSame(0, BillingPaymentRecord::query()->count());
    }

    public function test_all_stub_providers_are_unavailable(): void
    {
        foreach (['stripe', 'tilopay', 'fygaro', 'sinpe'] as $key) {
            $provider = $this->registry->get($key);
            $this->assertFalse($provider->isAvailable(), "El proveedor {$key} no debe simular disponibilidad.");
            $this->assertSame($key, $provider->key());
        }
    }

    public function test_registry_throws_for_unconfigured_provider(): void
    {
        $this->expectException(PaymentProviderUnavailableException::class);

        $this->registry->get('nonexistent');
    }

    public function test_registry_for_product_throws_when_no_provider_available(): void
    {
        $this->expectException(PaymentProviderUnavailableException::class);

        $this->registry->forProduct('cobros', 'CR');
    }

    // ------------------------------------------------------------------
    // Idempotencia por proveedor + identificador externo
    // ------------------------------------------------------------------

    public function test_process_event_is_idempotent_by_provider_and_external_id(): void
    {
        $confirmed = $this->makeEvent([
            'type' => PaymentEventTypes::CONFIRMED,
            'external_id' => 'pi_idem_1',
        ]);

        $first = $this->service->processEvent($confirmed);
        $second = $this->service->processEvent($confirmed);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, BillingPaymentRecord::query()->count());
        $this->assertSame(BillingPaymentRecord::STATUS_CONFIRMED, $first->status);
        $this->assertNotNull($first->confirmed_at);
    }

    public function test_same_external_id_different_provider_creates_separate_records(): void
    {
        $this->service->processEvent($this->makeEvent([
            'provider' => 'stripe',
            'external_id' => 'shared-1',
        ]));
        $this->service->processEvent($this->makeEvent([
            'provider' => 'sinpe',
            'external_id' => 'shared-1',
        ]));

        $this->assertSame(2, BillingPaymentRecord::query()->count());
    }

    // ------------------------------------------------------------------
    // Aislamiento por producto/empresa
    // ------------------------------------------------------------------

    public function test_records_are_isolated_by_product_and_company(): void
    {
        $this->service->processEvent($this->makeEvent([
            'product' => 'cobros',
            'company_id' => '10',
            'external_id' => 'iso-1',
        ]));
        $this->service->processEvent($this->makeEvent([
            'product' => 'pos',
            'company_id' => '10',
            'external_id' => 'iso-2',
        ]));
        $this->service->processEvent($this->makeEvent([
            'product' => 'cobros',
            'company_id' => '20',
            'external_id' => 'iso-3',
        ]));

        $this->assertSame(3, BillingPaymentRecord::query()->count());
        $this->assertSame(1, BillingPaymentRecord::query()->where('product', 'cobros')->where('company_id', '10')->count());
        $this->assertSame(1, BillingPaymentRecord::query()->where('product', 'pos')->where('company_id', '10')->count());
        $this->assertSame(1, BillingPaymentRecord::query()->where('product', 'cobros')->where('company_id', '20')->count());
    }

    // ------------------------------------------------------------------
    // Entrega de pagos confirmados al módulo consumidor
    // ------------------------------------------------------------------

    public function test_confirmed_payment_is_delivered_to_matching_handler_only(): void
    {
        $cobrosHandler = new class implements PaymentConfirmedHandlerInterface
        {
            public array $received = [];

            public function supports(string $product): bool
            {
                return $product === 'cobros';
            }

            public function handle(PaymentEvent $event): void
            {
                $this->received[] = $event;
            }
        };

        $posHandler = new class implements PaymentConfirmedHandlerInterface
        {
            public array $received = [];

            public function supports(string $product): bool
            {
                return $product === 'pos';
            }

            public function handle(PaymentEvent $event): void
            {
                $this->received[] = $event;
            }
        };

        $dispatcher = new PaymentDispatcher($this->app);
        $dispatcher->register($cobrosHandler);
        $dispatcher->register($posHandler);

        $service = new PaymentService($this->registry, $dispatcher);

        $service->processEvent($this->makeEvent([
            'type' => PaymentEventTypes::CONFIRMED,
            'product' => 'cobros',
            'external_id' => 'disp-1',
        ]));

        $this->assertCount(1, $cobrosHandler->received);
        $this->assertCount(0, $posHandler->received);
        $this->assertSame('disp-1', $cobrosHandler->received[0]->externalId);
        $this->assertTrue($cobrosHandler->received[0]->isConfirmed());
    }

    public function test_pending_event_is_not_delivered_to_handlers(): void
    {
        $handler = new class implements PaymentConfirmedHandlerInterface
        {
            public array $received = [];

            public function supports(string $product): bool
            {
                return true;
            }

            public function handle(PaymentEvent $event): void
            {
                $this->received[] = $event;
            }
        };

        $dispatcher = new PaymentDispatcher($this->app);
        $dispatcher->register($handler);

        $service = new PaymentService($this->registry, $dispatcher);

        $service->processEvent($this->makeEvent([
            'type' => PaymentEventTypes::PENDING,
            'external_id' => 'pend-1',
        ]));

        $this->assertCount(0, $handler->received);
    }

    // ------------------------------------------------------------------
    // Estados y transiciones
    // ------------------------------------------------------------------

    public function test_failed_and_refunded_events_update_record_status(): void
    {
        $this->service->processEvent($this->makeEvent([
            'type' => PaymentEventTypes::PENDING,
            'external_id' => 'trans-1',
        ]));

        $this->service->processEvent($this->makeEvent([
            'type' => PaymentEventTypes::FAILED,
            'external_id' => 'trans-1',
        ]));

        $record = $this->service->findByExternalId('stripe', 'trans-1');
        $this->assertSame(BillingPaymentRecord::STATUS_FAILED, $record->status);
        $this->assertNull($record->confirmed_at);

        $this->service->processEvent($this->makeEvent([
            'type' => PaymentEventTypes::CONFIRMED,
            'external_id' => 'trans-2',
        ]));
        $this->service->processEvent($this->makeEvent([
            'type' => PaymentEventTypes::REFUNDED,
            'external_id' => 'trans-2',
        ]));

        $record2 = $this->service->findByExternalId('stripe', 'trans-2');
        $this->assertSame(BillingPaymentRecord::STATUS_REFUNDED, $record2->status);
        $this->assertNotNull($record2->confirmed_at);
    }
}
