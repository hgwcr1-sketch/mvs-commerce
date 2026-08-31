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
        Schema::create('customers', function (Blueprint $table) {

            $table->id();

            /*
            |--------------------------------------------------------------------------
            | Tipo de cliente
            |--------------------------------------------------------------------------
            */

            $table->enum('customer_type', [
                'individual',
                'company',
            ])->default('individual');

            /*
            |--------------------------------------------------------------------------
            | Información general
            |--------------------------------------------------------------------------
            */

            $table->string('identification', 50)->nullable()->unique();
            $table->string('name', 150);
            $table->string('commercial_name', 150)->nullable();

            /*
            |--------------------------------------------------------------------------
            | Contacto
            |--------------------------------------------------------------------------
            */

            $table->string('phone', 30)->nullable();
            $table->string('mobile', 30)->nullable();
            $table->string('email', 150)->nullable();
            /*
|--------------------------------------------------------------------------
| Facturación Electrónica Costa Rica
|--------------------------------------------------------------------------
*/

            $table->enum('identification_type', [
                '01', // Cédula Física
                '02', // Cédula Jurídica
                '03', // DIMEX
                '04', // NITE
                '05',  // Extranjero no domiciliado
            ])->nullable();

            $table->string('taxpayer_name', 255)->nullable();

            $table->boolean('accepts_email_invoice')->default(true);

            /*
            |--------------------------------------------------------------------------
            | Ubicación
            |--------------------------------------------------------------------------
            */

            $table->foreignId('country_id')
                ->nullable();

            $table->foreignId('province_id')
                ->nullable();

            $table->foreignId('canton_id')
                ->nullable();

            $table->foreignId('district_id')
                ->nullable();

            $table->text('address')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Información comercial
            |--------------------------------------------------------------------------
            */

            $table->decimal('credit_limit', 15, 2)->default(0);

            $table->unsignedInteger('credit_days')->default(0);

            /*
            |--------------------------------------------------------------------------
            | Fidelización
            |--------------------------------------------------------------------------
            */

            $table->unsignedInteger('points')->default(0);

            /*
            |--------------------------------------------------------------------------
            | Otros
            |--------------------------------------------------------------------------
            */

            $table->date('birth_date')->nullable();

            $table->boolean('is_active')->default(true);

            $table->softDeletes();

            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
