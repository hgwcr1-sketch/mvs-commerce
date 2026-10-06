<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corte de inicio fiscal por empresa.
 *
 * Fecha a partir de la cual una venta puede emitirse electrónicamente.
 * Las ventas completadas antes de ese corte quedan FUERA del flujo
 * automático de forma permanente (doble gate: POS y job), protegiendo el
 * histórico pre-integración de cualquier emisión masiva accidental.
 * Sin valor no hay corte: el resto de gates siguen aplicando.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_fiscal_configs', function (Blueprint $table) {
            $table->timestamp('fiscal_start_at')->nullable()->after('auto_emit_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('company_fiscal_configs', function (Blueprint $table) {
            $table->dropColumn('fiscal_start_at');
        });
    }
};
