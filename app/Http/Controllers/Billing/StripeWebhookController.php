<?php

namespace App\Http\Controllers\Billing;

use App\Models\BillingPaymentRecord;
use App\Services\Billing\Contracts\PaymentEventTypes;
use App\Services\Billing\DTOs\PaymentEvent;
use App\Services\Billing\PaymentService;
use App\Services\Billing\PaymentProviderRegistry;
use App\Services\Billing\PaymentDispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Stripe\Event as StripeEvent;

/**
 * BLOQUE 17B-2B — Webhook seguro de Stripe para MVS Billing.
 *
 * Recibe eventos de Stripe, verifica la firma y los normaliza
 * mediante el motor genérico. No activa licencias ni servicios CobrOS.
 */
class StripeWebhookController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly PaymentProviderRegistry $registry,
    ) {
    }

    /**
     * POST /billing/webhook/stripe
     *
     * Valida firma, normaliza evento y lo procesa idempotentemente.
     * Responde 200 solo si el procesamiento interno tuvo éxito.
     */
    public function handle(Request $request): JsonResponse
    {
        $mode = config('billing.stripe.mode', 'test');
        $webhookSecret = $mode === 'live'
            ? config('billing.stripe.webhook_secret_live', env('STRIPE_WEBHOOK_SECRET_LIVE'))
            : config('billing.stripe.webhook_secret_test', env('STRIPE_WEBHOOK_SECRET_TEST'));

        if (empty($webhookSecret)) {
            return response()->json(['error' => 'Webhook no configurado'], 500);
        }

        $signature = $request->header('Stripe-Signature');
        if (empty($signature)) {
            return response()->json(['error' => 'Firma faltante'], 400);
        }

        $payload = $request->getContent();

        try {
            /** @var StripeEvent $stripeEvent */
            $stripeEvent = Webhook::constructEvent($payload, $signature, $webhookSecret);
        } catch (SignatureVerificationException $e) {
            return response()->json(['error' => 'Firma inválida'], 400);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Payload inválido'], 400);
        }

        // Verificar livemode coincide con configuración
        if (($stripeEvent->livemode ?? false) !== ($mode === 'live')) {
            return response()->json(['error' => 'Modo livemode no coincide'], 400);
        }

        // Solo procesar eventos de pagos/reembolsos que nos interesan
        $supportedTypes = [
            'payment_intent.succeeded',
            'payment_intent.payment_failed',
            'payment_intent.canceled',
            'charge.refunded',
            'refund.created',
        ];

        if (! in_array($stripeEvent->type, $supportedTypes, true)) {
            return response()->json(['received' => true, 'ignored' => $stripeEvent->type], 200);
        }

        // Idempotencia por event.id de Stripe (más duradera que payment_intent.id)
        $eventId = $stripeEvent->id;

        // Verificar si ya procesamos este event.id
        $existingByEventId = $this->findByStripeEventId($eventId);
        if ($existingByEventId !== null) {
            return response()->json(['received' => true, 'duplicate' => true], 200);
        }

        // Mapear evento Stripe a nuestro PaymentEvent
        $paymentEvent = $this->mapStripeEvent($stripeEvent);

        if ($paymentEvent === null) {
            return response()->json(['error' => 'Evento no mapeable'], 400);
        }

        // Validaciones de integridad
        if (! $this->validateEventIntegrity($stripeEvent, $paymentEvent)) {
            return response()->json(['error' => 'Evento inconsistente'], 400);
        }

        try {
            $record = $this->paymentService->processEvent($paymentEvent);

            // Asegurar que el estado refleje el tipo de evento (workaround para persistRecord que pone PENDING en no-CONFIRMED)
            $expectedStatus = match ($paymentEvent->type) {
                PaymentEventTypes::CONFIRMED => BillingPaymentRecord::STATUS_CONFIRMED,
                PaymentEventTypes::FAILED => BillingPaymentRecord::STATUS_FAILED,
                PaymentEventTypes::REFUNDED => BillingPaymentRecord::STATUS_REFUNDED,
                default => $record->status,
            };

            if ($record->status !== $expectedStatus) {
                $record->status = $expectedStatus;
                if ($paymentEvent->isConfirmed() && $record->confirmed_at === null) {
                    $record->confirmed_at = now();
                }
                $record->save();
            }

            // Guardar el event.id de Stripe para deduplicación futura
            $record->stripe_event_id = $eventId;
            $record->save();

            return response()->json(['received' => true, 'processed' => true], 200);
        } catch (\Throwable $e) {
            // No devolver 200 para permitir reintento de Stripe
            return response()->json(['error' => 'Error interno'], 500);
        }
    }

    private function mapStripeEvent(StripeEvent $stripeEvent): ?PaymentEvent
    {
        $data = $stripeEvent->data->object ?? null;
        if ($data === null) {
            return null;
        }

        $metadata = $this->normalizeMetadata($data->metadata ?? []);
        $provider = 'stripe';

        // Para reembolsos, el externalId debe ser el payment_intent original para idempotencia
        $externalId = $data->id;
        if (in_array($stripeEvent->type, ['charge.refunded', 'refund.created'], true)) {
            $externalId = $data->payment_intent ?? $data->id;
        }

        // Validar que tenemos metadata mínima
        if (empty($metadata['product'] ?? '')) {
            return null;
        }

        return match ($stripeEvent->type) {
            'payment_intent.succeeded' => new PaymentEvent(
                type: PaymentEventTypes::CONFIRMED,
                provider: $provider,
                externalId: $externalId,
                product: $metadata['product'] ?? '',
                companyId: $metadata['company_id'] ?? '',
                contractReference: $metadata['contract_reference'] ?? '',
                currency: strtoupper($data->currency ?? ''),
                amount: $this->fromMinorUnits($data->amount ?? 0, strtoupper($data->currency ?? '')),
                periodYear: isset($metadata['period_year']) && $metadata['period_year'] !== '' ? (int) $metadata['period_year'] : null,
                periodMonth: isset($metadata['period_month']) && $metadata['period_month'] !== '' ? (int) $metadata['period_month'] : null,
                rawPayload: $stripeEvent->jsonSerialize()
            ),
            'payment_intent.payment_failed', 'payment_intent.canceled' => new PaymentEvent(
                type: PaymentEventTypes::FAILED,
                provider: $provider,
                externalId: $externalId,
                product: $metadata['product'] ?? '',
                companyId: $metadata['company_id'] ?? '',
                contractReference: $metadata['contract_reference'] ?? '',
                currency: strtoupper($data->currency ?? ''),
                amount: $this->fromMinorUnits($data->amount ?? 0, strtoupper($data->currency ?? '')),
                periodYear: isset($metadata['period_year']) && $metadata['period_year'] !== '' ? (int) $metadata['period_year'] : null,
                periodMonth: isset($metadata['period_month']) && $metadata['period_month'] !== '' ? (int) $metadata['period_month'] : null,
                rawPayload: $stripeEvent->jsonSerialize()
            ),
            'charge.refunded', 'refund.created' => new PaymentEvent(
                type: PaymentEventTypes::REFUNDED,
                provider: $provider,
                externalId: $externalId,
                product: $metadata['product'] ?? '',
                companyId: $metadata['company_id'] ?? '',
                contractReference: $metadata['contract_reference'] ?? '',
                currency: strtoupper($data->currency ?? ''),
                amount: $this->fromMinorUnits($data->amount_refunded ?? $data->amount ?? 0, strtoupper($data->currency ?? '')),
                periodYear: isset($metadata['period_year']) && $metadata['period_year'] !== '' ? (int) $metadata['period_year'] : null,
                periodMonth: isset($metadata['period_month']) && $metadata['period_month'] !== '' ? (int) $metadata['period_month'] : null,
                rawPayload: $stripeEvent->jsonSerialize()
            ),
            default => null,
        };
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

    private function validateEventIntegrity(StripeEvent $stripeEvent, PaymentEvent $paymentEvent): bool
    {
        $data = $stripeEvent->data->object ?? null;
        if ($data === null) {
            return false;
        }

        // Verificar que el evento corresponde a un pago nuestro (tiene metadata product)
        $metadata = $this->normalizeMetadata($data->metadata ?? []);
        if (empty($metadata['product'] ?? '')) {
            return false;
        }

        // Verificar importe y moneda contra el registro existente si existe
        $existing = $this->paymentService->findByExternalId('stripe', $paymentEvent->externalId);
        if ($existing !== null) {
            if ($existing->currency !== $paymentEvent->currency) {
                return false;
            }
            // Comparación numérica de importe para evitar problemas de formato decimal
            if ((float) $existing->amount !== (float) $paymentEvent->amount) {
                return false;
            }
            if ($existing->product !== $paymentEvent->product) {
                return false;
            }
            // company_id: BD guarda int, metadata de Stripe trae string; normalizar a string
            if ((string) $existing->company_id !== (string) $paymentEvent->companyId && $paymentEvent->companyId !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Convierte metadata de Stripe (StripeObject, array, stdClass) a array plano.
     */
    private function normalizeMetadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }
        if (is_object($metadata)) {
            if (method_exists($metadata, 'toArray')) {
                return $metadata->toArray();
            }
            if (method_exists($metadata, 'jsonSerialize')) {
                return $metadata->jsonSerialize();
            }
            return (array) $metadata;
        }
        return [];
    }

    private function findByStripeEventId(string $stripeEventId): ?\App\Models\BillingPaymentRecord
    {
        return \App\Models\BillingPaymentRecord::query()
            ->where('stripe_event_id', $stripeEventId)
            ->first();
    }
}