<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_counts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('branch_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->string('reference', 80)->nullable();

            $table->string('status', 20)->default('draft');

            $table->foreignId('started_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('counted_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('confirmed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('cancelled_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('started_at')->nullable();

            $table->timestamp('counted_at')->nullable();

            $table->timestamp('reviewed_at')->nullable();

            $table->timestamp('confirmed_at')->nullable();

            $table->timestamp('cancelled_at')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'branch_id', 'status']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('inventory_count_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('inventory_count_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('product_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->decimal('theoretical_quantity', 15, 4)->default(0);

            $table->decimal('counted_quantity', 15, 4)->nullable();

            $table->decimal('recount_quantity', 15, 4)->nullable();

            $table->decimal('final_quantity', 15, 4)->nullable();

            $table->decimal('difference', 15, 4)->default(0);

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['inventory_count_id', 'product_id']);
            $table->index(['inventory_count_id', 'difference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_count_items');
        Schema::dropIfExists('inventory_counts');
    }
};