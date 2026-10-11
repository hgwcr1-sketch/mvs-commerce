<?php

namespace App\Console\Commands;

use App\Services\Cobros\CobrosDemoScenario;
use App\Services\DemoCompanyProvisioner;
use Illuminate\Console\Command;
use Throwable;

/**
 * BLOQUE 21D-2 — generación/restablecimiento explícito del escenario DEMO CobrOS.
 *
 * Restricciones dobles: solo la empresa DEMO (DemoCompanyProvisioner) y solo
 * entornos local/testing (validado dentro de CobrosDemoScenario). No imprime
 * credenciales y no opera sobre bases ajenas ni sobre producción.
 */
class DemoCobros extends Command
{
    protected $signature = 'demo:cobros
        {--reset : Elimina SOLO las filas del escenario CobrOS de la empresa DEMO}
        {--force : Omitir la confirmación del reset}';

    protected $description = 'Genera o restablece el escenario DEMO de CobrOS (tres modalidades) con datos sintéticos.';

    public function handle(CobrosDemoScenario $scenario, DemoCompanyProvisioner $provisioner): int
    {
        $company = $provisioner->findDemoCompany();
        if ($company === null) {
            $this->error('No existe la empresa DEMO. Ejecute primero: php artisan demo:company');

            return self::FAILURE;
        }

        if ($this->option('reset') && ! $this->option('force') && ! $this->confirm(
            "Restablecer SOLO el escenario CobrOS de {$company->trade_name} (ID {$company->id})?",
            false
        )) {
            $this->warn('Reset cancelado. No se modificó nada.');

            return self::SUCCESS;
        }

        try {
            $summary = $this->option('reset')
                ? $scenario->reset($company)
                : $scenario->seed($company);
        } catch (Throwable $e) {
            $this->error('Operación no permitida: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('reset')) {
            $this->info('Escenario CobrOS restablecido (solo filas DEMO):');
            $this->table(['Elemento', 'Eliminados'], collect($summary)->map(fn ($v, $k) => [$k, $v])->values()->all());

            return self::SUCCESS;
        }

        $this->info('Escenario CobrOS '.($summary['created'] ?? false) ? 'creado' : 'ya existía (idempotente)');
        $this->table(
            ['Elemento', 'Cantidad'],
            collect($summary)->filter(fn ($v, $k) => $k !== 'created')->map(fn ($v, $k) => [$k, $v])->values()->all()
        );
        $this->line('Usuarios del escenario: dominio sintético '.CobrosDemoScenario::USER_DOMAIN.' (sin credenciales mostradas).');

        return self::SUCCESS;
    }
}
