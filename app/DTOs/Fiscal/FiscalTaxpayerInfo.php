<?php

namespace App\DTOs\Fiscal;

/**
 * Información fiscal neutral de un contribuyente, traducida desde el
 * proveedor de consulta. Las actividades económicas usan claves MVS
 * ("code"/"description"), nunca las claves propietarias del proveedor.
 */
final class FiscalTaxpayerInfo
{
    /**
     * @param  array<int, array{code: string, description: string}>  $activities
     */
    public function __construct(
        public readonly bool $found = false,
        public readonly ?bool $taxpayer = null,
        public readonly ?string $regimeCode = null,
        public readonly ?string $regimeKey = null,
        public readonly ?string $regimeDescription = null,
        public readonly bool $regimeSimplified = false,
        public readonly bool $regimeTransfersTax = false,
        public readonly array $activities = [],
        public readonly ?string $situation = null,
        public readonly ?FiscalError $error = null,
    ) {
    }
}
