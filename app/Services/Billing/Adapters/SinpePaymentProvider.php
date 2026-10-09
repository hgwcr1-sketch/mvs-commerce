<?php

namespace App\Services\Billing\Adapters;

use App\Services\Billing\Contracts\PaymentProviderInterface;
use App\Services\Billing\DTOs\PaymentEvent;
use App\Services\Billing\DTOs\PaymentRequest;
use App\Services\Billing\Exceptions\PaymentProviderUnavailableException;

/**
 * BLOQUE 17B-1 — adaptador SINPE Móvil (stub, sin integración real).
 *
 * SINPE opera como pago pendiente de verificación manual (nunca activación
 * automática). Mientras no exista API, responde "no disponible" sin simular
 * éxito. La aprobación manual con permisos y auditoría llegará en 17B-2.
 */
class SinpePaymentProvider implements PaymentProviderInterface
{
    public function key(): string
    {
        return 'sinpe';
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
