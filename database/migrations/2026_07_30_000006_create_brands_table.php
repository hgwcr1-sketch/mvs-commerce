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
        Schema::create('brands', function (Blueprint $table) {

            $table->id();

            $table->string('name', 150)->unique();

            $table->text('description')->nullable();

            $table->boolean('is_active')->default(true);

            $table->softDeletes();

            $table->timestamps();

        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreign('brand_id')
                ->references('id')
                ->on('brands')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['brand_id']);
        });

        Schema::dropIfExists('brands');
    }
};
