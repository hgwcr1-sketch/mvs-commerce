<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Emisión fiscal bloqueada ANTES del POST al proveedor: servicio fiscal
 * deshabilitado, tipo no consumible o cuota agotada sin excedentes.
 */
class FiscalQuotaException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason = 'denied',
    ) {
        parent::__construct($message);
    }
}
