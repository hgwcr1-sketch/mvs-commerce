<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sale_id')
                ->constrained('sales')
                ->cascadeOnDelete();

            $table->foreignId('product_id')
                ->nullable()
                ->constrained('products')
                ->restrictOnDelete();

            $table->string('product_code', 100)->nullable();
            $table->string('barcode', 100)->nullable();
            $table->string('cabys_code', 20)->nullable();
            $table->string('description', 255);
            $table->string('unit_code', 20)->nullable();
            $table->decimal('quantity', 19, 4);
            $table->decimal('unit_price', 19, 4);
            $table->decimal('gross_total', 19, 4);
            $table->decimal('discount_total', 19, 4)->default(0);
            $table->decimal('subtotal', 19, 4);
            $table->decimal('tax_rate', 19, 4)->default(0);
            $table->decimal('tax_total', 19, 4)->default(0);
            $table->decimal('total', 19, 4);
            $table->decimal('unit_cost', 19, 4)->default(0);
            $table->timestamps();

            $table->index(
                ['sale_id', 'product_id'],
                'sale_items_sale_product_index'
            );
            $table->index('product_code', 'sale_items_product_code_index');
            $table->index('barcode', 'sale_items_barcode_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};
