<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('is_super_admin')->default(false)->after('is_active');
        });

        // Marcar roles existentes llamados "Administrador" como super admin,
        // preservando el comportamiento anterior sin depender del nombre en el futuro.
        DB::table('roles')
            ->where('name', 'Administrador')
            ->where('is_active', true)
            ->update(['is_super_admin' => true]);
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('is_super_admin');
        });
    }
};
