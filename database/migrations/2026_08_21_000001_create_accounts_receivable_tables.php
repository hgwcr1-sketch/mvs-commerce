<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('accounts_receivable', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->unique()->constrained()->restrictOnDelete();
            $table->date('issued_at');
            $table->date('due_date');
            $table->decimal('original_amount', 19, 4);
            $table->decimal('balance_due', 19, 4);
            $table->string('status', 20);
            $table->char('currency_code', 3);
            $table->timestamps();
            $table->index(['company_id', 'branch_id', 'status', 'due_date'], 'ar_context_status_due_index');
        });

        Schema::create('accounts_receivable_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_receivable_id')->constrained('accounts_receivable')->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 19, 4);
            $table->boolean('affects_cash_snapshot');
            $table->decimal('cash_effect_amount', 19, 4);
            $table->string('reference', 150)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('paid_at');
            $table->timestamps();
            $table->index(['cash_session_id', 'payment_method_id'], 'ar_payments_session_method_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts_receivable_payments');
        Schema::dropIfExists('accounts_receivable');
    }
};
