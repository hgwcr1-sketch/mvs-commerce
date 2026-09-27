<?php

namespace App\DTOs\Fiscal;

/**
 * Resultado neutral de verificar conexión con el proveedor (SIN emitir).
 * Solo conectividad + credenciales; jamás crea documentos ni consume cuota.
 */
final class FiscalConnectionResult
{
    public function __construct(
        public readonly bool $connected,
        public readonly ?string $errorCode = null,
        public readonly ?string $message = null,
    ) {
    }

    public static function ok(): self
    {
        return new self(true);
    }

    public static function failed(string $errorCode, string $message): self
    {
        return new self(false, $errorCode, $message);
    }
}
