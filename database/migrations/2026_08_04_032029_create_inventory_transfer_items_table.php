<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transfer_items', function (Blueprint $table) {

            $table->id();

            $table->foreignId('inventory_transfer_id')
                ->constrained('inventory_transfers')
                ->cascadeOnDelete();

            $table->foreignId('product_id')
                ->constrained()
                ->restrictOnDelete();

            $table->decimal('quantity', 14, 2);

            $table->decimal('from_previous_stock', 14, 2);
            $table->decimal('from_new_stock', 14, 2);

            $table->decimal('to_previous_stock', 14, 2);
            $table->decimal('to_new_stock', 14, 2);

            $table->timestamps();

            $table->unique([
                'inventory_transfer_id',
                'product_id'
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfer_items');
    }
};