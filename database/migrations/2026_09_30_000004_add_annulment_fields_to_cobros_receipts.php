<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3C CobrOS — estado de anulación visible en el recibo.
 * El pago original queda intacto; solo el recibo muestra si fue anulado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cobros_receipts', function (Blueprint $table) {
            $table->timestamp('annulled_at')->nullable()->after('paid_at');
            $table->foreignId('annulled_by_user_id')->nullable()->after('annulled_at')
                ->constrained('users')->restrictOnDelete();
            $table->string('annul_reason', 250)->nullable()->after('annulled_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('cobros_receipts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('annulled_by_user_id');
            $table->dropColumn(['annulled_at', 'annul_reason']);
        });
    }
};
