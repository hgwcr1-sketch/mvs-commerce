<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
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