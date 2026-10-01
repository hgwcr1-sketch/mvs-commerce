<?php

namespace App\DTOs\Fiscal;

/**
 * Entrada neutral del catálogo CABYS (datos MVS/BCCR, no del proveedor).
 */
final class FiscalCabysEntry
{
    public function __construct(
        public readonly string $code,
        public readonly ?string $description = null,
        public readonly ?string $taxRate = null,
        public readonly bool $active = true,
    ) {
    }
}
