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
        Schema::create('districts', function (Blueprint $table) {

            $table->id();

            $table->foreignId('canton_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->string('name', 150);

            $table->string('code', 20)->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique([
                'canton_id',
                'name',
            ]);

        });

        Schema::table('customers', function (Blueprint $table) {
            $table->foreign('country_id')->references('id')->on('countries')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign('province_id')->references('id')->on('provinces')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign('canton_id')->references('id')->on('cantons')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign('district_id')->references('id')->on('districts')->cascadeOnUpdate()->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['country_id']);
            $table->dropForeign(['province_id']);
            $table->dropForeign(['canton_id']);
            $table->dropForeign(['district_id']);
        });

        Schema::dropIfExists('districts');
    }
};
