<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 12B-fix: variante de importación de clientes (commerce por defecto, cobros
 * simplificada). Aditivo y reversible; solo agrega una columna nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_import_runs', function (Blueprint $table) {
            $table->string('variant', 20)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('customer_import_runs', function (Blueprint $table) {
            $table->dropColumn('variant');
        });
    }
};
