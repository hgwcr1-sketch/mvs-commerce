<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_lots', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->restrictOnDelete();

            $table->foreignId('branch_id')
                ->constrained('branches')
                ->restrictOnDelete();

            $table->foreignId('product_id')
                ->constrained('products')
                ->restrictOnDelete();

            $table->foreignId('purchase_item_id')
                ->nullable()
                ->constrained('purchase_items')
                ->nullOnDelete();

            $table->string('lot_number')->nullable();
            $table->date('expires_at')->nullable();

            $table->decimal('initial_quantity', 15, 4);
            $table->decimal('current_quantity', 15, 4);

            $table->timestamps();

            $table->index([
                'company_id',
                'branch_id',
                'product_id',
            ], 'inventory_lots_company_branch_product_index');

            $table->index([
                'branch_id',
                'product_id',
                'expires_at',
            ], 'inventory_lots_branch_product_expiration_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_lots');
    }
};
