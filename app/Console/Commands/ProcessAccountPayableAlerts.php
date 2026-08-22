<?php

namespace App\Console\Commands;

use App\Services\Purchases\AccountPayableAlertService;
use Illuminate\Console\Command;

class ProcessAccountPayableAlerts extends Command
{
    protected $signature='payables:alerts';
    protected $description='Genera alertas de cuentas por pagar próximas y vencidas';
    public function handle(AccountPayableAlertService $service): int { $this->info("Alertas CxP creadas: {$service->process()}"); return self::SUCCESS; }
}
