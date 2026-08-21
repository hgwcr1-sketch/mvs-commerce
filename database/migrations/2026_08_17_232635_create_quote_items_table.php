<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('quote_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('product_code')->nullable();
            $table->string('barcode')->nullable();
            $table->string('cabys_code')->nullable();
            $table->string('description', 255)->nullable();
            $table->string('unit_code', 10)->nullable();
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_price', 14, 4);
            $table->decimal('gross_total', 14, 4);
            $table->decimal('discount_total', 14, 4)->default(0);
            $table->decimal('subtotal', 14, 4);
            $table->decimal('tax_rate', 6, 4)->default(0);
            $table->decimal('tax_total', 14, 4)->default(0);
            $table->decimal('total', 14, 4);
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->timestamps();

            $table->index('quote_id');
            $table->index('product_id');
            $table->foreign('product_id')->references('id')->on('products');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_items');
    }
};