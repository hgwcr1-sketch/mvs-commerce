<?php

namespace App\Services\Billing\Checkout;

use App\Services\Billing\DTOs\PaymentRequest;

/**
 * Solicitud genérica para crear una sesión de Checkout Stripe.
 *
 * A diferencia de PaymentRequest, esta solicitud NO acepta importe/moneda
 * desde el cliente: obtiene todo desde una cotización interna validada.
 */
final class CheckoutSessionRequest
{
    public function __construct(
        public readonly string $product,
        public readonly string $companyId,
        public readonly string $contractReference,
        public readonly string $successUrl,
        public readonly string $cancelUrl,
        public readonly ?int $periodYear = null,
        public readonly ?int $periodMonth = null,
        public readonly array $metadata = [],
        public readonly string $idempotencyKey = '',
    ) {
    }

    /**
     * Construye la solicitud desde un PaymentRequest validado + URLs.
     *
     * @param array<string, mixed> $metadata
     */
    public static function fromPaymentRequest(
        PaymentRequest $request,
        string $successUrl,
        string $cancelUrl,
        array $metadata = [],
        string $idempotencyKey = '',
    ): self {
        return new self(
            product: $request->product,
            companyId: $request->companyId,
            contractReference: $request->contractReference,
            successUrl: $successUrl,
            cancelUrl: $cancelUrl,
            periodYear: $request->periodYear,
            periodMonth: $request->periodMonth,
            metadata: array_merge($request->metadata, $metadata),
            idempotencyKey: $idempotencyKey,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'product' => $this->product,
            'company_id' => $this->companyId,
            'contract_reference' => $this->contractReference,
            'period_year' => $this->periodYear,
            'period_month' => $this->periodMonth,
            'success_url' => $this->successUrl,
            'cancel_url' => $this->cancelUrl,
            'metadata' => $this->metadata,
            'idempotency_key' => $this->idempotencyKey,
        ];
    }
}