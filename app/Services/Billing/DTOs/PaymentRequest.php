<?php

namespace App\Services\Billing\DTOs;

/**
 * BLOQUE 17B-1 — solicitud genérica de pago (independiente de CobrOS).
 *
 * Identifica producto, empresa, referencia de contrato, moneda, importe y
 * período sin depender de modelos de un módulo concreto.
 */
final class PaymentRequest
{
    public function __construct(
        public readonly string $product,
        public readonly string $companyId,
        public readonly string $contractReference,
        public readonly string $currency,
        public readonly string $amount,
        public readonly ?int $periodYear = null,
        public readonly ?int $periodMonth = null,
        public readonly array $metadata = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'product' => $this->product,
            'company_id' => $this->companyId,
            'contract_reference' => $this->contractReference,
            'currency' => $this->currency,
            'amount' => $this->amount,
            'period_year' => $this->periodYear,
            'period_month' => $this->periodMonth,
            'metadata' => $this->metadata,
        ];
    }
}
