<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->restrictOnDelete();

            $table->foreignId('branch_id')
                ->constrained('branches')
                ->restrictOnDelete();

            $table->foreignId('customer_id')
                ->constrained('customers')
                ->restrictOnDelete();

            $table->foreignId('sale_id')
                ->constrained('sales')
                ->restrictOnDelete();

            $table->foreignId('sale_return_id')
                ->unique('credit_notes_sale_return_unique')
                ->constrained('sale_returns')
                ->restrictOnDelete();

            $table->string('credit_note_number', 50);
            $table->char('currency_code', 3);
            $table->decimal('issued_amount', 19, 4);
            $table->decimal('applied_amount', 19, 4)->default(0);
            $table->decimal('balance', 19, 4);
            $table->string('status', 30)->default('issued');
            $table->string('reason', 255);
            $table->foreignId('issued_by')
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamp('issued_at');
            $table->foreignId('voided_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->boolean('requires_ar_review')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(
                ['company_id', 'credit_note_number'],
                'credit_notes_company_number_unique'
            );

            $table->unique(
                ['company_id', 'idempotency_key'],
                'credit_notes_company_idempotency_unique'
            );

            $table->index(
                ['company_id', 'customer_id', 'status'],
                'credit_notes_company_customer_status_index'
            );

            $table->index(
                ['company_id', 'sale_id'],
                'credit_notes_company_sale_index'
            );

            $table->index(
                ['company_id', 'branch_id', 'status'],
                'credit_notes_company_branch_status_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_notes');
    }
};
