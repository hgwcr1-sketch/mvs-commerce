<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description', 255);
            $table->string('internal_code', 100)->nullable();
            $table->string('barcode', 100)->nullable();
            $table->string('unit_code', 20);
            $table->boolean('allows_decimals_snapshot')->default(false);
            $table->decimal('requested_quantity', 19, 4);
            $table->decimal('stock_snapshot', 19, 4);
            $table->decimal('sale_price_snapshot', 19, 4);
            $table->decimal('cost_snapshot', 19, 4)->nullable();
            $table->decimal('last_cost_snapshot', 19, 4)->nullable();
            $table->decimal('approved_quantity', 19, 4)->default(0);
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_status', 30)->default('pending');
            $table->text('request_note')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'product_id'], 'order_items_order_product_index');
            $table->index(['supplier_id', 'item_status'], 'order_items_supplier_status_index');
        });
    }

    public function down(): void { Schema::dropIfExists('order_items'); }
};
