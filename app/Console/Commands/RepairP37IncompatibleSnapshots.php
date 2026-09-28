<?php

namespace App\Console\Commands;

use App\Services\Imports\RepairP37IncompatibleSnapshots as RepairService;
use Illuminate\Console\Command;

class RepairP37IncompatibleSnapshots extends Command
{
    protected $signature = 'loyalty:p37-repair-incompatible-snapshots
        {--company-id= : ID de empresa}
        {--source-key= : Source key P37}
        {--batch-id= : ID del batch}
        {--dry-run : Solo inspeccionar}
        {--apply : Aplicar la reparación}';

    protected $description = 'Revierte snapshots legacy incompatibles importados por el bug de revalidación P37';

    public function handle(RepairService $repair): int
    {
        if ($this->option('dry-run') && $this->option('apply')) {
            $this->error('Use --dry-run o --apply, no ambos.');

            return self::INVALID;
        }
        $companyId = filter_var($this->option('company-id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $batchId = filter_var($this->option('batch-id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $sourceKey = trim((string) $this->option('source-key'));
        if ($companyId === false || $batchId === false || $sourceKey === '') {
            $this->error('--company-id, --source-key y --batch-id son obligatorios.');

            return self::INVALID;
        }

        $result = $this->option('apply')
            ? $repair->apply($companyId, $sourceKey, $batchId)
            : $repair->inspect($companyId, $sourceKey, $batchId);
        $mode = $this->option('apply') ? 'APPLY' : 'DRY-RUN';
        $this->info("P37 {$mode}: {$result['candidates']} candidatos; ".($result['repaired'] ?? 0).' reparados.');
        $this->line('Filas: '.implode(', ', $result['row_numbers']));
        $this->line("Resultado: {$result['imported_count']} importados / {$result['pending_count']} pendientes.");

        return self::SUCCESS;
    }
}
