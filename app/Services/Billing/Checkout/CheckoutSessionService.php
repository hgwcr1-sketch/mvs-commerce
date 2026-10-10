<?php

namespace App\Services\Billing\Checkout;

use App\Models\CobrosBillingContract;
use App\Services\Billing\Adapters\StripePaymentProvider;
use App\Services\Billing\Contracts\PaymentProviderInterface;
use App\Services\Billing\DTOs\PaymentRequest;
use App\Services\Billing\Exceptions\PaymentProviderUnavailableException;
use App\Services\Cobros\CobrosBillingContractService;
use App\Services\Cobros\CobrosPricingService;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * BLOQUE 17B-4A — creación de sesiones Stripe Checkout desde Billing genérico.
 *
 * Reglas:
 *  - Importe, moneda, producto, contrato y período SIEMPRE provienen de
 *    cotización/contrato interno (CobrosPricingService / CobrosBillingContractService).
 *  - NUNCA se confía en importes enviados por el navegador/cliente.
 *  - Validación de credenciales test/live, URLs de retorno permitidas, idempotencia.
 *  - Compatible con futuras suscripciones mensuales (mode: payment | subscription).
 *  - Genérico: no depende de CobrOS; usa contratos de Billing.
 */
class CheckoutSessionService
{
    public const MODE_PAYMENT = 'payment';
    public const MODE_SUBSCRIPTION = 'subscription';

    private const ALLOWED_SUCCESS_DOMAINS = [
        'localhost',
        '127.0.0.1',
        'mvscommerce.test',
        'staging.mvscommerce.com',
        'app.mvscommerce.com',
    ];

    private const ALLOWED_CANCEL_DOMAINS = [
        'localhost',
        '127.0.0.1',
        'mvscommerce.test',
        'staging.mvscommerce.com',
        'app.mvscommerce.com',
    ];

    public function __construct(
        private readonly StripePaymentProvider $stripeProvider,
        private readonly CobrosBillingContractService $billingContracts,
        private readonly CobrosPricingService $pricing,
    ) {}

    /**
     * Crea una sesión Checkout Stripe para un contrato existente.
     *
     * @throws PaymentProviderUnavailableException
     * @throws ValidationException
     */
    public function createForContract(CheckoutSessionRequest $request): CheckoutSessionResponse
    {
        // 1) Validar proveedor Stripe disponible
        if (! $this->stripeProvider->isAvailable()) {
            throw PaymentProviderUnavailableException::forProvider(
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

        // 5) Crear sesión Checkout en Stripe
        $client = $this->stripeProvider->getClientForCheckout();
        $session = $this->createStripeSession($client, $request, $contract, $quote, $idempotencyKey);

        // 6) Respuesta con datos mínimos para redirección
        return new CheckoutSessionResponse(
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
            expiresAt: $session->expires_at ?? (time() + 1800), // 30 min default
        );
    }

    /**
     * Resuelve el contrato de facturación CobrOS desde la solicitud.
     */
    private function resolveContract(CheckoutSessionRequest $request): CobrosBillingContract
    {
        $contract = CobrosBillingContract::query()
            ->where('company_id', $request->companyId)
            ->where('id', $request->contractReference)
            ->whereIn('status', CobrosBillingContract::OPEN_STATUSES)
            ->first();

        if ($contract === null) {
            throw ValidationException::withMessages([
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
     * Crea la sesión Checkout en Stripe.
     */
    private function createStripeSession(
        StripeClient $client,
        CheckoutSessionRequest $request,
        CobrosBillingContract $contract,
        \App\Data\Cobros\CobrosPriceQuote $quote,
        string $idempotencyKey,
    ): \Stripe\Checkout\Session {
        $params = [
            'mode' => self::MODE_PAYMENT, // Futuro: subscription
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => strtolower($quote->currency),
                    'product_data' => [
                        'name' => $this->buildProductName($quote),
                        'description' => $this->buildProductDescription($quote, $contract),
                        'metadata' => array_merge([
                            'product' => $request->product,
                            'company_id' => $request->companyId,
                            'contract_reference' => $request->contractReference,
                            'period_year' => (string) ($request->periodYear ?? ''),
                            'period_month' => (string) ($request->periodMonth ?? ''),
                        ], $request->metadata),
                    ],
                    'unit_amount' => $this->toMinorUnits($quote->monthlyTotal, $quote->currency),
                ],
                'quantity' => 1,
            ]],
            'success_url' => $request->successUrl,
            'cancel_url' => $request->cancelUrl,
            'metadata' => array_merge([
                'product' => $request->product,
                'company_id' => $request->companyId,
                'contract_reference' => $request->contractReference,
                'period_year' => (string) ($request->periodYear ?? ''),
                'period_month' => (string) ($request->periodMonth ?? ''),
                'billing_mode' => $contract->billing_mode,
            ], $request->metadata),
            'expires_at' => time() + 1800, // 30 minutos
        ];

        try {
            return $client->checkout->sessions->create($params, [
                'idempotency_key' => $idempotencyKey,
            ]);
        } catch (ApiErrorException $e) {
            throw PaymentProviderUnavailableException::forProvider(
                'stripe',
                'Error creando sesión Checkout: ' . $e->getMessage()
            );
        }
    }

    private function buildProductName(\App\Data\Cobros\CobrosPriceQuote $quote): string
    {
        $modalityLabel = match ($quote->modality) {
            'prestamista' => 'Prestamista',
            'prestamista_cobrador' => 'Prestamista-Cobrador',
            'cobrador_vinculado' => 'Cobrador vinculado',
            'cobrador_independiente' => 'Cobrador independiente',
            'cobrador_multiprestamista' => 'Cobrador multiprestamista',
            default => 'CobrOS',
        };

        return "MVS CobrOS - {$modalityLabel} ({$quote->countryIso})";
    }

    private function buildProductDescription(\App\Data\Cobros\CobrosPriceQuote $quote, CobrosBillingContract $contract): string
    {
        $parts = [
            "Modalidad: {$quote->modality}",
            "País: {$quote->countryIso}",
            "Moneda: {$quote->currency}",
        ];

        if ($contract->billing_mode === CobrosBillingContract::MODE_ENTERPRISE) {
            $parts[] = "Cupos: {$contract->seats_total}";
        }

        return implode(' | ', $parts);
    }

    /**
     * Valida que la URL pertenezca a un dominio permitido.
     */
    private function assertAllowedUrl(string $url, array $allowedDomains, string $field): void
    {
        try {
            $parsed = parse_url($url);
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => 'URL inválida.']);
        }

        if (! isset($parsed['host'])) {
            throw ValidationException::withMessages([$field => 'URL debe incluir host.']);
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
            throw ValidationException::withMessages([
                $field => 'Dominio no permitido para URLs de retorno.',
            ]);
        }
    }

    /**
     * Valida que el período esté dentro de la vigencia del contrato.
     */
    private function assertPeriodWithinTerm(CobrosBillingContract $contract, int $year, int $month): void
    {
        $start = \Carbon\CarbonImmutable::create($year, $month, 1)->startOfMonth();
        $validFrom = $contract->valid_from?->startOfDay();
        $validUntil = $contract->valid_until?->endOfDay();

        if ($validFrom !== null && $start->lt($validFrom)) {
            throw ValidationException::withMessages([
                'period' => 'El período es anterior al inicio de vigencia del contrato.',
            ]);
        }

        if ($validUntil !== null && $start->gt($validUntil)) {
            throw ValidationException::withMessages([
                'period' => 'El período es posterior al fin de vigencia del contrato.',
            ]);
        }
    }

    private function buildIdempotencyKey(CheckoutSessionRequest $request, \App\Data\Cobros\CobrosPriceQuote $quote): string
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

    private function toMinorUnits(string $amount, string $currency): int
    {
        $decimals = $this->getCurrencyDecimals($currency);
        $factor = 10 ** $decimals;

        return (int) round((float) $amount * $factor);
    }

    private function getCurrencyDecimals(string $currency): int
    {
        $zeroDecimalCurrencies = [
            'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW',
            'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF',
            'XOF', 'XPF'
        ];

        return in_array(strtoupper($currency), $zeroDecimalCurrencies, true) ? 0 : 2;
    }
}