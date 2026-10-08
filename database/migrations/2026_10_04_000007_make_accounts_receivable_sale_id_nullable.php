<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Créditos migrados (cartera inicial 5B): la CxC nace de un dato declarado,
 * sin venta asociada. sale_id pasa a ser nullable (aditivo, no destructivo,
 * mismo patrón que cash_session_id). Evita inventar ventas is_historical que
 * contaminarían los reportes de ingresos. Ventas reales conservan su FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts_receivable', function (Blueprint $table) {
            $table->foreignId('sale_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('accounts_receivable', function (Blueprint $table) {
            $table->foreignId('sale_id')->nullable(false)->change();
        });
    }
};