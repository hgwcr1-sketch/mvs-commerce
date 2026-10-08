<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CobrOS — datos del recibo formal: saldo anterior/nuevo y, en efectivo,
 * "paga con" / vuelto. Solo informativo: ninguna columna participa en el
 * cálculo de dinero de CxC, pagos ni caja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cobros_receipts', function (Blueprint $table) {
            $table->decimal('balance_before', 19, 4)->nullable()->after('amount');
            $table->decimal('balance_after', 19, 4)->nullable()->after('balance_before');
            $table->decimal('tendered_amount', 19, 4)->nullable()->after('balance_after');
            $table->decimal('change_amount', 19, 4)->nullable()->after('tendered_amount');
        });
    }

    public function down(): void
    {
        Schema::table('cobros_receipts', function (Blueprint $table) {
            $table->dropColumn(['balance_before', 'balance_after', 'tendered_amount', 'change_amount']);
        });
    }
};
