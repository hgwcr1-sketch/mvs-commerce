<?php

namespace App\Contracts\Fiscal;

use App\DTOs\Fiscal\FiscalTaxpayerInfo;

/**
 * Consulta de contribuyentes ante la administración tributaria.
 *
 * Contrato neutral MVS: la implementación actual puede ser un proveedor
 * externo (ej. FacturaEnCR) y en el futuro una consulta directa a
 * Hacienda, sin que los consumidores cambien. Cada implementación es
 * responsable de traducir su respuesta propietaria a FiscalTaxpayerInfo.
 */
interface FiscalTaxpayerLookupInterface
{
    public function lookup(string $identification): FiscalTaxpayerInfo;
}
