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
    public function __construct(
        public readonly Sale $sale,
        public readonly Company $company,
        public readonly Customer $customer,
        public readonly array $saleItems,
        public readonly ?SalePayment $salePayment = null,
    ) {
    }
}
