<?php

namespace App\Services\Billing\Adapters;

use App\Services\Billing\Contracts\PaymentProviderInterface;
use App\Services\Billing\DTOs\PaymentEvent;
use App\Services\Billing\DTOs\PaymentRequest;
use App\Services\Billing\Exceptions\PaymentProviderUnavailableException;

/**
 * BLOQUE 17B-1 — adaptador Fygaro (stub, sin integración real).
 *
 * Responde explícitamente "no disponible" sin simular éxito. Podrá usarse
 * como proveedor alternativo en Colombia u otros países.
 */
class FygaroPaymentProvider implements PaymentProviderInterface
{
    public function key(): string
    {
        return 'fygaro';
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function createPayment(PaymentRequest $request): PaymentEvent
    {
        throw PaymentProviderUnavailableException::notImplemented($this->key());
    }

    public function confirmPayment(string $externalId): PaymentEvent
    {
        throw PaymentProviderUnavailableException::notImplemented($this->key());
    }

    public function refundPayment(string $externalId): PaymentEvent
    {
        throw PaymentProviderUnavailableException::notImplemented($this->key());
    }
}
