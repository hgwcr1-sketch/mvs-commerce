<?php

namespace App\DTOs\Fiscal;

/**
 * Estado fiscal neutral de un documento, traducido desde el proveedor.
 */
final class FiscalDocumentStatus
{
    public function __construct(
        public readonly string $state,
        public readonly bool $final = false,
        public readonly ?string $providerReference = null,
        public readonly ?string $fiscalReference = null,
        public readonly ?string $message = null,
    ) {
    }
}
