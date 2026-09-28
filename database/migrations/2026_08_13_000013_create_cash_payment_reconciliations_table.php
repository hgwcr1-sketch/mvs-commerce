<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_payment_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_session_id')->constrained('cash_sessions')->cascadeOnDelete();
            $table->foreignId('payment_method_id')->constrained('payment_methods')->restrictOnDelete();
            $table->decimal('expected_amount', 19, 4);
            $table->decimal('reported_amount', 19, 4);
            $table->decimal('difference_amount', 19, 4);
            $table->string('reference', 150)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('reconciled_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('reconciled_at');
            $table->timestamps();
            $table->unique(['cash_session_id', 'payment_method_id'], 'cash_payment_reconciliations_session_method_unique');
            $table->index(['cash_session_id', 'reconciled_at'], 'cash_payment_reconciliations_session_reconciled_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_payment_reconciliations');
    }
};
