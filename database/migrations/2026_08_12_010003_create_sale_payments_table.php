<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sale_id')
                ->constrained('sales')
                ->cascadeOnDelete();

            $table->foreignId('payment_method_id')
                ->constrained('payment_methods')
                ->restrictOnDelete();

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->decimal('amount', 19, 4);
            $table->decimal('received_amount', 19, 4)->default(0);
            $table->decimal('change_amount', 19, 4)->default(0);
            $table->string('reference', 150)->nullable();
            $table->string('status', 20)->default('completed');

            $table->foreignId('voided_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            $table->index(
                ['sale_id', 'status'],
                'sale_payments_sale_status_index'
            );

            $table->index(
                ['payment_method_id', 'created_at'],
                'sale_payments_method_created_index'
            );

            $table->index('reference', 'sale_payments_reference_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_payments');
    }
};
