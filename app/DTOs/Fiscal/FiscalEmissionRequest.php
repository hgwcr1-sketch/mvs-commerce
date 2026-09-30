<?php

namespace App\DTOs\Fiscal;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SalePayment;

/**
 * Solicitud MVS de emisión fiscal. No contiene nombres ni estructura
 * del payload de ningún proveedor en particular.
 */
final class FiscalEmissionRequest
{
    /**
     * @param  array  $saleItems  líneas de venta (SaleItem) ya normalizadas por MVS.
     */
    /**
     * @param array $saleItems líneas de venta (SaleItem) ya normalizadas por MVS.
     * @param ?FiscalDocument $adjustment documento modificador neutral (NC03/ND02).
     * Cuando está presente, el proveedor debe mapear desde él; la venta aporta
     * únicamente ámbito (empresa/sucursal) y compatibilidad hacia atrás.
     */
    public function __construct(
        public readonly Sale $sale,
        public readonly Company $company,
        public readonly Customer $customer,
        public readonly array $saleItems,
        public readonly ?SalePayment $salePayment = null,
        public readonly ?FiscalDocument $adjustment = null,
    ) {
    }
}
