<?php

namespace App\Console\Commands;

use App\Services\DemoCompanyProvisioner;
use Illuminate\Console\Command;

class DemoCompany extends Command
{
    protected $signature = 'demo:company
        {--reset : Eliminar y recrear la empresa demo desde cero}
        {--force : Requiere --reset en producción}';

    protected $description = 'Crea o resetea la empresa demo de MVS Commerce con datos ficticios.';

    public function handle(DemoCompanyProvisioner $provisioner): int
    {
        if ($this->option('reset')) {
            return $this->reset($provisioner);
        }

        return $this->create($provisioner);
    }

    private function create(DemoCompanyProvisioner $provisioner): int
    {
        $existing = $provisioner->findDemoCompany();

        if ($existing) {
            $this->warn('La empresa demo ya existe (ID: ' . $existing->id . ').');
            $this->info('Use --reset para recrearla si es necesario.');

            return self::SUCCESS;
        }

        $this->info('Creando empresa demo...');

        $company = $provisioner->create();

        $this->newLine();
        $this->info('Empresa demo creada exitosamente.');
        $this->newLine();
        $this->table(['Campo', 'Valor'], [
            ['Empresa', $company->trade_name],
            ['ID', $company->id],
            ['Sucursales', $company->branches()->count()],
            ['Productos', $company->products()->count()],
            ['Clientes', $company->customers()->count()],
            ['Proveedores', \App\Models\Supplier::where('company_id', $company->id)->count()],
            ['Usuarios', $company->users()->count()],
        ]);
        $this->newLine();
        $this->info('Credenciales:');
        $this->line('  Email:    ' . config('demo.owner_email'));
        $this->line('  Password: ' . config('demo.owner_password'));

        return self::SUCCESS;
    }

    private function reset(DemoCompanyProvisioner $provisioner): int
    {
        if (! $this->option('force') && app()->environment('production')) {
            $this->error('En producción, use --force para resetear la empresa demo.');

            return self::FAILURE;
        }

        $company = $provisioner->findDemoCompany();

        if (! $company) {
            $this->warn('No existe empresa demo. Creando desde cero...');

            return $this->create($provisioner);
        }

        $this->warn('Esto eliminará TODOS los datos de la empresa demo:');
        $this->warn('  ' . $company->trade_name . ' (ID: ' . $company->id . ')');
        $this->newLine();

        if (! $this->option('force') && ! $this->confirm('¿Continuar con el reset?', false)) {
            $this->info('Reset cancelado.');

            return self::SUCCESS;
        }

        $this->info('Reseteando empresa demo...');

        $company = $provisioner->reset();

        $this->newLine();
        $this->info('Empresa demo reseteada exitosamente.');
        $this->newLine();
        $this->table(['Campo', 'Valor'], [
            ['Empresa', $company->trade_name],
            ['ID', $company->id],
            ['Sucursales', $company->branches()->count()],
            ['Productos', $company->products()->count()],
            ['Clientes', $company->customers()->count()],
            ['Usuarios', $company->users()->count()],
        ]);
        $this->newLine();
        $this->info('Credenciales restauradas:');
        $this->line('  Email:    ' . config('demo.owner_email'));
        $this->line('  Password: ' . config('demo.owner_password'));

        return self::SUCCESS;
    }
}
