<?php

namespace App\Services\Billing\Contracts;

use App\Services\Billing\DTOs\PaymentEvent;
use App\Services\Billing\DTOs\PaymentRequest;

/**
 * BLOQUE 17B-1 — contrato genérico de proveedor de pago MVS.
 *
 * Cada adaptador (Stripe, Tilopay, Fygaro, SINPE) implementa esta interfaz.
 * Los no integrados responden `isAvailable() === false` y lanzan
 * PaymentProviderUnavailableException en los métodos operativos: NUNCA
 * simulan éxito.
 */
interface PaymentProviderInterface
{
    /** Clave única del proveedor (ej: 'stripe', 'tilopay', 'sinpe'). */
    public function key(): string;

    /** true si el proveedor tiene integración real habilitada. */
    public function isAvailable(): bool;

    /**
     * Crea un pago y devuelve el evento normalizado (normalmente 'pending').
     *
     * @throws \App\Services\Billing\Exceptions\PaymentProviderUnavailableException
     */
    public function createPayment(PaymentRequest $request): PaymentEvent;

    /**
     * Confirma un pago por su identificador externo.
     *
     * @throws \App\Services\Billing\Exceptions\PaymentProviderUnavailableException
     */
    public function confirmPayment(string $externalId): PaymentEvent;

    /**
     * Reembolsa un pago por su identificador externo.
     *
     * @throws \App\Services\Billing\Exceptions\PaymentProviderUnavailableException
     */
    public function refundPayment(string $externalId): PaymentEvent;
}
