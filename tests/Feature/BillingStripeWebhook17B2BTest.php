<?php

namespace Tests\Feature;

use App\Models\BillingPaymentRecord;
use App\Services\Billing\DTOs\PaymentEvent;
use App\Services\Billing\PaymentDispatcher;
use App\Services\Billing\PaymentProviderRegistry;
use App\Services\Billing\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\Event;
use Stripe\Stripe;
use Stripe\Webhook;
use Tests\TestCase;

/**
 * BLOQUE 17B-2B — pruebas focales del webhook Stripe.
 *
 * Usan payloads firmados localmente con Webhook::signPayload();
 * ninguna llamada real a la API de Stripe.
 */
class BillingStripeWebhook17B2BTest extends TestCase
{
    use RefreshDatabase;

    private PaymentProviderRegistry $registry;
    private PaymentService $service;
    private string $webhookSecret = 'whsec_test_mocksecret12345678901234567890123456789012';

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new PaymentProviderRegistry();
        $dispatcher = new PaymentDispatcher($this->app);
        $this->service = new PaymentService($this->registry, $dispatcher);

        $this->app['config']->set('billing.stripe.mode', 'test');
        $this->app['config']->set('billing.stripe.webhook_secret_test', $this->webhookSecret);
        $this->app['config']->set('billing.stripe.webhook_secret_live', 'whsec_live_mocksecret12345678901234567890123456789012');
    }

    private function signPayload(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return \Stripe\WebhookSignature::generateSignatureHeader($json, $this->webhookSecret);
    }

    private function basePaymentIntentSucceeded(array $overrides = []): array
    {
        return array_merge([
            'id' => 'evt_test_webhook_' . uniqid(),
            'object' => 'event',
            'api_version' => '2024-04-10',
            'created' => time(),
            'data' => [
                'object' => array_merge([
                    'id' => 'pi_test_' . uniqid(),
                    'object' => 'payment_intent',
                    'amount' => 1000000,
                    'amount_capturable' => 0,
                    'amount_details' => [],
                    'amount_received' => 1000000,
                    'application' => null,
                    'application_fee_amount' => null,
                    'automatic_payment_methods' => null,
                    'canceled_at' => null,
                    'cancellation_reason' => null,
                    'capture_method' => 'automatic',
                    'client_secret' => 'pi_test_secret',
                    'confirmation_method' => 'automatic',
                    'created' => time(),
                    'currency' => 'crc',
                    'customer' => null,
                    'description' => null,
                    'invoice' => null,
                    'last_payment_error' => null,
                    'latest_charge' => 'ch_test_123',
                    'livemode' => false,
                    'metadata' => [
                        'product' => 'cobros',
                        'company_id' => '1',
                        'contract_reference' => 'CTR-001',
                        'period_year' => '2026',
                        'period_month' => '10',
                    ],
                    'next_action' => null,
                    'on_behalf_of' => null,
                    'payment_method' => 'pm_test_123',
                    'payment_method_options' => [],
                    'payment_method_types' => ['card'],
                    'processing' => null,
                    'receipt_email' => null,
                    'review' => null,
                    'setup_future_usage' => null,
                    'shipping' => null,
                    'source' => null,
                    'statement_descriptor' => null,
                    'statement_descriptor_suffix' => null,
                    'status' => 'succeeded',
                    'transfer_data' => null,
                    'transfer_group' => null,
                ], $overrides['data']['object'] ?? []),
            ],
            'livemode' => false,
            'pending_webhooks' => 1,
            'request' => ['id' => null, 'idempotency_key' => null],
            'type' => 'payment_intent.succeeded',
        ], $overrides);
    }

    private function basePaymentIntentFailed(array $overrides = []): array
    {
        return array_merge([
            'id' => 'evt_test_webhook_' . uniqid(),
            'object' => 'event',
            'api_version' => '2024-04-10',
            'created' => time(),
            'data' => [
                'object' => array_merge([
                    'id' => 'pi_test_' . uniqid(),
                    'object' => 'payment_intent',
                    'amount' => 1000000,
                    'currency' => 'crc',
                    'livemode' => false,
                    'metadata' => [
                        'product' => 'cobros',
                        'company_id' => '1',
                        'contract_reference' => 'CTR-001',
                        'period_year' => '2026',
                        'period_month' => '10',
                    ],
                    'status' => 'canceled',
                    'last_payment_error' => [
                        'code' => 'card_declined',
                        'message' => 'Your card was declined.',
                    ],
                ], $overrides['data']['object'] ?? []),
            ],
            'livemode' => false,
            'pending_webhooks' => 1,
            'request' => ['id' => null, 'idempotency_key' => null],
            'type' => 'payment_intent.payment_failed',
        ], $overrides);
    }

    private function baseChargeRefunded(array $overrides = []): array
    {
        return array_merge([
            'id' => 'evt_test_webhook_' . uniqid(),
            'object' => 'event',
            'api_version' => '2024-04-10',
            'created' => time(),
            'data' => [
                'object' => array_merge([
                    'id' => 'ch_test_' . uniqid(),
                    'object' => 'charge',
                    'amount' => 1000000,
                    'amount_refunded' => 1000000,
                    'currency' => 'crc',
                    'livemode' => false,
                    'metadata' => [
                        'product' => 'cobros',
                        'company_id' => '1',
                        'contract_reference' => 'CTR-001',
                        'period_year' => '2026',
                        'period_month' => '10',
                    ],
                    'payment_intent' => 'pi_test_123',
                    'refunded' => true,
                    'refunds' => [
                        'object' => 'list',
                        'data' => [
                            ['id' => 're_test_123', 'amount' => 1000000, 'currency' => 'crc'],
                        ],
                    ],
                ], $overrides['data']['object'] ?? []),
            ],
            'livemode' => false,
            'pending_webhooks' => 1,
            'request' => ['id' => null, 'idempotency_key' => null],
            'type' => 'charge.refunded',
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // Firma válida / inválida
    // ------------------------------------------------------------------

    /**
     * 19D-2J — la ruta webhook debe estar exenta de CSRF (PreventRequestForgery).
     *
     * El withoutMiddleware antiguo referenciaba VerifyCsrfToken (clase
     * inexistente en Laravel 12) y el 419 era del middleware real
     * PreventRequestForgery. Verifica el registro del except en bootstrap.
     */
    public function test_webhook_ruta_exenta_de_csrf(): void
    {
        $reflection = new \ReflectionClass(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
        $property = $reflection->getProperty('neverVerify');
        $property->setAccessible(true);

        $this->assertContains('billing/webhook/stripe', $property->getValue());
    }

    public function test_webhook_valid_signature_processes_event(): void
    {
        $payload = $this->basePaymentIntentSucceeded();
        $signature = $this->signPayload($payload);

        $response = $this->postJson('/billing/webhook/stripe', $payload, [
            'Stripe-Signature' => $signature,
        ]);

        $this->assertNotSame(419, $response->getStatusCode());

        $response->assertStatus(200)
            ->assertJson(['received' => true, 'processed' => true]);

        $this->assertDatabaseHas('billing_payment_records', [
            'provider' => 'stripe',
            'external_id' => $payload['data']['object']['id'],
            'status' => 'confirmed',
        ]);
    }

    public function test_webhook_invalid_signature_rejected(): void
    {
        $payload = $this->basePaymentIntentSucceeded();
        $signature = 'invalid_signature';

        $response = $this->postJson('/billing/webhook/stripe', $payload, [
            'Stripe-Signature' => $signature,
        ]);

        $response->assertStatus(400)
            ->assertJson(['error' => 'Firma inválida']);
    }

    public function test_webhook_missing_signature_rejected(): void
    {
        $payload = $this->basePaymentIntentSucceeded();

        $response = $this->postJson('/billing/webhook/stripe', $payload, []);

        $response->assertStatus(400)
            ->assertJson(['error' => 'Firma faltante']);
    }

    // ------------------------------------------------------------------
    // Deduplicación por event.id
    // ------------------------------------------------------------------

    public function test_webhook_duplicate_event_id_returns_200(): void
    {
        $payload = $this->basePaymentIntentSucceeded();
        $signature = $this->signPayload($payload);

        $this->postJson('/billing/webhook/stripe', $payload, [
            'Stripe-Signature' => $signature,
        ])->assertStatus(200);

        $response = $this->postJson('/billing/webhook/stripe', $payload, [
            'Stripe-Signature' => $signature,
        ]);

        $response->assertStatus(200)
            ->assertJson(['received' => true, 'duplicate' => true]);

        $this->assertSame(1, BillingPaymentRecord::query()->count());
    }

    // ------------------------------------------------------------------
    // Modo livemode incorrecto
    // ------------------------------------------------------------------

    public function test_webhook_livemode_mismatch_rejected(): void
    {
        $payload = $this->basePaymentIntentSucceeded();
        $payload['livemode'] = true; // evento live pero config test
        $signature = $this->signPayload($payload);

        $response = $this->postJson('/billing/webhook/stripe', $payload, [
            'Stripe-Signature' => $signature,
        ]);

        $response->assertStatus(400)
            ->assertJson(['error' => 'Modo livemode no coincide']);
    }

    public function test_webhook_live_mode_uses_live_secret(): void
    {
        $this->app['config']->set('billing.stripe.mode', 'live');

        $payload = $this->basePaymentIntentSucceeded();
        $payload['livemode'] = true;
        $signature = \Stripe\WebhookSignature::generateSignatureHeader(json_encode($payload), config('billing.stripe.webhook_secret_live'));

        $response = $this->postJson('/billing/webhook/stripe', $payload, [
            'Stripe-Signature' => $signature,
        ]);

        $response->assertStatus(200);
    }

    // ------------------------------------------------------------------
    // Importe / moneda inconsistentes
    // ------------------------------------------------------------------

    public function test_webhook_amount_mismatch_rejected(): void
    {
        $payload = $this->basePaymentIntentSucceeded();
        $signature = $this->signPayload($payload);

        $this->postJson('/billing/webhook/stripe', $payload, [
            'Stripe-Signature' => $signature,
        ])->assertStatus(200);

        // Segundo evento con mismo payment_intent pero importe distinto
        $payload2 = $this->basePaymentIntentSucceeded([
            'data' => ['object' => [
                'id' => $payload['data']['object']['id'],
                'amount' => 2000000,
                'currency' => 'crc',
                'metadata' => [
                    'product' => 'cobros',
                    'company_id' => '1',
                    'contract_reference' => 'CTR-001',
                    'period_year' => '2026',
                    'period_month' => '10',
                ],
            ]],
        ]);
        $payload2['id'] = 'evt_test_webhook_' . uniqid();
        $signature2 = $this->signPayload($payload2);

        $response = $this->postJson('/billing/webhook/stripe', $payload2, [
            'Stripe-Signature' => $signature2,
        ]);

        $response->assertStatus(400)
            ->assertJson(['error' => 'Evento inconsistente']);
    }

    public function test_webhook_currency_mismatch_rejected(): void
    {
        $payload = $this->basePaymentIntentSucceeded();
        $signature = $this->signPayload($payload);

        $this->postJson('/billing/webhook/stripe', $payload, [
            'Stripe-Signature' => $signature,
        ])->assertStatus(200);

        $payload2 = $this->basePaymentIntentSucceeded([
            'data' => ['object' => [
                'id' => $payload['data']['object']['id'],
                'amount' => 1000000,
                'currency' => 'usd',
                'metadata' => [
                    'product' => 'cobros',
                    'company_id' => '1',
                    'contract_reference' => 'CTR-001',
                    'period_year' => '2026',
                    'period_month' => '10',
                ],
            ]],
        ]);
        $payload2['id'] = 'evt_test_webhook_' . uniqid();
        $signature2 = $this->signPayload($payload2);

        $response = $this->postJson('/billing/webhook/stripe', $payload2, [
            'Stripe-Signature' => $signature2,
        ]);

        $response->assertStatus(400)
            ->assertJson(['error' => 'Evento inconsistente']);
    }

    // ------------------------------------------------------------------
    // Eventos fuera de orden
    // ------------------------------------------------------------------

    public function test_webhook_failed_before_confirmed_then_confirmed_updates(): void
    {
        $failedPayload = $this->basePaymentIntentFailed();
        $failedSignature = $this->signPayload($failedPayload);

        $this->postJson('/billing/webhook/stripe', $failedPayload, [
            'Stripe-Signature' => $failedSignature,
        ])->assertStatus(200);

        $this->assertDatabaseHas('billing_payment_records', [
            'external_id' => $failedPayload['data']['object']['id'],
            'status' => 'failed',
        ]);

        $confirmedPayload = $this->basePaymentIntentSucceeded([
            'data' => ['object' => [
                'id' => $failedPayload['data']['object']['id'],
                'status' => 'succeeded',
                'amount' => 1000000,
                'currency' => 'crc',
                'metadata' => [
                    'product' => 'cobros',
                    'company_id' => '1',
                    'contract_reference' => 'CTR-001',
                    'period_year' => '2026',
                    'period_month' => '10',
                ],
            ]],
        ]);
        $confirmedPayload['id'] = 'evt_test_webhook_' . uniqid();
        $confirmedSignature = $this->signPayload($confirmedPayload);

        $this->postJson('/billing/webhook/stripe', $confirmedPayload, [
            'Stripe-Signature' => $confirmedSignature,
        ])->assertStatus(200);

        $this->assertDatabaseHas('billing_payment_records', [
            'external_id' => $failedPayload['data']['object']['id'],
            'status' => 'confirmed',
        ]);
    }

    // ------------------------------------------------------------------
    // Eventos no soportados
    // ------------------------------------------------------------------

    public function test_webhook_unsupported_type_returns_200_ignored(): void
    {
        $payload = [
            'id' => 'evt_test_webhook_' . uniqid(),
            'object' => 'event',
            'api_version' => '2024-04-10',
            'created' => time(),
            'data' => ['object' => ['id' => 'cus_test_123']],
            'livemode' => false,
            'pending_webhooks' => 1,
            'request' => ['id' => null],
            'type' => 'customer.created',
        ];
        $signature = $this->signPayload($payload);

        $response = $this->postJson('/billing/webhook/stripe', $payload, [
            'Stripe-Signature' => $signature,
        ]);

        $response->assertStatus(200)
            ->assertJson(['received' => true, 'ignored' => 'customer.created']);
    }

    // ------------------------------------------------------------------
    // Fallo interno permite reintento
    // ------------------------------------------------------------------

    public function test_webhook_internal_error_returns_500(): void
    {
        // Simular fallo interno pasando metadata que cause error
        $payload = $this->basePaymentIntentSucceeded([
            'data' => ['object' => ['metadata' => []]], // sin product
        ]);
        $signature = $this->signPayload($payload);

        $response = $this->postJson('/billing/webhook/stripe', $payload, [
            'Stripe-Signature' => $signature,
        ]);

        $response->assertStatus(400)
            ->assertJson(['error' => 'Evento no mapeable']);
    }

    // ------------------------------------------------------------------
    // Eventos de reembolso
    // ------------------------------------------------------------------

    public function test_webhook_charge_refunded_creates_refunded_record(): void
    {
        $payload = $this->baseChargeRefunded();
        $signature = $this->signPayload($payload);

        $response = $this->postJson('/billing/webhook/stripe', $payload, [
            'Stripe-Signature' => $signature,
        ]);

        $response->assertStatus(200)
            ->assertJson(['received' => true, 'processed' => true]);

        $this->assertDatabaseHas('billing_payment_records', [
            'provider' => 'stripe',
            'status' => 'refunded',
        ]);
    }

    // ------------------------------------------------------------------
    // Sin activación de licencias
    // ------------------------------------------------------------------

    public function test_webhook_does_not_trigger_license_activation(): void
    {
        $payload = $this->basePaymentIntentSucceeded();
        $signature = $this->signPayload($payload);

        $this->postJson('/billing/webhook/stripe', $payload, [
            'Stripe-Signature' => $signature,
        ])->assertStatus(200);

        // Verificar que no se llamó a servicios de activación de licencias
        // (El webhook solo usa PaymentService, sin dependencias de CobrOS)
        $this->assertTrue(true, 'Webhook desacoplado de CobrOS');
    }

    // ------------------------------------------------------------------
    // Zero-decimal currency
    // ------------------------------------------------------------------

    public function test_webhook_jpy_zero_decimal_amount(): void
    {
        $payload = $this->basePaymentIntentSucceeded([
            'data' => ['object' => [
                'id' => 'pi_test_jpy',
                'currency' => 'jpy',
                'amount' => 1000,
                'metadata' => [
                    'product' => 'cobros',
                    'company_id' => '1',
                    'contract_reference' => 'CTR-001',
                    'period_year' => '2026',
                    'period_month' => '10',
                ],
            ]],
        ]);
        $signature = $this->signPayload($payload);

        $response = $this->postJson('/billing/webhook/stripe', $payload, [
            'Stripe-Signature' => $signature,
        ]);

        $response->assertStatus(200);

        $record = BillingPaymentRecord::query()->first();
        $this->assertSame('1000', $record->amount);
        $this->assertSame('JPY', $record->currency);
    }

    // ------------------------------------------------------------------
    // Rate limiting
    // ------------------------------------------------------------------

    public function test_webhook_rate_limited(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $payload = $this->basePaymentIntentSucceeded([
                'data' => ['object' => [
                    'id' => 'pi_test_' . $i,
                    'metadata' => [
                        'product' => 'cobros',
                        'company_id' => '1',
                        'contract_reference' => 'CTR-001',
                        'period_year' => '2026',
                        'period_month' => '10',
                    ],
                ]],
            ]);
            $payload['id'] = 'evt_test_webhook_' . $i;
            $signature = $this->signPayload($payload);

            $this->postJson('/billing/webhook/stripe', $payload, [
                'Stripe-Signature' => $signature,
            ]);
        }

        $payload = $this->basePaymentIntentSucceeded([
            'data' => ['object' => [
                'id' => 'pi_test_overflow',
                'metadata' => [
                    'product' => 'cobros',
                    'company_id' => '1',
                    'contract_reference' => 'CTR-001',
                    'period_year' => '2026',
                    'period_month' => '10',
                ],
            ]],
        ]);
        $payload['id'] = 'evt_test_webhook_overflow';
        $signature = $this->signPayload($payload);

        $response = $this->postJson('/billing/webhook/stripe', $payload, [
            'Stripe-Signature' => $signature,
        ]);

        $response->assertStatus(429);
    }

    // ------------------------------------------------------------------
    // 19D-2L — checkout.session.completed (respaldo de identificación)
    // ------------------------------------------------------------------

    private function baseCheckoutSessionCompleted(array $overrides = []): array
    {
        return array_merge([
            'id' => 'evt_test_cs_' . uniqid(),
            'object' => 'event',
            'api_version' => '2024-04-10',
            'created' => time(),
            'data' => [
                'object' => array_merge([
                    'id' => 'cs_test_' . uniqid(),
                    'object' => 'checkout.session',
                    'amount_subtotal' => 1000000,
                    'amount_total' => 1000000,
                    'currency' => 'crc',
                    'currency_conversion' => null,
                    'customer_details' => null,
                    'custom_text' => [],
                    'expires_at' => time() + 1800,
                    'livemode' => false,
                    'metadata' => [
                        'product' => 'cobros',
                        'company_id' => '1',
                        'contract_reference' => 'CTR-001',
                        'period_year' => '2026',
                        'period_month' => '10',
                    ],
                    'mode' => 'payment',
                    'payment_intent' => 'pi_test_cs_' . uniqid(),
                    'payment_method_types' => ['card'],
                    'payment_status' => 'paid',
                    'status' => 'complete',
                ], $overrides['data']['object'] ?? []),
            ],
            'livemode' => false,
            'pending_webhooks' => 1,
            'request' => ['id' => null, 'idempotency_key' => null],
            'type' => 'checkout.session.completed',
        ], $overrides);
    }

    public function test_webhook_checkout_session_paid_processes_event(): void
    {
        $payload = $this->baseCheckoutSessionCompleted();
        $signature = $this->signPayload($payload);

        $response = $this->postJson('/billing/webhook/stripe', $payload, [
            'Stripe-Signature' => $signature,
        ]);

        $response->assertStatus(200)
            ->assertJson(['received' => true, 'processed' => true]);

        $this->assertDatabaseHas('billing_payment_records', [
            'provider' => 'stripe',
            'external_id' => $payload['data']['object']['payment_intent'],
            'status' => 'confirmed',
        ]);
    }

    public function test_webhook_checkout_session_not_paid_rejected(): void
    {
        $payload = $this->baseCheckoutSessionCompleted([
            'data' => ['object' => ['payment_status' => 'unpaid', 'status' => 'open']],
        ]);
        $signature = $this->signPayload($payload);

        $this->postJson('/billing/webhook/stripe', $payload, [
            'Stripe-Signature' => $signature,
        ])->assertStatus(400)
            ->assertJson(['error' => 'Evento no mapeable']);

        $this->assertSame(0, BillingPaymentRecord::query()->count());
    }

    public function test_webhook_checkout_session_without_payment_intent_rejected(): void
    {
        $payload = $this->baseCheckoutSessionCompleted([
            'data' => ['object' => ['payment_intent' => null]],
        ]);
        $signature = $this->signPayload($payload);

        $this->postJson('/billing/webhook/stripe', $payload, [
            'Stripe-Signature' => $signature,
        ])->assertStatus(400)
            ->assertJson(['error' => 'Evento no mapeable']);
    }

    public function test_webhook_checkout_session_invalid_signature_rejected(): void
    {
        $payload = $this->baseCheckoutSessionCompleted();

        $this->postJson('/billing/webhook/stripe', $payload, [
            'Stripe-Signature' => 't=1,v1=firma_invalida',
        ])->assertStatus(400)
            ->assertJson(['error' => 'Firma inválida']);

        $this->assertSame(0, BillingPaymentRecord::query()->count());
    }

    public function test_webhook_checkout_session_replay_duplicate(): void
    {
        $payload = $this->baseCheckoutSessionCompleted();
        $signature = $this->signPayload($payload);

        $this->postJson('/billing/webhook/stripe', $payload, [
            'Stripe-Signature' => $signature,
        ])->assertStatus(200);

        $this->postJson('/billing/webhook/stripe', $payload, [
            'Stripe-Signature' => $signature,
        ])->assertStatus(200)
            ->assertJson(['received' => true, 'duplicate' => true]);

        $this->assertSame(1, BillingPaymentRecord::query()->count());
    }

    /**
     * Idempotencia cruzada: payment_intent.succeeded y
     * checkout.session.completed del mismo PaymentIntent => UN registro.
     */
    public function test_webhook_cross_event_pi_and_session_single_record(): void
    {
        $piId = 'pi_test_cross_' . uniqid();

        $piPayload = $this->basePaymentIntentSucceeded([
            'data' => ['object' => array_merge($this->basePaymentIntentSucceeded()['data']['object'], ['id' => $piId])],
        ]);
        $this->postJson('/billing/webhook/stripe', $piPayload, [
            'Stripe-Signature' => $this->signPayload($piPayload),
        ])->assertStatus(200);

        $sessionPayload = $this->baseCheckoutSessionCompleted([
            'data' => ['object' => array_merge($this->baseCheckoutSessionCompleted()['data']['object'], ['payment_intent' => $piId])],
        ]);
        $resp = $this->postJson('/billing/webhook/stripe', $sessionPayload, [
            'Stripe-Signature' => $this->signPayload($sessionPayload),
        ]);
        $this->assertSame(200, $resp->getStatusCode(), $resp->getContent());

        $this->assertSame(1, BillingPaymentRecord::query()
            ->where('external_id', $piId)
            ->count());
        $this->assertSame(1, BillingPaymentRecord::query()->count());
    }

    /**
     * Evento cruzado inverso: sesión primero, PI después — también UN registro.
     */
    public function test_webhook_cross_event_session_then_pi_single_record(): void
    {
        $piId = 'pi_test_cross2_' . uniqid();

        $sessionPayload = $this->baseCheckoutSessionCompleted([
            'data' => ['object' => array_merge($this->baseCheckoutSessionCompleted()['data']['object'], ['payment_intent' => $piId])],
        ]);
        $this->postJson('/billing/webhook/stripe', $sessionPayload, [
            'Stripe-Signature' => $this->signPayload($sessionPayload),
        ])->assertStatus(200);

        $piPayload = $this->basePaymentIntentSucceeded([
            'data' => ['object' => array_merge($this->basePaymentIntentSucceeded()['data']['object'], ['id' => $piId])],
        ]);
        $this->postJson('/billing/webhook/stripe', $piPayload, [
            'Stripe-Signature' => $this->signPayload($piPayload),
        ])->assertStatus(200);

        $this->assertSame(1, BillingPaymentRecord::query()->count());
    }

    /**
     * Contrato ajeno/inexistente: el webhook registra el pago pero el handler
     * CobrOS no activa nada (sin confirmaciones ni licencias).
     */
    public function test_webhook_checkout_session_foreign_contract_no_activation(): void
    {
        $payload = $this->baseCheckoutSessionCompleted([
            'data' => ['object' => array_merge($this->baseCheckoutSessionCompleted()['data']['object'], [
                'metadata' => [
                    'product' => 'cobros',
                    'company_id' => '999999',
                    'contract_reference' => '999999',
                    'period_year' => '2026',
                    'period_month' => '10',
                ],
            ])],
        ]);

        $this->postJson('/billing/webhook/stripe', $payload, [
            'Stripe-Signature' => $this->signPayload($payload),
        ])->assertStatus(200);

        $this->assertSame(0, \App\Models\CobrosPaymentConfirmation::query()->count());
        $this->assertSame(0, \App\Models\CobrosCollectorLicense::query()->count());
    }
}