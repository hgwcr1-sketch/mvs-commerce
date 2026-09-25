<?php

namespace App\Console\Commands;

use App\Services\Backups\CompanyBackupService;
use Illuminate\Console\Command;

class RunCompanyBackups extends Command
{
    protected $signature = 'backup:companies';

    protected $description = 'Ejecuta los backups automáticos de las empresas activas con el servicio habilitado';

    public function handle(CompanyBackupService $service): int
    {
        $settings = $service->dueSettings();

        if ($settings->isEmpty()) {
            $this->info('Sin empresas pendientes de backup.');

            return self::SUCCESS;
        }

        $success = 0;
        $errors = 0;

        foreach ($settings as $setting) {
            $company = $setting->company;
            if (! $company) {
                continue;
            }

            $record = $service->runBackup($company, 'scheduled');
            if ($record->status === 'success') {
                $success++;
                $this->line("OK empresa {$company->id} · {$company->trade_name}");
            } else {
                $errors++;
                $this->warn("ERROR empresa {$company->id} · {$company->trade_name} · {$record->message}");
            }
        }

        $this->info("Backups ejecutados: {$success} correctos, {$errors} con error.");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
