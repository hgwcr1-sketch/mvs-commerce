<?php

namespace App\Services\Billing\Adapters;

use App\Services\Billing\Contracts\PaymentProviderInterface;
use App\Services\Billing\DTOs\PaymentEvent;
use App\Services\Billing\DTOs\PaymentRequest;
use App\Services\Billing\Exceptions\PaymentProviderUnavailableException;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\Stripe;
use Stripe\StripeClient;

/**
 * BLOQUE 17B-2A — adaptador Stripe real.
 *
 * Requiere configuración mediante variables de entorno:
 * - STRIPE_MODE=test|live
 * - STRIPE_TEST_SECRET_KEY / STRIPE_LIVE_SECRET_KEY
 * - STRIPE_TEST_PUBLISHABLE_KEY / STRIPE_LIVE_PUBLISHABLE_KEY
 *
 * isAvailable() devuelve true solo si la configuración completa está presente.
 * createPayment() crea PaymentIntent con claves idempotentes.
 * confirmPayment() recupera/confirma PaymentIntent existente.
 * refundPayment() crea Refund sobre PaymentIntent confirmado.
 */
class StripePaymentProvider implements PaymentProviderInterface
{
    private ?StripeClient $client = null;
    private ?string $mode = null;

    /** 19D-2I: transporte IPv4 único para el SDK Stripe (errno 6 intermitente). */
    private static bool $ipv4TransportConfigured = false;

    public function key(): string
    {
        return 'stripe';
    }

    public function isAvailable(): bool
    {
        if ($this->mode !== null) {
            return $this->client !== null;
        }

        $this->mode = config('billing.stripe.mode', env('STRIPE_MODE'));

        if (! in_array($this->mode, ['test', 'live'], true)) {
            return false;
        }

        $secretKey = $this->mode === 'test'
            ? config('billing.stripe.secret_key_test', env('STRIPE_TEST_SECRET_KEY'))
            : config('billing.stripe.secret_key_live', env('STRIPE_LIVE_SECRET_KEY'));

        if (empty($secretKey)) {
            return false;
        }

        try {
            $this->configureIpv4Transport();
            $this->client = new StripeClient($secretKey);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 19D-2I — Fuerza resolución IPv4 SOLO en el transporte HTTP del SDK
     * Stripe (extensión oficial: CurlClient::defaultOptions vía
     * ApiRequestor::setHttpClient). No toca el transporte global de Laravel
     * ni TLS: CURLOPT_IPRESOLVE se añade a las opciones por defecto y
     * constructCurlOptions conserva CAINFO, timeouts y verificación SSL.
     */
    private function configureIpv4Transport(): void
    {
        if (self::$ipv4TransportConfigured) {
            return;
        }

        \Stripe\ApiRequestor::setHttpClient(new \Stripe\HttpClient\CurlClient([
            \CURLOPT_IPRESOLVE => \CURL_IPRESOLVE_V4,
        ]));

        self::$ipv4TransportConfigured = true;
    }

    private function getClient(): StripeClient
    {
        if ($this->client === null) {
            $this->isAvailable();
        }

        if ($this->client === null) {
            throw PaymentProviderUnavailableException::forProvider(
                $this->key(),
                'Stripe no está configurado: defina STRIPE_MODE y la clave secreta correspondiente.'
            );
        }

        return $this->client;
    }

    /**
     * Obtiene el cliente Stripe para sesiones Checkout.
     * Separado de getClient() por claridad semántica (mismo cliente, distinto uso).
     */
    public function getClientForCheckout(): StripeClient
    {
        return $this->getClient();
    }

    /**
     * Obtiene la clave pública (publishable key) para el frontend.
     * Solo expone la clave pública, nunca la secreta.
     */
    public function getPublishableKey(): ?string
    {
        if ($this->mode === null) {
            $this->isAvailable();
        }

        if ($this->mode === null) {
            return null;
        }

        return $this->mode === 'test'
            ? config('billing.stripe.publishable_key_test', env('STRIPE_TEST_PUBLISHABLE_KEY'))
            : config('billing.stripe.publishable_key_live', env('STRIPE_LIVE_PUBLISHABLE_KEY'));
    }

    public function createPayment(PaymentRequest $request): PaymentEvent
    {
        $client = $this->getClient();

        $amountMinor = $this->toMinorUnits($request->amount, $request->currency);

        $idempotencyKey = $this->buildIdempotencyKey($request);

        $params = [
            'amount' => $amountMinor,
            'currency' => strtolower($request->currency),
            'metadata' => array_merge([
                'product' => $request->product,
                'company_id' => $request->companyId,
                'contract_reference' => $request->contractReference,
                'period_year' => (string) ($request->periodYear ?? ''),
                'period_month' => (string) ($request->periodMonth ?? ''),
            ], $request->metadata),
        ];

        $options = ['idempotency_key' => $idempotencyKey];

        try {
            /** @var PaymentIntent $intent */
            $intent = $client->paymentIntents->create($params, $options);
        } catch (ApiErrorException $e) {
            throw PaymentProviderUnavailableException::forProvider(
                $this->key(),
                'Error creando PaymentIntent: ' . $e->getMessage()
            );
        }

        return new PaymentEvent(
            type: 'pending',
            provider: $this->key(),
            externalId: $intent->id,
            product: $request->product,
            companyId: $request->companyId,
            contractReference: $request->contractReference,
            currency: $request->currency,
            amount: $request->amount,
            periodYear: $request->periodYear,
            periodMonth: $request->periodMonth,
            rawPayload: $intent->jsonSerialize()
        );
    }

    public function confirmPayment(string $externalId): PaymentEvent
    {
        $client = $this->getClient();

        try {
            /** @var PaymentIntent $intent */
            $intent = $client->paymentIntents->retrieve($externalId);
        } catch (ApiErrorException $e) {
            throw PaymentProviderUnavailableException::forProvider(
                $this->key(),
                'Error recuperando PaymentIntent: ' . $e->getMessage()
            );
        }

        $type = $this->mapStripeStatus($intent->status);

        return new PaymentEvent(
            type: $type,
            provider: $this->key(),
            externalId: $intent->id,
            product: $intent->metadata['product'] ?? '',
            companyId: $intent->metadata['company_id'] ?? '',
            contractReference: $intent->metadata['contract_reference'] ?? '',
            currency: strtoupper($intent->currency),
            amount: $this->fromMinorUnits($intent->amount, strtoupper($intent->currency)),
            periodYear: $intent->metadata['period_year'] ?? null,
            periodMonth: $intent->metadata['period_month'] ?? null,
            rawPayload: $intent->jsonSerialize()
        );
    }

    public function refundPayment(string $externalId): PaymentEvent
    {
        $client = $this->getClient();

        try {
            /** @var PaymentIntent $intent */
            $intent = $client->paymentIntents->retrieve($externalId);

            if ($intent->status !== 'succeeded') {
                throw PaymentProviderUnavailableException::forProvider(
                    $this->key(),
                    "No se puede reembolsar: PaymentIntent {$externalId} no está confirmado (status: {$intent->status})"
                );
            }

            /** @var Refund $refund */
            $refund = $client->refunds->create([
                'payment_intent' => $externalId,
            ]);
        } catch (ApiErrorException $e) {
            throw PaymentProviderUnavailableException::forProvider(
                $this->key(),
                'Error creando reembolso: ' . $e->getMessage()
            );
        }

        return new PaymentEvent(
            type: 'refunded',
            provider: $this->key(),
            externalId: $refund->id,
            product: $intent->metadata['product'] ?? '',
            companyId: $intent->metadata['company_id'] ?? '',
            contractReference: $intent->metadata['contract_reference'] ?? '',
            currency: strtoupper($intent->currency),
            amount: $this->fromMinorUnits($refund->amount, strtoupper($intent->currency)),
            periodYear: $intent->metadata['period_year'] ?? null,
            periodMonth: $intent->metadata['period_month'] ?? null,
            rawPayload: $refund->jsonSerialize()
        );
    }

    private function toMinorUnits(string $amount, string $currency): int
    {
        $decimals = $this->getCurrencyDecimals($currency);
        $factor = 10 ** $decimals;

        return (int) round((float) $amount * $factor);
    }

    private function fromMinorUnits(int $amountMinor, string $currency): string
    {
        $decimals = $this->getCurrencyDecimals($currency);
        $factor = 10 ** $decimals;

        $whole = floor($amountMinor / $factor);
        $fraction = $amountMinor % $factor;

        return sprintf('%d.%0' . $decimals . 'd', $whole, $fraction);
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

    private function buildIdempotencyKey(PaymentRequest $request): string
    {
        $data = implode('|', [
            $request->product,
            $request->companyId,
            $request->contractReference,
            $request->currency,
            $request->amount,
            $request->periodYear ?? '',
            $request->periodMonth ?? '',
        ]);

        return 'mvs-billing-' . hash('sha256', $data);
    }

    private function mapStripeStatus(string $stripeStatus): string
    {
        return match ($stripeStatus) {
            'requires_payment_method', 'requires_confirmation', 'requires_action', 'processing' => 'pending',
            'succeeded' => 'confirmed',
            'canceled' => 'failed',
            default => 'failed',
        };
    }
}