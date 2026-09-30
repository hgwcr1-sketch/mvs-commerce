<?php

namespace App\Contracts\Fiscal;

use App\DTOs\Fiscal\FiscalCabysEntry;
use App\DTOs\Fiscal\FiscalCabysSearchResult;

/**
 * Catálogo fiscal CABYS desde el punto de vista de MVS.
 *
 * El CABYS es un dato fiscal de Costa Rica (BCCR/Hacienda) gestionado
 * localmente por MVS (tabla cabys, cargada por CabysImporter /
 * InstallCatalogs). No depende de ningún proveedor de emisión: la
 * búsqueda y validación normales son locales y no hacen llamadas
 * remotas por cada consulta.
 */
interface FiscalCabysCatalogInterface
{
    public function search(string $query, int $limit = 30): FiscalCabysSearchResult;

    public function validate(string $code): ?FiscalCabysEntry;
}
