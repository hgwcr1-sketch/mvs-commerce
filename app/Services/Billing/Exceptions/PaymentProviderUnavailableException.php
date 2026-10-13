<?php

namespace App\Services\Billing\Exceptions;

use RuntimeException;

/**
 * BLOQUE 17B-1 — un proveedor no integrado o no disponible para el
 * producto/país solicitado. Los adaptadores NO simulan éxito: lanzan esta
 * excepción explícita para que el llamador sepa que no hay integración real.
 */
class PaymentProviderUnavailableException extends RuntimeException
{
    public static function forProvider(string $provider, string $reason = ''): self
    {
        $message = "El proveedor de pago «{$provider}» no está disponible.";
        if ($reason !== '') {
            $message .= ' '.$reason;
        }

        return new self($message);
    }

    public static function notImplemented(string $provider): self
    {
        return new self(
            "El proveedor «{$provider}» aún no tiene integración real habilitada; "
            .'no se procesa ningún pago.'
        );
    }
}
