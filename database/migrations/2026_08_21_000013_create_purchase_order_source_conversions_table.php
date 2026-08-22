<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_source_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_item_source_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_item_id')->constrained()->restrictOnDelete();
            $table->decimal('converted_quantity', 19, 4);
            $table->timestamps();
            $table->unique(['purchase_order_item_source_id', 'purchase_item_id'], 'purchase_order_source_purchase_item_unique');
            $table->index(['purchase_item_id', 'converted_quantity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_source_conversions');
    }
};
