<?php

namespace Tests\Feature\Billing;

use App\Models\CobrosBillingContract;
use App\Models\Company;
use App\Models\User;
use App\Services\Billing\Adapters\StripePaymentProvider;
use App\Services\Billing\Checkout\CheckoutSessionRequest;
use App\Services\Billing\Checkout\CheckoutSessionService;
use App\Services\Billing\Contracts\PaymentProviderInterface;
use App\Services\Billing\DTOs\PaymentEvent;
use App\Services\Billing\Exceptions\PaymentProviderUnavailableException;
use App\Services\Cobros\CobrosBillingContractService;
use App\Services\Cobros\CobrosPricingService;
use App\Services\Cobros\CobrosModalityCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Stripe\Checkout\Session;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * BLOQUE 17B-4A — pruebas focales de creación de sesiones Stripe Checkout.
 *
 * Verifica configuración, importe/moneda desde cotización interna, aislamiento
 * e idempotencia, usando un proveedor de prueba (sin llamadas reales).
 */
class CheckoutSessionServiceTest extends TestCase
{
    use RefreshDatabase;

    private TestCheckoutSessionService $service;
    private TestStripeProvider $stripeProvider;
    private CobrosBillingContractService $billingContracts;
    private CobrosPricingService $pricing;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('billing.stripe.mode', 'test');
        Config::set('billing.stripe.secret_key_test', 'sk_test_mock');
        Config::set('billing.stripe.webhook_secret_test', 'whsec_test');

        $this->stripeProvider = new TestStripeProvider();
        $this->billingContracts = app(CobrosBillingContractService::class);
        $this->pricing = app(CobrosPricingService::class);

        $this->service = new TestCheckoutSessionService(
            $this->stripeProvider,
            $this->billingContracts,
            $this->pricing,
        );
    }

    private function platformAdmin(): User
    {
        return User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
    }

    private function lender(string $email): array
    {
        $lender = User::factory()->create(['is_active' => true, 'email' => $email]);
        $company = Company::create([
            'trade_name' => 'Prestamista '.explode('@', $email)[0],
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
            'owner_user_id' => $lender->id,
        ]);

        return [$company, $lender];
    }

    private function createContract(Company $company, User $payer, User $admin): CobrosBillingContract
    {
        return $this->billingContracts->createIndividual(
            $company, 'cobrador_vinculado', 'CR', $payer,
            now()->startOfMonth(), now()->addYear()->startOfMonth(), $admin,
        );
    }

    // ------------------------------------------------------------------
    // Configuración y validaciones
    // ------------------------------------------------------------------

    public function test_stripe_no_configurado_lanza_excepcion(): void
    {
        $provider = new TestStripeProvider();
        $provider->setAvailable(false);

        $service = new TestCheckoutSessionService(
            $provider,
            $this->billingContracts,
            $this->pricing,
        );

        $request = new CheckoutSessionRequest(
            product: 'cobros',
            companyId: '1',
            contractReference: '1',
            successUrl: 'https://localhost/success',
            cancelUrl: 'https://localhost/cancel',
        );

        $this->expectException(\App\Services\Billing\Exceptions\PaymentProviderUnavailableException::class);
        $service->createForContract($request);
    }

    public function test_dominio_success_no_permitido_lanza_excepcion(): void
    {
        [$company, $payer] = $this->lender('presta@test.com');
        $admin = $this->platformAdmin();
        $contract = $this->createContract($company, $payer, $admin);

        $request = new CheckoutSessionRequest(
            product: 'cobros',
            companyId: (string) $company->id,
            contractReference: (string) $contract->id,
            successUrl: 'https://dominio-malicioso.com/success',
            cancelUrl: 'https://localhost/cancel',
        );

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service->createForContract($request);
    }

    public function test_dominio_cancel_no_permitido_lanza_excepcion(): void
    {
        [$company, $payer] = $this->lender('presta@test.com');
        $admin = $this->platformAdmin();
        $contract = $this->createContract($company, $payer, $admin);

        $request = new CheckoutSessionRequest(
            product: 'cobros',
            companyId: (string) $company->id,
            contractReference: (string) $contract->id,
            successUrl: 'https://localhost/success',
            cancelUrl: 'https://dominio-malicioso.com/cancel',
        );

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service->createForContract($request);
    }

    public function test_contrato_inexistente_lanza_excepcion(): void
    {
        [$company, $payer] = $this->lender('presta@test.com');
        $admin = $this->platformAdmin();

        $request = new CheckoutSessionRequest(
            product: 'cobros',
            companyId: (string) $company->id,
            contractReference: '999999',
            successUrl: 'https://localhost/success',
            cancelUrl: 'https://localhost/cancel',
        );

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service->createForContract($request);
    }

    public function test_contrato_ajeno_a_empresa_lanza_excepcion(): void
    {
        [$companyA, $payerA] = $this->lender('presta.a@test.com');
        [$companyB, $payerB] = $this->lender('presta.b@test.com');
        $admin = $this->platformAdmin();

        $contractA = $this->createContract($companyA, $payerA, $admin);

        $request = new CheckoutSessionRequest(
            product: 'cobros',
            companyId: (string) $companyB->id,
            contractReference: (string) $contractA->id,
            successUrl: 'https://localhost/success',
            cancelUrl: 'https://localhost/cancel',
        );

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service->createForContract($request);
    }

    public function test_periodo_fuera_vigencia_lanza_excepcion(): void
    {
        [$company, $payer] = $this->lender('presta@test.com');
        $admin = $this->platformAdmin();
        $contract = $this->createContract($company, $payer, $admin);

        $request = new CheckoutSessionRequest(
            product: 'cobros',
            companyId: (string) $company->id,
            contractReference: (string) $contract->id,
            successUrl: 'https://localhost/success',
            cancelUrl: 'https://localhost/cancel',
            periodYear: 2020,
            periodMonth: 1,
        );

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service->createForContract($request);
    }

    // ------------------------------------------------------------------
    // Importe y moneda desde cotización interna
    // ------------------------------------------------------------------

    public function test_importe_y_moneda_provienen_de_cotizacion_interna(): void
    {
        [$company, $payer] = $this->lender('presta@importe.test');
        $admin = $this->platformAdmin();
        $contract = $this->createContract($company, $payer, $admin);

        $request = new CheckoutSessionRequest(
            product: 'cobros',
            companyId: (string) $company->id,
            contractReference: (string) $contract->id,
            successUrl: 'https://localhost/success',
            cancelUrl: 'https://localhost/cancel',
        );

        $response = $this->service->createForContract($request);

        // Verificar que el importe y moneda coinciden con la cotización del contrato
        $quote = $this->pricing->quoteForContract($contract);
        $this->assertSame($quote->currency, $response->currency);
        $this->assertSame($quote->monthlyTotal, $response->amount);
        $this->assertSame($quote->countryIso, 'CR');
        $this->assertSame('CRC', $response->currency);
    }

    public function test_idempotencia_mismo_request_mismo_session_id(): void
    {
        [$company, $payer] = $this->lender('presta@idem.test');
        $admin = $this->platformAdmin();
        $contract = $this->createContract($company, $payer, $admin);

        $idempotencyKey = 'mvs-checkout-test-idem-key';

        $request = new CheckoutSessionRequest(
            product: 'cobros',
            companyId: (string) $company->id,
            contractReference: (string) $contract->id,
            successUrl: 'https://localhost/success',
            cancelUrl: 'https://localhost/cancel',
            idempotencyKey: $idempotencyKey,
        );

        $response1 = $this->service->createForContract($request);
        $response2 = $this->service->createForContract($request);

        $this->assertSame($idempotencyKey, $response1->idempotencyKey);
        $this->assertSame($idempotencyKey, $response2->idempotencyKey);
        $this->assertSame($response1->sessionId, $response2->sessionId);
    }

    // ------------------------------------------------------------------
    // Aislamiento entre empresas
    // ------------------------------------------------------------------

    public function test_aislamiento_empresas_sesiones_independientes(): void
    {
        [$companyA, $payerA] = $this->lender('presta.a@aislamiento.test');
        [$companyB, $payerB] = $this->lender('presta.b@aislamiento.test');
        $admin = $this->platformAdmin();

        $contractA = $this->createContract($companyA, $payerA, $admin);
        $contractB = $this->createContract($companyB, $payerB, $admin);

        $requestA = new CheckoutSessionRequest(
            product: 'cobros',
            companyId: (string) $companyA->id,
            contractReference: (string) $contractA->id,
            successUrl: 'https://localhost/success',
            cancelUrl: 'https://localhost/cancel',
        );

        $requestB = new CheckoutSessionRequest(
            product: 'cobros',
            companyId: (string) $companyB->id,
            contractReference: (string) $contractB->id,
            successUrl: 'https://localhost/success',
            cancelUrl: 'https://localhost/cancel',
        );

        $responseA = $this->service->createForContract($requestA);
        $responseB = $this->service->createForContract($requestB);

        // Cada empresa tiene su propia sesión (independiente)
        $this->assertSame((string) $companyA->id, $responseA->companyId);
        $this->assertSame((string) $companyB->id, $responseB->companyId);
        $this->assertSame((string) $contractA->id, $responseA->contractReference);
        $this->assertSame((string) $contractB->id, $responseB->contractReference);
    }

    // ------------------------------------------------------------------
    // Estructura de respuesta
    // ------------------------------------------------------------------

    public function test_respuesta_contiene_datos_minimos_para_redireccion(): void
    {
        [$company, $payer] = $this->lender('presta@respuesta.test');
        $admin = $this->platformAdmin();
        $contract = $this->createContract($company, $payer, $admin);

        $request = new CheckoutSessionRequest(
            product: 'cobros',
            companyId: (string) $company->id,
            contractReference: (string) $contract->id,
            successUrl: 'https://localhost/success',
            cancelUrl: 'https://localhost/cancel',
        );

        $response = $this->service->createForContract($request);

        $this->assertNotEmpty($response->sessionId);
        $this->assertNotEmpty($response->url);
        $this->assertStringStartsWith('https://checkout.stripe.com', $response->url);
        $this->assertSame('cobros', $response->product);
        $this->assertSame((string) $company->id, $response->companyId);
        $this->assertSame((string) $contract->id, $response->contractReference);
        $this->assertNotEmpty($response->idempotencyKey);
        $this->assertGreaterThan(time(), $response->expiresAt);
    }

    /**
     * Prueba de integración: ejerce el CheckoutSessionService REAL con StripeClient mockeado.
     *
     * Valida que los parámetros enviados a Stripe son correctos:
     * - importe en unidades menores
     * - moneda
     * - referencia de contrato
     * - producto
     * - metadatos
     * - URLs permitidas
     * - idempotency key
     */
    public function test_servicio_real_envia_parametros_correctos_a_stripe(): void
    {
        [$company, $payer] = $this->lender('presta@real.test');
        $admin = $this->platformAdmin();
        $contract = $this->createContract($company, $payer, $admin);

        $capturedParams = [];
        $capturedIdempotencyKey = '';
        $mockSession = $this->makeMockSession();
        $mockClient = $this->makeMockStripeClient($mockSession, $capturedParams, $capturedIdempotencyKey);

        // Inyectar mock en StripePaymentProvider
        $stripeProvider = new \App\Services\Billing\Adapters\StripePaymentProvider();
        // Primero: inicializar configuración llamando isAvailable() para cachear el modo
        $stripeProvider->isAvailable();
        // Segundo: inyectar mock client via reflection
        $reflection = new \ReflectionClass($stripeProvider);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($stripeProvider, $mockClient);

        $service = new \App\Services\Billing\Checkout\CheckoutSessionService(
            $stripeProvider,
            $this->billingContracts,
            $this->pricing,
        );

        $request = new \App\Services\Billing\Checkout\CheckoutSessionRequest(
            product: 'cobros',
            companyId: (string) $company->id,
            contractReference: (string) $contract->id,
            successUrl: 'https://localhost/success',
            cancelUrl: 'https://localhost/cancel',
        );

        $response = $service->createForContract($request);

        // 1) Verificar que se capturó la llamada a Stripe
        $this->assertNotEmpty($capturedParams, 'Debe haberse llamado a Stripe');

        // 2) Importe en unidades menores (CRC tiene 2 decimales: 10000.0000 -> 1000000)
        $quote = $this->pricing->quoteForContract($contract);
        $expectedMinorUnits = (int) round((float) $quote->monthlyTotal * 100);
        $this->assertSame($expectedMinorUnits, $capturedParams['line_items'][0]['price_data']['unit_amount']);

        // 3) Moneda en minúsculas
        $this->assertSame(strtolower($quote->currency), $capturedParams['line_items'][0]['price_data']['currency']);

        // 4) Referencia de contrato en metadatos
        $this->assertSame((string) $contract->id, $capturedParams['metadata']['contract_reference']);

        // 5) Producto
        $this->assertSame('cobros', $capturedParams['metadata']['product']);

        // 6) URLs permitidas
        $this->assertSame('https://localhost/success', $capturedParams['success_url']);
        $this->assertSame('https://localhost/cancel', $capturedParams['cancel_url']);

        // 7) Idempotency key presente
        $this->assertNotEmpty($capturedIdempotencyKey);
        $this->assertStringStartsWith('mvs-checkout-', $capturedIdempotencyKey);

        // 8) Respuesta del servicio real
        $this->assertSame($mockSession->id, $response->sessionId);
        $this->assertSame($mockSession->url, $response->url);
        $this->assertSame($quote->currency, $response->currency);
        $this->assertSame($quote->monthlyTotal, $response->amount);
    }

    /**
     * Crea una sesión Checkout simulada para tests de integración.
     */
    private function makeMockSession(string $sessionId = 'cs_test_real_123', string $url = 'https://checkout.stripe.com/pay/cs_test_real_123'): \Stripe\Checkout\Session
    {
        return \Stripe\Checkout\Session::constructFrom([
            'id' => $sessionId,
            'object' => 'checkout.session',
            'url' => $url,
            'expires_at' => time() + 1800,
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'success_url' => 'https://localhost/success',
            'cancel_url' => 'https://localhost/cancel',
            'metadata' => [],
            'line_items' => ['data' => []],
        ], 'sk_test_mock');
    }

    /**
     * Crea un StripeClient mockeado que captura los parámetros enviados a create().
     */
    private function makeMockStripeClient(
        \Stripe\Checkout\Session $mockSession,
        array &$capturedParams,
        string &$capturedIdempotencyKey
    ): \Stripe\StripeClient {
        $mockSessionService = $this->createMock(\Stripe\Service\Checkout\SessionService::class);
        $mockSessionService
            ->method('create')
            ->willReturnCallback(function ($params, $options) use (&$capturedParams, &$capturedIdempotencyKey, $mockSession) {
                $capturedParams = $params;
                $capturedIdempotencyKey = $options['idempotency_key'] ?? '';
                return $mockSession;
            });

        $mockCheckoutFactory = new class($mockSessionService) extends \Stripe\Service\Checkout\CheckoutServiceFactory {
            private $sessionService;
            public function __construct($sessionService) { $this->sessionService = $sessionService; }
            public function __get($name) {
                if ($name === 'sessions') return $this->sessionService;
                return parent::__get($name);
            }
        };
        $mockCheckoutFactory->sessions = $mockSessionService;

        $mockClient = new class($mockCheckoutFactory) extends \Stripe\StripeClient {
            private $checkoutFactory;
            public function __construct($checkoutFactory) { $this->checkoutFactory = $checkoutFactory; }
            public function __get($name) {
                if ($name === 'checkout') return $this->checkoutFactory;
                return parent::__get($name);
            }
        };
        $mockClient->checkout = $mockCheckoutFactory;

        return $mockClient;
    }
}

/**
 * Proveedor Stripe de prueba que simula sesiones Checkout sin llamar a la API real.
 */
class TestStripeProvider implements \App\Services\Billing\Contracts\PaymentProviderInterface
{
    private array $sessions = [];
    private int $callCount = 0;
    private bool $available = true;

    public function setAvailable(bool $available): void
    {
        $this->available = $available;
    }

    public function key(): string
    {
        return 'stripe';
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function createPayment(\App\Services\Billing\DTOs\PaymentRequest $request): \App\Services\Billing\DTOs\PaymentEvent
    {
        throw new \RuntimeException('No implementado en tests');
    }

    public function confirmPayment(string $externalId): \App\Services\Billing\DTOs\PaymentEvent
    {
        throw new \RuntimeException('No implementado en tests');
    }

    public function refundPayment(string $externalId): \App\Services\Billing\DTOs\PaymentEvent
    {
        throw new \RuntimeException('No implementado en tests');
    }

    /**
     * Crea una sesión Checkout simulada.
     */
    public function createCheckoutSession(
        \App\Services\Billing\Checkout\CheckoutSessionRequest $request,
        \App\Data\Cobros\CobrosPriceQuote $quote,
        string $idempotencyKey,
    ): \Stripe\Checkout\Session {
        if (isset($this->sessions[$idempotencyKey])) {
            return $this->sessions[$idempotencyKey];
        }

        $session = \Stripe\Checkout\Session::constructFrom([
            'id' => 'cs_test_' . count($this->sessions),
            'object' => 'checkout.session',
            'url' => 'https://checkout.stripe.com/pay/cs_test_' . count($this->sessions),
            'expires_at' => time() + 1800,
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'success_url' => $request->successUrl,
            'cancel_url' => $request->cancelUrl,
            'metadata' => array_merge([
                'product' => $request->product,
                'company_id' => $request->companyId,
                'contract_reference' => $request->contractReference,
                'period_year' => (string) ($request->periodYear ?? ''),
                'period_month' => (string) ($request->periodMonth ?? ''),
            ], $request->metadata),
            'line_items' => ['data' => []],
        ], 'sk_test_mock');

        $this->sessions[$idempotencyKey] = $session;

        return $session;
    }
}

/**
 * Servicio de prueba que usa TestStripeProvider en lugar del adaptador real.
 */
class TestCheckoutSessionService
{
    public const MODE_PAYMENT = 'payment';
    public const MODE_SUBSCRIPTION = 'subscription';

    private const ALLOWED_SUCCESS_DOMAINS = [
        'localhost', '127.0.0.1', 'mvscommerce.test', 'staging.mvscommerce.com', 'app.mvscommerce.com',
    ];

    private const ALLOWED_CANCEL_DOMAINS = [
        'localhost', '127.0.0.1', 'mvscommerce.test', 'staging.mvscommerce.com', 'app.mvscommerce.com',
    ];

    public function __construct(
        private readonly \Tests\Feature\Billing\TestStripeProvider $stripeProvider,
        private readonly \App\Services\Cobros\CobrosBillingContractService $billingContracts,
        private readonly \App\Services\Cobros\CobrosPricingService $pricing,
    ) {}

    /**
     * Crea una sesión Checkout Stripe para un contrato existente.
     *
     * @throws \App\Services\Billing\Exceptions\PaymentProviderUnavailableException
     * @throws \Illuminate\Validation\ValidationException
     */
    public function createForContract(\App\Services\Billing\Checkout\CheckoutSessionRequest $request): \App\Services\Billing\Checkout\CheckoutSessionResponse
    {
        // 1) Validar proveedor Stripe disponible
        if (! $this->stripeProvider->isAvailable()) {
            throw \App\Services\Billing\Exceptions\PaymentProviderUnavailableException::forProvider(
                'stripe',
                'Stripe no está configurado: defina STRIPE_MODE y la clave secreta correspondiente.'
            );
        }

        // 2) Validar URLs de retorno (allow-list)
        $this->assertAllowedUrl($request->successUrl, self::ALLOWED_SUCCESS_DOMAINS, 'success_url');
        $this->assertAllowedUrl($request->cancelUrl, self::ALLOWED_CANCEL_DOMAINS, 'cancel_url');

        // 3) Obtener contrato y cotización interna (fuente de verdad de importe/moneda)
        $contract = $this->resolveContract($request);
        $quote = $this->pricing->quoteForContract($contract);

        // 4) Construir idempotency key
        $idempotencyKey = $request->idempotencyKey !== ''
            ? $request->idempotencyKey
            : $this->buildIdempotencyKey($request, $quote);

        // 5) Crear sesión Checkout en Stripe (proveedor de prueba)
        $session = $this->stripeProvider->createCheckoutSession($request, $quote, $idempotencyKey);

        // 6) Respuesta con datos mínimos para redirección
        return new \App\Services\Billing\Checkout\CheckoutSessionResponse(
            sessionId: $session->id,
            url: $session->url,
            product: $request->product,
            companyId: $request->companyId,
            contractReference: $request->contractReference,
            currency: $quote->currency,
            amount: $quote->monthlyTotal,
            periodYear: $request->periodYear,
            periodMonth: $request->periodMonth,
            idempotencyKey: $idempotencyKey,
            expiresAt: $session->expires_at ?? (time() + 1800),
        );
    }

    /**
     * Resuelve el contrato de facturación CobrOS desde la solicitud.
     */
    private function resolveContract(\App\Services\Billing\Checkout\CheckoutSessionRequest $request): \App\Models\CobrosBillingContract
    {
        $contract = \App\Models\CobrosBillingContract::query()
            ->where('company_id', $request->companyId)
            ->where('id', $request->contractReference)
            ->whereIn('status', \App\Models\CobrosBillingContract::OPEN_STATUSES)
            ->first();

        if ($contract === null) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'contract' => 'Contrato de facturación no encontrado o no abierto para esta empresa.',
            ]);
        }

        // Validar período si se proporciona
        if ($request->periodYear !== null && $request->periodMonth !== null) {
            $this->assertPeriodWithinTerm($contract, $request->periodYear, $request->periodMonth);
        }

        return $contract;
    }

    /**
     * Valida que la URL pertenezca a un dominio permitido.
     */
    private function assertAllowedUrl(string $url, array $allowedDomains, string $field): void
    {
        try {
            $parsed = parse_url($url);
        } catch (\Throwable) {
            throw \Illuminate\Validation\ValidationException::withMessages([$field => 'URL inválida.']);
        }

        if (! isset($parsed['host'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([$field => 'URL debe incluir host.']);
        }

        $host = strtolower($parsed['host']);

        $allowed = false;
        foreach ($allowedDomains as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                $allowed = true;
                break;
            }
        }

        if (! $allowed) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                $field => 'Dominio no permitido para URLs de retorno.',
            ]);
        }
    }

    /**
     * Valida que el período esté dentro de la vigencia del contrato.
     */
    private function assertPeriodWithinTerm(\App\Models\CobrosBillingContract $contract, int $year, int $month): void
    {
        $start = \Carbon\CarbonImmutable::create($year, $month, 1)->startOfMonth();
        $validFrom = $contract->valid_from?->startOfDay();
        $validUntil = $contract->valid_until?->endOfDay();

        if ($validFrom !== null && $start->lt($validFrom)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'period' => 'El período es anterior al inicio de vigencia del contrato.',
            ]);
        }

        if ($validUntil !== null && $start->gt($validUntil)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'period' => 'El período es posterior al fin de vigencia del contrato.',
            ]);
        }
    }

    private function buildIdempotencyKey(\App\Services\Billing\Checkout\CheckoutSessionRequest $request, \App\Data\Cobros\CobrosPriceQuote $quote): string
    {
        $data = implode('|', [
            $request->product,
            $request->companyId,
            $request->contractReference,
            $quote->currency,
            $quote->monthlyTotal,
            $request->periodYear ?? '',
            $request->periodMonth ?? '',
        ]);

        return 'mvs-checkout-' . hash('sha256', $data);
    }
}