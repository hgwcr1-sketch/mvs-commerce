<?php

namespace App\Services\Billing\Checkout;

/**
 * Respuesta de creación de sesión Checkout Stripe.
 *
 * Contiene solo lo necesario para redirigir al usuario al portal de Stripe.
 * NO expone secretos, claves API ni datos internos de validación.
 */
final class CheckoutSessionResponse
{
    public function __construct(
        public readonly string $sessionId,
        public readonly string $url,
        public readonly string $product,
        public readonly string $companyId,
        public readonly string $contractReference,
        public readonly string $currency,
        public readonly string $amount,
        public readonly ?int $periodYear = null,
        public readonly ?int $periodMonth = null,
        public readonly string $idempotencyKey,
        public readonly int $expiresAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'session_id' => $this->sessionId,
            'url' => $this->url,
            'product' => $this->product,
            'company_id' => $this->companyId,
            'contract_reference' => $this->contractReference,
            'currency' => $this->currency,
            'amount' => $this->amount,
            'period_year' => $this->periodYear,
            'period_month' => $this->periodMonth,
            'idempotency_key' => $this->idempotencyKey,
            'expires_at' => $this->expiresAt,
        ];
    }
}