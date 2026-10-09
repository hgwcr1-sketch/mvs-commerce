<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** 13-1B — resumen numérico del aviso para cotejo con el sistema anterior (aditiva y reversible). */
    public function up(): void
    {
        Schema::table('cobros_migration_notices', function (Blueprint $table) {
            $table->json('summary')->nullable()->after('details');
        });
    }

    public function down(): void
    {
        Schema::table('cobros_migration_notices', function (Blueprint $table) {
            $table->dropColumn('summary');
        });
    }
};
