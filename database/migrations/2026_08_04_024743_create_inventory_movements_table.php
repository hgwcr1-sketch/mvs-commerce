<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {

            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('branch_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('product_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // entry, exit, adjustment, transfer_in, transfer_out, sale, return, purchase
            $table->string('type', 30);

            $table->decimal('quantity', 14, 2);

            $table->decimal('previous_stock', 14, 2)->default(0);

            $table->decimal('new_stock', 14, 2)->default(0);

            $table->string('reason')->nullable();

            $table->string('reference_type')->nullable();

            $table->unsignedBigInteger('reference_id')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index([
                'company_id',
                'branch_id',
                'product_id'
            ]);

            $table->index([
                'reference_type',
                'reference_id'
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};