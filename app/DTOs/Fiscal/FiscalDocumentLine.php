<?php

namespace App\DTOs\Fiscal;

/**
 * Línea fiscal congelada (snapshot histórico). Montos como cadenas decimales;
 * jamás floats. Los impuestos viajan ya resueltos (código/tarifa/factor).
 */
final class FiscalDocumentLine
{
    /**
     * @param array<int, array<string, mixed>> $taxes Impuestos congelados de la
     * línea (codigo, codigoTarifa, tarifa, factorIVA, exoneracion?, ...).
     */
    public function __construct(
        public readonly string $cabys,
        public readonly string $description,
        public readonly string $quantity,
        public readonly string $unitPrice,
        public readonly string $discountTotal,
        public readonly string $subtotal,
        public readonly array $taxes,
        public readonly string $taxTotal,
        public readonly string $total,
    ) {
    }
}
