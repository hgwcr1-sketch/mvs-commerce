<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Actividades económicas oficiales del contribuyente (Hacienda).
 *
 * Una fila por actividad. `is_primary` queda reservado: la API oficial
 * `/fe/ae` no marca una actividad principal y marcarla sería inventar un
 * dato tributario.
 *
 * `company_id` en cada fila: aislamiento multiempresa como el resto del
 * módulo de clientes. El payload de Hacienda NO se guarda; solo código y
 * descripción normalizados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_taxpayer_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('description', 255)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['customer_id', 'code']);
            $table->index(['company_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_taxpayer_activities');
    }
};
