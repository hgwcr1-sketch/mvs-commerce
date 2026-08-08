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
        Schema::create('company_purchase_settings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('default_product_category_id')
                ->nullable()
                ->constrained('product_categories')
                ->nullOnDelete();

            $table->foreignId('default_unit_id')
                ->nullable()
                ->constrained('units')
                ->nullOnDelete();

            $table->boolean('supplier_assignment_required')->default(true);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_purchase_settings');
    }
};
