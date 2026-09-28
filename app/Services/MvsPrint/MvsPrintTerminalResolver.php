<?php

namespace App\Services\MvsPrint;

use App\Models\MvsPrint\MvsPrintTerminal;

class MvsPrintTerminalResolver
{
    public function resolve(int $companyId, int $branchId, ?string $uuid): ?MvsPrintTerminal
    {
        $query = MvsPrintTerminal::query()->forCompany($companyId)->forBranch($branchId)->enabled();

        if ($uuid) {
            return $query->where('terminal_uuid', $uuid)->first();
        }

        // Sin vínculo local solo es seguro resolver una terminal inequívoca.
        $terminals = $query->whereNotNull('printer_name')->where('printer_name', '!=', '')->limit(2)->get();

        return $terminals->count() === 1 ? $terminals->first() : null;
    }
}
