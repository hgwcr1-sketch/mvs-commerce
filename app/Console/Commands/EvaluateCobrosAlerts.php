<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Cobros\CobrosAlertService;
use Illuminate\Console\Command;

/**
 * Fase 5A CobrOS — evalúa y despacha las alertas diarias de cada empresa:
 * promesas vencidas, clientes no localizados, custodia pendiente y licencia
 * próxima a vencer. Deduplicado: correrlo varias veces no duplica alertas.
 */
class EvaluateCobrosAlerts extends Command
{
    protected $signature = 'cobros:evaluate-alerts {--company= : Evalúa solo una empresa}';

    protected $description = 'Evalúa las alertas CobrOS diarias (promesas vencidas, no localizados, custodia y licencia) y las envía al Centro de Notificaciones';

    public function handle(CobrosAlertService $alerts): int
    {
        $query = Company::query()->where('is_active', true);
        if ($companyId = $this->option('company')) {
            $query->where('id', (int) $companyId);
        }

        $total = 0;
        foreach ($query->pluck('id') as $companyId) {
            $company = Company::query()->find($companyId);
            if (! $company) {
                continue;
            }
            $summary = $alerts->evaluate($company);
            $created = array_sum($summary);
            $total += $created;
            $this->line(sprintf('Empresa %d: %d alertas nuevas.', $company->id, $created));
        }

        $this->info(sprintf('Total de alertas CobrOS nuevas: %d.', $total));

        return self::SUCCESS;
    }
}
