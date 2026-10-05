<?php

namespace App\Services\Fiscal;

use App\Contracts\Fiscal\FiscalCabysCatalogInterface;
use App\DTOs\Fiscal\FiscalCabysEntry;
use App\DTOs\Fiscal\FiscalCabysSearchResult;
use App\Services\Cabys\LocalCabysCatalog as VersionedCabysCatalog;

/**
 * Adaptador fiscal del catálogo versionado compartido con productos/POS.
 */
class LocalCabysCatalog implements FiscalCabysCatalogInterface
{
    public function search(string $query, int $limit = 30): FiscalCabysSearchResult
    {
        $result = app(VersionedCabysCatalog::class)->search($query, $limit);

        return new FiscalCabysSearchResult(
            found: $result['found'],
            entries: array_map(fn (array $entry) => $this->toEntry($entry), $result['entries']),
        );
    }

    public function validate(string $code): ?FiscalCabysEntry
    {
        $row = app(VersionedCabysCatalog::class)->validate($code);

        return $row !== null ? $this->toEntry($row) : null;
    }

    private function toEntry(array $cabys): FiscalCabysEntry
    {
        return new FiscalCabysEntry(
            code: (string) $cabys['code'],
            description: $cabys['description'],
            taxRate: $cabys['tax_rate_pct'] !== null ? number_format($cabys['tax_rate_pct'], 2, '.', '') : null,
            active: true,
        );
    }
}
