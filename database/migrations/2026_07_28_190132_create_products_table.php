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
        Schema::create('products', function (Blueprint $table) {

            $table->id();

            /*
            |--------------------------------------------------------------------------
            | Relaciones
            |--------------------------------------------------------------------------
            */

            $table->foreignId('category_id')
                ->constrained('product_categories')
                ->restrictOnDelete();

            $table->foreignId('brand_id')
                ->nullable();

            $table->foreignId('unit_id')
                ->constrained('units')
                ->restrictOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Información General
            |--------------------------------------------------------------------------
            */

            $table->string('name', 150);

            $table->string('internal_code', 50)
                ->unique();
            $table->string('barcode', 100)
                ->nullable()
                ->unique();

            $table->enum('product_type', [
                'product',
                'service',
                'combo',
            ])->default('product');

            $table->string('cabys_code', 20)->nullable();

            $table->string('short_description')->nullable();

            $table->text('description')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Costos
            |--------------------------------------------------------------------------
            */

            $table->decimal('cost', 15, 2)->default(0);
            $table->decimal('sale_price', 15, 2)->default(0);

            $table->decimal('wholesale_price', 15, 2)->nullable();

            $table->decimal('special_price', 15, 2)->nullable();

            /*
            |--------------------------------------------------------------------------
            | Inventario
            |--------------------------------------------------------------------------
            */
            $table->decimal('stock', 15, 2)->default(0);

            $table->boolean('track_inventory')->default(true);
            $table->decimal('minimum_stock', 15, 2)->default(0);

            $table->decimal('maximum_stock', 15, 2)->default(0);

            $table->boolean('allow_negative_stock')
                ->default(false);

            /*
            |--------------------------------------------------------------------------
            | Impuestos
            |--------------------------------------------------------------------------
            */

            $table->decimal('tax_rate', 5, 2)
                ->default(13.00);

            /*
            |--------------------------------------------------------------------------
            | Imagen
            |--------------------------------------------------------------------------
            */

            $table->string('image')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Estado
            |--------------------------------------------------------------------------
            */

            $table->boolean('is_active')
                ->default(true);

            $table->timestamps();

            $table->softDeletes();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
