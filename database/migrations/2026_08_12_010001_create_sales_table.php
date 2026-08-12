<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->restrictOnDelete();

            $table->foreignId('branch_id')
                ->constrained('branches')
                ->restrictOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('customer_id')
                ->nullable()
                ->constrained('customers')
                ->restrictOnDelete();

            $table->string('sale_number', 50);
            $table->string('document_type', 30)
                ->default('electronic_ticket');
            $table->string('sale_condition', 20)->default('cash');
            $table->string('status', 30)->default('draft');
            $table->char('currency_code', 3)->default('CRC');
            $table->decimal('exchange_rate', 19, 4)->default(1);
            $table->decimal('subtotal', 19, 4)->default(0);
            $table->decimal('discount_total', 19, 4)->default(0);
            $table->decimal('tax_total', 19, 4)->default(0);
            $table->decimal('total', 19, 4)->default(0);
            $table->decimal('paid_total', 19, 4)->default(0);
            $table->decimal('balance_due', 19, 4)->default(0);
            $table->date('due_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->foreignId('voided_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(
                ['company_id', 'sale_number'],
                'sales_company_number_unique'
            );

            $table->index(
                ['company_id', 'branch_id', 'status'],
                'sales_company_branch_status_index'
            );

            $table->index(
                ['company_id', 'customer_id', 'status'],
                'sales_company_customer_status_index'
            );

            $table->index(
                ['company_id', 'document_type', 'created_at'],
                'sales_company_document_created_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
