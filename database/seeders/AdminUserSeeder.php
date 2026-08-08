<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Seeder exclusivo para desarrollo o demostraciones locales.
     *
     * Las instalaciones reales deben usar mvs:install para crear el
     * administrador inicial con credenciales proporcionadas en ese momento.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('AdminUserSeeder solo está disponible en entornos local o testing.');

            return;
        }

        User::updateOrCreate(

            [
                'email' => 'admin@mvscommerce.com',
            ],

            [
                'name' => 'Administrador',

                'password' => Hash::make('Admin123*'),

                'phone' => '00000000',

                'is_active' => true,
            ]

        );
    }
}
