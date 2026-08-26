<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class ManagePlatformAdmin extends Command
{
    protected $signature = 'platform:admin {email : Correo de una cuenta existente} {--revoke : Retirar acceso maestro}';

    protected $description = 'Concede o retira acceso al Panel Maestro MVS';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error('No existe un usuario con ese correo.');

            return self::FAILURE;
        }

        $enabled = ! $this->option('revoke');
        $user->update(['is_platform_admin' => $enabled]);
        $this->info($enabled ? 'Acceso maestro concedido.' : 'Acceso maestro retirado.');

        return self::SUCCESS;
    }
}
