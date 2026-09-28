<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suspended_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('suspension_number', 50);
            $table->string('status', 30)->default('suspended');
            $table->char('currency_code', 3)->default('CRC');
            $table->decimal('estimated_subtotal', 19, 4)->default(0);
            $table->decimal('estimated_tax_total', 19, 4)->default(0);
            $table->decimal('estimated_rounding_total', 19, 4)->default(0);
            $table->decimal('estimated_total', 19, 4)->default(0);
            $table->timestamp('suspended_at');
            $table->uuid('recovery_token')->nullable()->unique();
            $table->timestamp('recovery_started_at')->nullable();
            $table->foreignId('recovery_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('recovered_sale_id')->nullable()->unique()->constrained('sales')->restrictOnDelete();
            $table->timestamp('recovered_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'suspension_number']);
            $table->index(['company_id', 'branch_id', 'status', 'suspended_at'], 'suspended_sales_scope_index');
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suspended_sales');
    }
};
