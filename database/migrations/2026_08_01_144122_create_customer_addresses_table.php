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
       Schema::create('customer_addresses', function (Blueprint $table) {

    $table->id();

    $table->foreignId('customer_id')
        ->constrained('customers')
        ->cascadeOnDelete();

    $table->string('name', 100);

    $table->foreignId('country_id')
        ->nullable()
        ->constrained('countries')
        ->restrictOnDelete();

    $table->foreignId('province_id')
        ->nullable()
        ->constrained('provinces')
        ->restrictOnDelete();

    $table->foreignId('canton_id')
        ->nullable()
        ->constrained('cantons')
        ->restrictOnDelete();

    $table->foreignId('district_id')
        ->nullable()
        ->constrained('districts')
        ->restrictOnDelete();

    $table->text('address');

    $table->boolean('is_primary')->default(false);

    $table->string('notes', 500)->nullable();

    $table->timestamps();

});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_addresses');
    }
};
