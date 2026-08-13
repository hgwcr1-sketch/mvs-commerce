<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suspended_sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('suspended_sale_id')->constrained('suspended_sales')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->string('product_code', 100)->nullable();
            $table->string('barcode', 100)->nullable();
            $table->string('cabys_code', 20)->nullable();
            $table->string('description', 255);
            $table->string('unit_code', 20)->nullable();
            $table->decimal('quantity', 19, 4);
            $table->decimal('estimated_unit_price', 19, 4);
            $table->decimal('estimated_gross_total', 19, 4);
            $table->decimal('estimated_tax_rate', 19, 4);
            $table->decimal('estimated_tax_total', 19, 4);
            $table->decimal('estimated_total', 19, 4);
            $table->timestamps();
            $table->index(['suspended_sale_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suspended_sale_items');
    }
};
