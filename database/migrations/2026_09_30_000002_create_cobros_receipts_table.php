<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3B CobrOS — recibos de cobro e idempotencia de pago.
 *
 * Un recibo por pago confirmado, con número único por empresa y token de
 * cliente único por empresa: el mismo token con el mismo payload devuelve
 * el mismo pago; un payload distinto con el mismo token se rechaza.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cobros_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('account_receivable_id')->constrained('accounts_receivable')->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->unique()->constrained('accounts_receivable_payments')->restrictOnDelete();
            $table->foreignId('collector_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained('payment_methods')->restrictOnDelete();
            $table->foreignId('cobros_visit_id')->nullable()->constrained('cobros_visits')->restrictOnDelete();
            $table->string('receipt_number', 40)->nullable();
            $table->decimal('amount', 19, 4);
            $table->char('currency_code', 3);
            $table->string('reference', 150)->nullable();
            $table->string('client_token', 64);
            $table->char('payload_hash', 64);
            $table->timestamp('paid_at');
            $table->timestamps();

            $table->unique(['company_id', 'receipt_number']);
            $table->unique(['company_id', 'client_token']);
            $table->index(['company_id', 'collector_user_id', 'paid_at'], 'cobros_receipts_collector_paid_index');
            $table->index(['company_id', 'account_receivable_id'], 'cobros_receipts_company_account_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobros_receipts');
    }
};
