<?php

namespace App\Services\Billing\DTOs;

use App\Services\Billing\Contracts\PaymentEventTypes;

/**
 * BLOQUE 17B-1 — evento normalizado de pago (independiente de CobrOS).
 *
 * Representa un cambio de estado (pendiente, confirmado, fallido,
 * reembolsado, renovación) con toda la información necesaria para que el
 * módulo consumidor actúe sin conocer al proveedor.
 */
final class PaymentEvent
{
    public function __construct(
        public readonly string $type,
        public readonly string $provider,
        public readonly string $externalId,
        public readonly string $product,
        public readonly string $companyId,
        public readonly string $contractReference,
        public readonly string $currency,
        public readonly string $amount,
        public readonly ?int $periodYear = null,
        public readonly ?int $periodMonth = null,
        public readonly array $rawPayload = [],
    ) {
        if (! PaymentEventTypes::isValid($type)) {
            throw new \InvalidArgumentException("Tipo de evento de pago desconocido: {$type}");
        }
    }

    public function isConfirmed(): bool
    {
        return $this->type === PaymentEventTypes::CONFIRMED;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'provider' => $this->provider,
            'external_id' => $this->externalId,
            'product' => $this->product,
            'company_id' => $this->companyId,
            'contract_reference' => $this->contractReference,
            'currency' => $this->currency,
            'amount' => $this->amount,
            'period_year' => $this->periodYear,
            'period_month' => $this->periodMonth,
        ];
    }
}
