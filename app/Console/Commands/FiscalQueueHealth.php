<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Señales de estabilidad de la cola fiscal (queue=fiscal):
 * - tamaño de cola fiscal y job más antiguo;
 * - jobs fiscales pendientes > 2 minutos (worker fiscal caído/atascado);
 * - jobs fiscales fallidos;
 * - worker fiscal: deducido de la falta de avance de la cola.
 *
 * Sale con código 1 cuando hay alertas, para poder usarlo como healthcheck.
 */
class FiscalQueueHealth extends Command
{
    protected $signature = 'fiscal:queue-health {--json : Salida JSON para monitoreo}';

    protected $description = 'Estado de la cola fiscal: pendientes, atraso, fallidos y worker caído';

    private const STALE_MINUTES = 2;

    public function handle(): int
    {
        $pending = DB::table('jobs')->where('queue', 'fiscal');
        $pendingCount = (clone $pending)->count();
        $oldestAt = $pending->min('created_at');
        $oldestMinutes = $oldestAt === null ? 0 : round((time() - (int) $oldestAt) / 60, 1);

        $failedCount = DB::table('failed_jobs')
            ->where('payload', 'like', '%EmitElectronicDocument%')
            ->count();

        $alerts = [];
        if ($failedCount > 0) {
            $alerts[] = 'job_fiscal_fallido';
        }
        if ($pendingCount > 0 && $oldestMinutes >= self::STALE_MINUTES) {
            $alerts[] = 'worker_fiscal_caido_o_atrasado';
        }

        $report = [
            'pending_fiscal' => $pendingCount,
            'oldest_pending_minutes' => $oldestMinutes,
            'failed_fiscal' => $failedCount,
            'alerts' => $alerts,
            'checked_at' => now()->toIso8601String(),
        ];

        if ($alerts !== []) {
            Log::warning('fiscal.queue.alert', $report);
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(sprintf(
                'Cola fiscal: pendientes=%d, más antiguo=%s min, fallidos=%d, alertas=%s',
                $pendingCount,
                $oldestMinutes,
                $failedCount,
                $alerts === [] ? 'ninguna' : implode(',', $alerts),
            ));
        }

        return $alerts === [] ? self::SUCCESS : self::FAILURE;
    }
}
