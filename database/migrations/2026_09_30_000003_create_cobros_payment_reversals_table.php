<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3C CobrOS — anulaciones auditable de cobros.
 *
 * Una anulación nunca borra ni modifica el pago original: crea un reverso
 * único por pago (UNIQUE payment_id) con su propio número por empresa y su
 * propio token de idempotencia. Bloquea al nivel de base cualquier doble
 * anulación del mismo cobro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cobros_payment_reversals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('receipt_id')->constrained('cobros_receipts')->restrictOnDelete();
            $table->foreignId('payment_id')->unique()->constrained('accounts_receivable_payments')->restrictOnDelete();
            $table->foreignId('account_receivable_id')->constrained('accounts_receivable')->restrictOnDelete();
            $table->foreignId('collector_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('annulled_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('reversal_number', 40);
            $table->decimal('amount', 19, 4);
            $table->char('currency_code', 3);
            $table->decimal('balance_before', 19, 4);
            $table->decimal('balance_after', 19, 4);
            $table->string('reason', 250);
            $table->string('client_token', 64);
            $table->char('payload_hash', 64);
            $table->timestamp('annulled_at');
            $table->timestamps();

            $table->unique(['company_id', 'reversal_number']);
            $table->unique(['company_id', 'client_token']);
            $table->index(['company_id', 'annulled_at'], 'cobros_reversals_company_annulled_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobros_payment_reversals');
    }
};
