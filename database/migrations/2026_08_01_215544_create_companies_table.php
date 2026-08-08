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
        Schema::create('companies', function (Blueprint $table) {

    $table->id();

    // Información principal
    $table->string('trade_name', 150);
    $table->string('legal_name', 200)->nullable();

    // Identificación
    $table->string('identification_type', 30)->nullable();
    $table->string('identification_number', 50)->nullable();

    // Contacto
    $table->string('phone', 30)->nullable();
    $table->string('email')->nullable();

    // Ubicación
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

    $table->text('address')->nullable();

    // Personalización
    $table->string('logo')->nullable();

    // Configuración general
    $table->string('currency', 10)->default('CRC');
    $table->string('timezone', 100)->default('America/Costa_Rica');

    // Estado
    $table->boolean('is_active')->default(true);

    $table->timestamps();
});

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
