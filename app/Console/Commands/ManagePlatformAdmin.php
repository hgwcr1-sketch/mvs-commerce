<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class ManagePlatformAdmin extends Command
{
    protected $signature = 'platform:admin
        {email : Correo de la cuenta}
        {--create : Crear una cuenta de plataforma independiente}
        {--revoke : Retirar acceso maestro}';

    protected $description = 'Concede o retira acceso al Panel Maestro MVS';

    public function handle(): int
    {
        if ($this->option('create') && $this->option('revoke')) {
            $this->error('Las opciones --create y --revoke no pueden usarse juntas.');

            return self::FAILURE;
        }

        if ($this->option('create')) {
            return $this->createPlatformAdministrator();
        }

        $user = User::query()->where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error('No existe un usuario con ese correo.');

            return self::FAILURE;
        }

        $enabled = ! $this->option('revoke');

        if ($enabled && $user->companies()->exists()) {
            $this->error('Una cuenta asociada a una empresa debe conservar acceso tenant y no puede convertirse en administradora de plataforma.');

            return self::FAILURE;
        }

        $user->update(['is_platform_admin' => $enabled]);
        $this->info($enabled ? 'Acceso maestro concedido.' : 'Acceso maestro retirado.');

        return self::SUCCESS;
    }

    private function createPlatformAdministrator(): int
    {
        $data = [
            'email' => $this->argument('email'),
            'name' => $this->ask('Nombre de la persona administradora'),
            'password' => $this->secret('Contraseña'),
            'password_confirmation' => $this->secret('Confirme la contraseña'),
        ];

        $validator = Validator::make($data, [
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->mixedCase()->numbers()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'is_active' => true,
            'is_platform_admin' => true,
        ]);

        $this->info('Cuenta de plataforma creada.');

        return self::SUCCESS;
    }
}
