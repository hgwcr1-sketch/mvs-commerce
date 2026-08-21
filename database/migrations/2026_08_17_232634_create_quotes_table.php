<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->foreignId('user_id')->constrained();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('quote_number', 20)->unique();
            $table->string('status')->default('active');
            $table->boolean('converted')->default(false);
            $table->unsignedBigInteger('converted_sale_id')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->boolean('cancelled')->default(false);
            $table->boolean('cancellation_enabled')->default(true);
            $table->string('cancellation_reason')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('subtotal', 14, 4)->default(0);
            $table->decimal('discount_total', 14, 4)->default(0);
            $table->decimal('tax_total', 14, 4)->default(0);
            $table->decimal('total', 14, 4)->default(0);
            $table->timestamps();

            $table->index('company_id');
            $table->index('branch_id');
            $table->index('status');
            $table->index(['company_id', 'branch_id', 'status']);
            $table->foreign('converted_sale_id')->references('id')->on('sales');
            $table->foreign('cancelled_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};
