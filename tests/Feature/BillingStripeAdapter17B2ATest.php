<?php

namespace Tests\Feature;

use App\Services\Billing\Adapters\StripePaymentProvider;
use App\Services\Billing\DTOs\PaymentRequest;
use App\Services\Billing\DTOs\PaymentEvent;
use App\Services\Billing\Exceptions\PaymentProviderUnavailableException;
use Mockery;
use Stripe\Exception\AuthenticationException;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * BLOQUE 17B-2A — pruebas focales del adaptador Stripe real.
 *
 * Usan mocks de Stripe\StripeClient; ninguna llamada real a la API.
 */
class BillingStripeAdapter17B2ATest extends TestCase
{
    private StripePaymentProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new StripePaymentProvider();

        $this->app['config']->set('billing.stripe.mode', 'test');
        $this->app['config']->set('billing.stripe.secret_key_test', 'sk_test_mockkey123456789');
        $this->app['config']->set('billing.stripe.secret_key_live', 'sk_live_mockkey123456789');
    }

    private function makeRequest(array $overrides = []): PaymentRequest
    {
        return new PaymentRequest(
            product: $overrides['product'] ?? 'cobros',
            companyId: $overrides['company_id'] ?? '1',
            contractReference: $overrides['contract_reference'] ?? 'CTR-001',
            currency: $overrides['currency'] ?? 'CRC',
            amount: $overrides['amount'] ?? '10000.00',
            periodYear: $overrides['period_year'] ?? 2026,
            periodMonth: $overrides['period_month'] ?? 10,
            metadata: $overrides['metadata'] ?? [],
        );
    }

    // ------------------------------------------------------------------
    // Configuración y disponibilidad
    // ------------------------------------------------------------------

    public function test_provider_key_is_stripe(): void
    {
        $this->assertSame('stripe', $this->provider->key());
    }

    public function test_is_available_true_when_configured(): void
    {
        $this->assertTrue($this->provider->isAvailable());
    }

    public function test_is_available_false_without_mode(): void
    {
        $this->app['config']->set('billing.stripe.mode', null);
        $this->app['config']->set('billing.stripe.secret_key_test', null);

        $provider = new StripePaymentProvider();
        $this->assertFalse($provider->isAvailable());
    }

    public function test_is_available_false_without_secret_key(): void
    {
        $this->app['config']->set('billing.stripe.secret_key_test', null);

        $provider = new StripePaymentProvider();
        $this->assertFalse($provider->isAvailable());
    }

    public function test_is_available_false_with_invalid_mode(): void
    {
        $this->app['config']->set('billing.stripe.mode', 'invalid');

        $provider = new StripePaymentProvider();
        $this->assertFalse($provider->isAvailable());
    }

    public function test_live_mode_uses_live_keys(): void
    {
        $this->app['config']->set('billing.stripe.mode', 'live');
        $this->app['config']->set('billing.stripe.secret_key_live', 'sk_live_mockkey');

        $provider = new StripePaymentProvider();
        $this->assertTrue($provider->isAvailable());
    }

    public function test_live_mode_rejects_test_key(): void
    {
        $this->app['config']->set('billing.stripe.mode', 'live');
        $this->app['config']->set('billing.stripe.secret_key_live', null);

        $provider = new StripePaymentProvider();
        $this->assertFalse($provider->isAvailable());
    }

    // ------------------------------------------------------------------
    // Helpers para mocks
    // ------------------------------------------------------------------

    private function buildMockIntent(string $id, string $status, int $amount, string $currency, array $metadata): object
    {
        return new class($id, $status, $amount, $currency, $metadata) {
            public string $id;
            public string $status;
            public int $amount;
            public string $currency;
            public array $metadata;
            public function __construct(string $id, string $status, int $amount, string $currency, array $metadata) {
                $this->id = $id;
                $this->status = $status;
                $this->amount = $amount;
                $this->currency = $currency;
                $this->metadata = $metadata;
            }
            public function jsonSerialize(): array { return ['id' => $this->id]; }
        };
    }

    private function buildMockRefund(string $id, int $amount, string $currency): object
    {
        return new class($id, $amount, $currency) {
            public string $id;
            public int $amount;
            public string $currency;
            public function __construct(string $id, int $amount, string $currency) {
                $this->id = $id;
                $this->amount = $amount;
                $this->currency = $currency;
            }
            public function jsonSerialize(): array { return ['id' => $this->id]; }
        };
    }

    private function createMockStripeClient(): Mockery\MockInterface
    {
        $mock = Mockery::mock(StripeClient::class);
        $mock->shouldIgnoreMissing();
        return $mock;
    }

    private function setupPaymentIntentsMock(Mockery\MockInterface $mockClient, Mockery\MockInterface $paymentIntents): void
    {
        $mockClient->shouldReceive('getService')
            ->with('paymentIntents')
            ->andReturn($paymentIntents);
        $mockClient->shouldReceive('paymentIntents')->andReturn($paymentIntents);
    }

    private function setupRefundsMock(Mockery\MockInterface $mockClient, Mockery\MockInterface $refunds): void
    {
        $mockClient->shouldReceive('getService')
            ->with('refunds')
            ->andReturn($refunds);
        $mockClient->shouldReceive('refunds')->andReturn($refunds);
    }

    private function setPrivateClient(StripeClient $client): void
    {
        $reflection = new \ReflectionClass($this->provider);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($this->provider, $client);

        $modeProperty = $reflection->getProperty('mode');
        $modeProperty->setAccessible(true);
        $modeProperty->setValue($this->provider, 'test');
    }

    // ------------------------------------------------------------------
    // Creación de pagos (PaymentIntent)
    // ------------------------------------------------------------------

    public function test_create_payment_returns_pending_event_with_external_id(): void
    {
        $mockClient = $this->createMockStripeClient();
        $mockPaymentIntents = Mockery::mock('overload:Stripe\PaymentIntent');

        $mockIntent = $this->buildMockIntent(
            'pi_test_123',
            'requires_payment_method',
            1000000,
            'crc',
            [
                'product' => 'cobros',
                'company_id' => '1',
                'contract_reference' => 'CTR-001',
                'period_year' => '2026',
                'period_month' => '10',
            ]
        );

        $mockPaymentIntents->shouldReceive('create')
            ->once()
            ->andReturn($mockIntent);

        $this->setupPaymentIntentsMock($mockClient, $mockPaymentIntents);
        $this->setPrivateClient($mockClient);

        $request = $this->makeRequest();
        $event = $this->provider->createPayment($request);

        $this->assertSame('pending', $event->type);
        $this->assertSame('stripe', $event->provider);
        $this->assertSame('pi_test_123', $event->externalId);
        $this->assertSame('cobros', $event->product);
        $this->assertSame('1', $event->companyId);
        $this->assertSame('CTR-001', $event->contractReference);
        $this->assertSame('CRC', $event->currency);
        $this->assertSame('10000.00', $event->amount);
    }

    public function test_create_payment_converts_amount_to_minor_units_crc(): void
    {
        $mockClient = $this->createMockStripeClient();
        $mockPaymentIntents = Mockery::mock('overload:Stripe\PaymentIntent');

        $mockIntent = $this->buildMockIntent(
            'pi_test_123',
            'requires_payment_method',
            2500050,
            'crc',
            ['product' => 'cobros', 'company_id' => '1', 'contract_reference' => 'CTR-001', 'period_year' => '2026', 'period_month' => '10']
        );

        $mockPaymentIntents->shouldReceive('create')
            ->once()
            ->withArgs(function ($params) {
                $this->assertSame(2500050, $params['amount']);
                return true;
            })
            ->andReturn($mockIntent);

        $this->setupPaymentIntentsMock($mockClient, $mockPaymentIntents);
        $this->setPrivateClient($mockClient);

        $request = $this->makeRequest(['amount' => '25000.50']);
        $event = $this->provider->createPayment($request);

        $this->assertSame('25000.50', $event->amount);
    }

    public function test_create_payment_handles_zero_decimal_currency_jpy(): void
    {
        $mockClient = $this->createMockStripeClient();
        $mockPaymentIntents = Mockery::mock('overload:Stripe\PaymentIntent');

        $mockIntent = $this->buildMockIntent(
            'pi_test_jpy',
            'requires_payment_method',
            1000,
            'jpy',
            ['product' => 'cobros', 'company_id' => '1', 'contract_reference' => 'CTR-001', 'period_year' => '2026', 'period_month' => '10']
        );

        $mockPaymentIntents->shouldReceive('create')
            ->once()
            ->withArgs(function ($params) {
                $this->assertSame(1000, $params['amount']);
                $this->assertSame('jpy', $params['currency']);
                return true;
            })
            ->andReturn($mockIntent);

        $this->setupPaymentIntentsMock($mockClient, $mockPaymentIntents);
        $this->setPrivateClient($mockClient);

        $request = $this->makeRequest(['currency' => 'JPY', 'amount' => '1000']);
        $event = $this->provider->createPayment($request);

        $this->assertSame('JPY', $event->currency);
        $this->assertSame('1000', $event->amount);
    }

    public function test_create_payment_idempotency_key_is_deterministic(): void
    {
        $mockClient = $this->createMockStripeClient();
        $mockPaymentIntents = Mockery::mock('overload:Stripe\PaymentIntent');

        $mockIntent = $this->buildMockIntent(
            'pi_test_123',
            'requires_payment_method',
            1000000,
            'crc',
            ['product' => 'cobros', 'company_id' => '1', 'contract_reference' => 'CTR-001', 'period_year' => '2026', 'period_month' => '10']
        );

        $capturedKeys = [];
        $mockPaymentIntents->shouldReceive('create')
            ->twice()
            ->withArgs(function ($params, $options) use (&$capturedKeys) {
                $capturedKeys[] = $options['idempotency_key'];
                return true;
            })
            ->andReturn($mockIntent);

        $this->setupPaymentIntentsMock($mockClient, $mockPaymentIntents);
        $this->setPrivateClient($mockClient);

        $request = $this->makeRequest();
        $this->provider->createPayment($request);
        $this->provider->createPayment($request);

        $this->assertSame($capturedKeys[0], $capturedKeys[1]);
        $this->assertStringStartsWith('mvs-billing-', $capturedKeys[0]);
    }

    public function test_create_payment_different_requests_produce_different_idempotency_keys(): void
    {
        $mockClient = $this->createMockStripeClient();
        $mockPaymentIntents = Mockery::mock('overload:Stripe\PaymentIntent');

        $mockIntent = $this->buildMockIntent(
            'pi_test_123',
            'requires_payment_method',
            1000000,
            'crc',
            ['product' => 'cobros', 'company_id' => '1', 'contract_reference' => 'CTR-001', 'period_year' => '2026', 'period_month' => '10']
        );

        $capturedKeys = [];
        $mockPaymentIntents->shouldReceive('create')
            ->twice()
            ->withArgs(function ($params, $options) use (&$capturedKeys) {
                $capturedKeys[] = $options['idempotency_key'];
                return true;
            })
            ->andReturn($mockIntent);

        $this->setupPaymentIntentsMock($mockClient, $mockPaymentIntents);
        $this->setPrivateClient($mockClient);

        $this->provider->createPayment($this->makeRequest(['amount' => '10000.00']));
        $this->provider->createPayment($this->makeRequest(['amount' => '20000.00']));

        $this->assertNotSame($capturedKeys[0], $capturedKeys[1]);
    }

    // ------------------------------------------------------------------
    // Confirmación de pagos
    // ------------------------------------------------------------------

    public function test_confirm_payment_returns_confirmed_event_when_succeeded(): void
    {
        $mockClient = $this->createMockStripeClient();
        $mockPaymentIntents = Mockery::mock('overload:Stripe\PaymentIntent');

        $mockIntent = $this->buildMockIntent(
            'pi_test_123',
            'succeeded',
            1000000,
            'crc',
            ['product' => 'cobros', 'company_id' => '1', 'contract_reference' => 'CTR-001', 'period_year' => '2026', 'period_month' => '10']
        );

        $mockPaymentIntents->shouldReceive('retrieve')
            ->once()
            ->with('pi_test_123')
            ->andReturn($mockIntent);

        $this->setupPaymentIntentsMock($mockClient, $mockPaymentIntents);
        $this->setPrivateClient($mockClient);

        $event = $this->provider->confirmPayment('pi_test_123');

        $this->assertSame('confirmed', $event->type);
        $this->assertSame('pi_test_123', $event->externalId);
    }

    public function test_confirm_payment_returns_pending_for_requires_payment_method(): void
    {
        $mockClient = $this->createMockStripeClient();
        $mockPaymentIntents = Mockery::mock('overload:Stripe\PaymentIntent');

        $mockIntent = $this->buildMockIntent(
            'pi_test_123',
            'requires_payment_method',
            1000000,
            'crc',
            ['product' => 'cobros', 'company_id' => '1', 'contract_reference' => 'CTR-001', 'period_year' => '2026', 'period_month' => '10']
        );

        $mockPaymentIntents->shouldReceive('retrieve')
            ->once()
            ->with('pi_test_123')
            ->andReturn($mockIntent);

        $this->setupPaymentIntentsMock($mockClient, $mockPaymentIntents);
        $this->setPrivateClient($mockClient);

        $event = $this->provider->confirmPayment('pi_test_123');

        $this->assertSame('pending', $event->type);
    }

    public function test_confirm_payment_returns_failed_for_canceled(): void
    {
        $mockClient = $this->createMockStripeClient();
        $mockPaymentIntents = Mockery::mock('overload:Stripe\PaymentIntent');

        $mockIntent = $this->buildMockIntent(
            'pi_test_123',
            'canceled',
            1000000,
            'crc',
            ['product' => 'cobros', 'company_id' => '1', 'contract_reference' => 'CTR-001', 'period_year' => '2026', 'period_month' => '10']
        );

        $mockPaymentIntents->shouldReceive('retrieve')
            ->once()
            ->with('pi_test_123')
            ->andReturn($mockIntent);

        $this->setupPaymentIntentsMock($mockClient, $mockPaymentIntents);
        $this->setPrivateClient($mockClient);

        $event = $this->provider->confirmPayment('pi_test_123');

        $this->assertSame('failed', $event->type);
    }

    // ------------------------------------------------------------------
    // Reembolsos
    // ------------------------------------------------------------------

    public function test_refund_payment_returns_refunded_event(): void
    {
        $mockClient = $this->createMockStripeClient();
        $mockPaymentIntents = Mockery::mock('overload:Stripe\PaymentIntent');
        $mockRefunds = Mockery::mock('overload:Stripe\Refund');

        $mockIntent = $this->buildMockIntent(
            'pi_test_123',
            'succeeded',
            1000000,
            'crc',
            ['product' => 'cobros', 'company_id' => '1', 'contract_reference' => 'CTR-001', 'period_year' => '2026', 'period_month' => '10']
        );

        $mockRefund = $this->buildMockRefund('re_test_123', 1000000, 'crc');

        $mockPaymentIntents->shouldReceive('retrieve')
            ->once()
            ->with('pi_test_123')
            ->andReturn($mockIntent);

        $mockRefunds->shouldReceive('create')
            ->once()
            ->withArgs(function ($params) {
                $this->assertSame('pi_test_123', $params['payment_intent']);
                return true;
            })
            ->andReturn($mockRefund);

        $this->setupPaymentIntentsMock($mockClient, $mockPaymentIntents);
        $this->setupRefundsMock($mockClient, $mockRefunds);
        $this->setPrivateClient($mockClient);

        $event = $this->provider->refundPayment('pi_test_123');

        $this->assertSame('refunded', $event->type);
        $this->assertSame('re_test_123', $event->externalId);
        $this->assertSame('10000.00', $event->amount);
    }

    public function test_refund_payment_throws_if_intent_not_succeeded(): void
    {
        $mockClient = $this->createMockStripeClient();
        $mockPaymentIntents = Mockery::mock('overload:Stripe\PaymentIntent');

        $mockIntent = $this->buildMockIntent(
            'pi_test_123',
            'requires_payment_method',
            1000000,
            'crc',
            ['product' => 'cobros', 'company_id' => '1', 'contract_reference' => 'CTR-001']
        );

        $mockPaymentIntents->shouldReceive('retrieve')
            ->once()
            ->with('pi_test_123')
            ->andReturn($mockIntent);

        $this->setupPaymentIntentsMock($mockClient, $mockPaymentIntents);
        $this->setPrivateClient($mockClient);

        $this->expectException(PaymentProviderUnavailableException::class);
        $this->expectExceptionMessage('no está confirmado');

        $this->provider->refundPayment('pi_test_123');
    }

    // ------------------------------------------------------------------
    // Aislamiento por empresa/producto
    // ------------------------------------------------------------------

    public function test_events_carry_company_and_product_isolation(): void
    {
        $mockClient = $this->createMockStripeClient();
        $mockPaymentIntents = Mockery::mock('overload:Stripe\PaymentIntent');

        $mockIntent = $this->buildMockIntent(
            'pi_test_123',
            'requires_payment_method',
            1000000,
            'crc',
            ['product' => 'cobros', 'company_id' => '99', 'contract_reference' => 'CTR-999', 'period_year' => '2026', 'period_month' => '10']
        );

        $mockPaymentIntents->shouldReceive('create')
            ->once()
            ->andReturn($mockIntent);

        $this->setupPaymentIntentsMock($mockClient, $mockPaymentIntents);
        $this->setPrivateClient($mockClient);

        $request = $this->makeRequest(['company_id' => '99', 'contract_reference' => 'CTR-999']);
        $event = $this->provider->createPayment($request);

        $this->assertSame('99', $event->companyId);
        $this->assertSame('CTR-999', $event->contractReference);
    }

    // ------------------------------------------------------------------
    // Errores
    // ------------------------------------------------------------------

    public function test_create_payment_throws_on_stripe_api_error(): void
    {
        $mockClient = $this->createMockStripeClient();
        $mockPaymentIntents = Mockery::mock('overload:Stripe\PaymentIntent');

        $mockPaymentIntents->shouldReceive('create')
            ->once()
            ->andThrow(AuthenticationException::factory('Invalid API Key', 401));

        $this->setupPaymentIntentsMock($mockClient, $mockPaymentIntents);
        $this->setPrivateClient($mockClient);

        $this->expectException(PaymentProviderUnavailableException::class);
        $this->expectExceptionMessage('Error creando PaymentIntent');

        $this->provider->createPayment($this->makeRequest());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}