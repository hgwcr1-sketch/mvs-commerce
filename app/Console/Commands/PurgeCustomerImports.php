<?php

namespace App\Console\Commands;

use App\Models\CustomerImportRun;
use App\Services\Imports\CustomerImportRunService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PurgeCustomerImports extends Command
{
    protected $signature = 'customers:purge-imports {--company= : Empresa obligatoria}';

    protected $description = 'Purga archivos y detalles temporales de importaciones con más de 30 días';

    public function handle(CustomerImportRunService $service): int
    {
        $companyId = (int) $this->option('company');
        if ($companyId < 1) {
            $this->error('Indique --company con la empresa que desea limpiar.');

            return self::FAILURE;
        }
        $count = 0;
        CustomerImportRun::where('company_id', $companyId)->whereNull('purged_at')
            ->where('updated_at', '<=', now()->subDays(30))->chunkById(100, function ($runs) use ($service, $companyId, &$count) {
                foreach ($runs as $run) {
                    $count += (int) $service->purge($run->id, $companyId);
                }
            });
        $this->info("Detalles temporales purgados: {$count}");

        return self::SUCCESS;
    }
}
