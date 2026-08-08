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
        Schema::table('purchase_items', function (Blueprint $table) {

            // Precio de venta que tenía el producto
            // antes de registrar esta compra.
            $table->decimal('previous_sale_price', 15, 2)
                ->nullable()
                ->after('unit_cost');

            // Nuevo precio de venta indicado durante
            // la compra. Es completamente opcional.
            $table->decimal('new_sale_price', 15, 2)
                ->nullable()
                ->after('previous_sale_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->dropColumn([
                'previous_sale_price',
                'new_sale_price',
            ]);
        });
    }
};