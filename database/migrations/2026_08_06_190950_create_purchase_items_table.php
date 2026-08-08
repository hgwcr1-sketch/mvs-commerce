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
        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('purchase_id')
                ->constrained('purchases')
                ->cascadeOnDelete();

            $table->foreignId('product_id')
                ->constrained('products')
                ->restrictOnDelete();

            // Cantidad comprada.
            $table->decimal('quantity', 15, 4);

            // Costo unitario utilizado en esta compra.
            $table->decimal('unit_cost', 15, 4);

            // Subtotal antes de descuento e impuesto.
            $table->decimal('subtotal', 15, 2)->default(0);

            // Descuento aplicado a esta línea.
            $table->decimal('discount', 15, 2)->default(0);

            // Porcentaje de impuesto aplicado.
            $table->decimal('tax_rate', 8, 4)->default(0);

            // Monto del impuesto de esta línea.
            $table->decimal('tax', 15, 2)->default(0);

            // Total final de la línea.
            $table->decimal('total', 15, 2)->default(0);

            $table->timestamps();

            /*
             * Un producto aparece una sola vez dentro
             * de una misma compra.
             */
            $table->unique(
                ['purchase_id', 'product_id'],
                'purchase_items_purchase_product_unique'
            );

            $table->index(
                'product_id',
                'purchase_items_product_index'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_items');
    }
};