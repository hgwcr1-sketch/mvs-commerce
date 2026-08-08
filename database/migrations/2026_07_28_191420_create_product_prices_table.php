<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_prices', function (Blueprint $table) {

            $table->id();

            $table->foreignId('product_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('price_list_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->decimal('price', 15, 2);

            // Para promociones por cantidad (futuro)
            $table->decimal('minimum_quantity', 15, 2)
                ->default(1);

            $table->boolean('is_active')
                ->default(true);

            $table->timestamps();

            // Evita duplicar la misma lista para un producto
            $table->unique(['product_id', 'price_list_id']);

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_prices');
    }
};