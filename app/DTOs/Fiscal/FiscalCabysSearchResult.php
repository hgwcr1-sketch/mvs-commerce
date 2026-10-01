<?php

namespace App\DTOs\Fiscal;

/**
 * Resultado neutral de una búsqueda en el catálogo CABYS.
 */
final class FiscalCabysSearchResult
{
    /**
     * @param  FiscalCabysEntry[]  $entries
     */
    public function __construct(
        public readonly bool $found,
        public readonly array $entries = [],
    ) {
    }
}
