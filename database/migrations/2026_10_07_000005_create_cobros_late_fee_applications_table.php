<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 9B.3 - mora cobrada: snapshot inmutable por aplicación (recibo + cuota).
 * Nunca se reescribe: cada fila registra el % efectivo vigente en ese cobro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cobros_late_fee_applications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('credit_id')->index();
            $table->unsignedBigInteger('installment_id')->index();
            $table->unsignedBigInteger('account_receivable_id')->index();
            $table->unsignedBigInteger('cobros_receipt_id');
            $table->unsignedBigInteger('collector_user_id');
            $table->decimal('late_fee_charged', 12, 4);
            $table->decimal('effective_pct', 5, 2);
            $table->decimal('collector_amount', 12, 4);
            $table->decimal('lender_amount', 12, 4);
            $table->timestamp('paid_at');
            $table->timestamps();

            $table->unique(['cobros_receipt_id', 'installment_id'], 'cfla_receipt_installment_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobros_late_fee_applications');
    }
};
