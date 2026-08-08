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
        Schema::create('suppliers', function (Blueprint $table) {

            $table->id();

            /*
            |--------------------------------------------------------------------------
            | Empresa
            |--------------------------------------------------------------------------
            */

            $table->foreignId('company_id')
                ->constrained('companies')
                ->restrictOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Tipo de proveedor
            |--------------------------------------------------------------------------
            */

            $table->enum('supplier_type', [
                'individual',
                'company',
            ])->default('company');

            /*
            |--------------------------------------------------------------------------
            | Información general
            |--------------------------------------------------------------------------
            */

            $table->string('identification', 50)->nullable();

            $table->enum('identification_type', [
                '01', // Cédula Física
                '02', // Cédula Jurídica
                '03', // DIMEX
                '04', // NITE
                '05', // Extranjero no domiciliado
            ])->nullable();

            $table->string('name', 150);

            $table->string('commercial_name', 150)->nullable();

            /*
            |--------------------------------------------------------------------------
            | Contacto
            |--------------------------------------------------------------------------
            */

            $table->string('contact_name', 150)->nullable();

            $table->string('phone', 30)->nullable();

            $table->string('mobile', 30)->nullable();

            $table->string('email', 150)->nullable();

            /*
            |--------------------------------------------------------------------------
            | Ubicación
            |--------------------------------------------------------------------------
            */

            $table->foreignId('country_id')
                ->nullable()
                ->constrained()
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('province_id')
                ->nullable()
                ->constrained()
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('canton_id')
                ->nullable()
                ->constrained()
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('district_id')
                ->nullable()
                ->constrained()
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->text('address')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Información comercial
            |--------------------------------------------------------------------------
            */

            $table->unsignedInteger('credit_days')->default(0);

            $table->decimal('credit_limit', 15, 2)->default(0);

            /*
            |--------------------------------------------------------------------------
            | Otros
            |--------------------------------------------------------------------------
            */

            $table->text('notes')->nullable();

            $table->boolean('is_active')->default(true);

            /*
            |--------------------------------------------------------------------------
            | Índices
            |--------------------------------------------------------------------------
            |
            | Una misma identificación puede existir en empresas diferentes,
            | pero no duplicarse dentro de la misma empresa.
            |
            */

            $table->unique([
                'company_id',
                'identification',
            ]);

            $table->index([
                'company_id',
                'name',
            ]);

            $table->softDeletes();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};