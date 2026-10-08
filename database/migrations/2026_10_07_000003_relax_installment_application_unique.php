<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 9B.2A - un mismo recibo puede aplicarse a varias cuotas
 * (pago adelantado distribuido en orden cronológico).
 * UNIQUE pasa de receipt_id a (receipt_id, installment_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cobros_credit_installment_applications', function (Blueprint $table) {
            $table->dropUnique(['cobros_receipt_id']);
        });

        Schema::table('cobros_credit_installment_applications', function (Blueprint $table) {
            $table->unique(['cobros_receipt_id', 'installment_id']);
        });
    }

    public function down(): void
    {
        Schema::table('cobros_credit_installment_applications', function (Blueprint $table) {
            $table->dropUnique(['cobros_receipt_id', 'installment_id']);
        });

        Schema::table('cobros_credit_installment_applications', function (Blueprint $table) {
            $table->unique(['cobros_receipt_id']);
        });
    }
};
