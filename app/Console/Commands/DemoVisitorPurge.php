<?php

namespace App\Console\Commands;

use App\Services\Cobros\CobrosDemoSessionService;
use Illuminate\Console\Command;

/**
 * BLOQUE 21D-4A — limpieza exclusiva de sesiones/empresas DEMO de visitantes
 * vencidas. No toca empresas reales ni el DEMO single-tenant previo.
 */
class DemoVisitorPurge extends Command
{
    protected $signature = 'demo:visitor-purge {--dry-run : Solo cuenta sin eliminar}';

    protected $description = 'Elimina sesiones DEMO de visitantes vencidas y sus empresas-demo aisladas.';

    public function handle(CobrosDemoSessionService $service): int
    {
        if ($this->option('dry-run')) {
            $count = \App\Models\CobrosDemoSession::query()->where('expires_at', '<=', now())->count();
            $this->info("Sesiones DEMO vencidas: {$count}");
            return self::SUCCESS;
        }

        $removed = $service->purgeExpired();
        $this->info("Sesiones DEMO vencidas eliminadas: {$removed}");

        return self::SUCCESS;
    }
}
