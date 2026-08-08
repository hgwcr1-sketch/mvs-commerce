<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Permission;
use App\Models\User;
use App\Services\CompanyProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class InstallMvsCommerce extends Command
{
    protected $signature = 'mvs:install
        {--admin-name= : Nombre del administrador inicial}
        {--admin-email= : Correo del administrador inicial}
        {--company-name= : Nombre comercial de la empresa inicial}
        {--branch-name=Principal : Nombre de la sucursal inicial}
        {--branch-code=PRINCIPAL : Código de la sucursal inicial}';

    protected $description = 'Provisiona el administrador, empresa, rol y sucursal iniciales de MVS Commerce.';

    public function handle(CompanyProvisioner $provisioner): int
    {
        if (Company::query()->exists()) {
            $this->error('La instalación inicial ya fue realizada. No se crearán empresas adicionales.');

            return self::FAILURE;
        }

        if (! Permission::query()->where('is_active', true)->exists()) {
            $this->error('No hay permisos globales disponibles. Ejecute primero los seeders de catálogos y permisos.');

            return self::FAILURE;
        }

        $name = $this->option('admin-name') ?: $this->ask('Nombre del administrador');
        $email = $this->option('admin-email') ?: $this->ask('Correo del administrador');
        $password = $this->secret('Contraseña del administrador');
        $companyName = $this->option('company-name') ?: $this->ask('Nombre comercial de la empresa');
        $branchName = $this->option('branch-name');
        $branchCode = $this->option('branch-code');

        $validator = Validator::make([
            'admin_name' => $name,
            'admin_email' => $email,
            'password' => $password,
            'company_name' => $companyName,
            'branch_name' => $branchName,
            'branch_code' => $branchCode,
        ], [
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8'],
            'company_name' => ['required', 'string', 'max:150'],
            'branch_name' => ['required', 'string', 'max:255'],
            'branch_code' => ['required', 'string', 'max:50'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->error('Ya existe una cuenta con ese correo. La instalación inicial no adjunta cuentas existentes.');

            return self::FAILURE;
        }

        $company = $provisioner->install(
            [
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ],
            ['trade_name' => $companyName],
            $branchName,
            $branchCode,
        );

        $this->info("Instalación inicial completada para {$company->trade_name}.");

        return self::SUCCESS;
    }
}
