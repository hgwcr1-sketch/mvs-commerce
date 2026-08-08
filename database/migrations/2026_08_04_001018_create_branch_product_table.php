<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_product', function (Blueprint $table) {
            $table->id();

            $table->foreignId('branch_id')
                ->constrained('branches')
                ->cascadeOnDelete();

            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();

            $table->decimal('stock', 15, 4)->default(0);

            $table->decimal('minimum_stock', 15, 4)
                ->nullable();

            $table->decimal('maximum_stock', 15, 4)
                ->nullable();

            $table->timestamps();

            $table->unique([
                'branch_id',
                'product_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_product');
    }
};