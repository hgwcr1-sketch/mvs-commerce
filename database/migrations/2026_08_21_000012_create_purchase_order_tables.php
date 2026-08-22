<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('number', 50);
            $table->string('status', 30)->default('draft');
            $table->text('notes')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('prepared_at')->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason', 255)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'branch_id', 'status']);
            $table->index(['company_id', 'supplier_id', 'status']);
            $table->index(['requested_at', 'number']);
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description', 255);
            $table->string('supplier_product_code', 100)->nullable();
            $table->string('unit_code', 20);
            $table->decimal('requested_quantity', 19, 4);
            $table->decimal('ordered_quantity', 19, 4);
            $table->decimal('unit_cost_snapshot', 19, 4)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['purchase_order_id', 'product_id']);
            $table->index(['product_id', 'purchase_order_id']);
        });

        Schema::create('purchase_order_item_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->decimal('allocated_quantity', 19, 4);
            $table->timestamps();
            $table->unique(['purchase_order_item_id', 'order_item_id'], 'purchase_order_source_unique');
            $table->index(['order_item_id', 'allocated_quantity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_item_sources');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
    }
};
