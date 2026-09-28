<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts_payable', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_id')->unique()->constrained()->restrictOnDelete();
            $table->decimal('original_amount', 19, 4);
            $table->decimal('paid_amount', 19, 4)->default(0);
            $table->decimal('balance_due', 19, 4);
            $table->date('issue_date');
            $table->date('due_date');
            $table->string('status', 20)->default('pending');
            $table->char('currency_code', 3)->default('CRC');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'branch_id', 'status', 'due_date'], 'ap_context_status_due_index');
            $table->index(['company_id', 'supplier_id', 'status'], 'ap_company_supplier_status_index');
        });

        Schema::create('accounts_payable_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_payable_id')->constrained('accounts_payable')->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_session_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('amount', 19, 4);
            $table->boolean('affects_cash_snapshot')->default(false);
            $table->decimal('cash_effect_amount', 19, 4)->default(0);
            $table->string('reference', 150)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('paid_at');
            $table->timestamps();

            $table->index(['cash_session_id', 'payment_method_id'], 'ap_payments_session_method_index');
            $table->index(['company_id', 'branch_id', 'paid_at'], 'ap_payments_context_paid_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts_payable_payments');
        Schema::dropIfExists('accounts_payable');
    }
};
