<?php

namespace App\Services\Fiscal;

use App\Contracts\Fiscal\FiscalCabysCatalogInterface;
use App\DTOs\Fiscal\FiscalCabysEntry;
use App\DTOs\Fiscal\FiscalCabysSearchResult;
use App\Models\Cabys;

/**
 * Catálogo CABYS local MVS: consulta la tabla cabys cargada con el
 * catálogo oficial BCCR (CabysImporter / InstallCatalogs). Sin red y
 * sin participación de proveedores de emisión.
 */
class LocalCabysCatalog implements FiscalCabysCatalogInterface
{
    public function search(string $query, int $limit = 30): FiscalCabysSearchResult
    {
        $term = trim($query);

        if ($term === '') {
            return new FiscalCabysSearchResult(found: false);
        }

        $rows = Cabys::query()
            ->where('is_active', true)
            ->where(function ($builder) use ($term) {
                $builder->where('code', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%");
            })
            ->orderBy('code')
            ->limit(max(1, $limit))
            ->get();

        return new FiscalCabysSearchResult(
            found: $rows->isNotEmpty(),
            entries: $rows->map(fn (Cabys $cabys) => $this->toEntry($cabys))->all(),
        );
    }

    public function validate(string $code): ?FiscalCabysEntry
    {
        $row = Cabys::query()
            ->where('is_active', true)
            ->where('code', trim($code))
            ->first();

        return $row !== null ? $this->toEntry($row) : null;
    }

    private function toEntry(Cabys $cabys): FiscalCabysEntry
    {
        return new FiscalCabysEntry(
            code: (string) $cabys->code,
            description: $cabys->description,
            taxRate: $cabys->tax_rate !== null ? (string) $cabys->tax_rate : null,
            active: (bool) $cabys->is_active,
        );
    }
}
