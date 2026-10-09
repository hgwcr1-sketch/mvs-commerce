<?php

namespace App\Services\Billing\Adapters;

use App\Services\Billing\Contracts\PaymentProviderInterface;
use App\Services\Billing\DTOs\PaymentEvent;
use App\Services\Billing\DTOs\PaymentRequest;
use App\Services\Billing\Exceptions\PaymentProviderUnavailableException;

/**
 * BLOQUE 17B-1 — adaptador Stripe (stub, sin SDK real).
 *
 * La integración real llegará en 17B-2 con webhook firmado. Mientras tanto,
 * isAvailable() es false y los métodos operativos lanzan la excepción
 * explícita: NUNCA simulan éxito ni crean registros.
 */
class StripePaymentProvider implements PaymentProviderInterface
{
    public function key(): string
    {
        return 'stripe';
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
