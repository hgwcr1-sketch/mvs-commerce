<?php
namespace App\Console\Commands; use App\Services\Sales\LayawayService; use Illuminate\Console\Command;
class ProcessLayaways extends Command {protected $signature='layaways:process';protected $description='Vence apartados y genera alertas próximas';public function handle(LayawayService $service):int{$expired=$service->expireDue();$alerts=$service->createUpcomingAlerts();$this->info("Apartados vencidos: {$expired}; alertas creadas: {$alerts}");return self::SUCCESS;}}
