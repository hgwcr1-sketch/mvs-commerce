<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3B CobrOS — custodia explícita.
 *
 * Los abonos registrados desde CobrOS no ingresan a una sesión de caja:
 * el efectivo queda en custodia del cobrador hasta una liquidación futura.
 * Por eso accounts_receivable_payments.cash_session_id pasa a ser nullable.
 * Es un cambio aditivo y no destructivo: los pagos de Commerce existentes
 * conservan su sesión de caja y sus expectativas de cierre no cambian.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts_receivable_payments', function (Blueprint $table) {
            $table->foreignId('cash_session_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('accounts_receivable_payments', function (Blueprint $table) {
            $table->foreignId('cash_session_id')->nullable(false)->change();
        });
    }
};
