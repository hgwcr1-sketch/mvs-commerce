<?php

namespace App\Console\Commands;

use App\Services\Loyalty\LoyaltyExpirationService;
use Illuminate\Console\Command;

class ExpireLoyaltyPoints extends Command
{
    protected $signature = 'loyalty:expire-points';
    protected $description = 'Vence automáticamente los puntos de fidelización según la inactividad configurada por empresa';

    public function handle(LoyaltyExpirationService $service): int
    {
        $result = $service->process();

        $this->info("Cuentas vencidas: {$result['expired_accounts']}; puntos vencidos: {$result['expired_points']}; omitidas: {$result['skipped']}");

        return self::SUCCESS;
    }
}
