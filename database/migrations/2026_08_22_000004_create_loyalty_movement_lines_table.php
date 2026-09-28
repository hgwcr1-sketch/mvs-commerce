<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_movement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loyalty_movement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('product_category_id')->nullable()->constrained('product_categories')->restrictOnDelete();
            $table->decimal('eligible_amount', 19, 4)->nullable();
            $table->decimal('earning_percentage', 19, 4)->nullable();
            $table->decimal('multiplier', 19, 4)->nullable();
            $table->decimal('points', 19, 4);
            $table->timestamps();

            $table->index(['loyalty_movement_id', 'sale_item_id'], 'loyalty_movement_lines_movement_sale_item_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_movement_lines');
    }
};
