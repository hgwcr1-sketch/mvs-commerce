<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asignación de CABYS a producto, POR EMPRESA (MF04 adaptado a producción).
 *
 * Es una tabla y no una columna porque hace que respondan tres preguntas que
 * una columna no puede:
 *
 *  1. ¿Quién asignó este código y cuándo? (auditoría)
 *  2. ¿Con qué versión del catálogo se validó?
 *  3. ¿Cómo se revierte una asignación? (`previous_code`)
 *
 * Aislamiento: el catálogo es global, la asignación es de la empresa. Por eso
 * el unique es `(company_id, product_id)`: una empresa nunca lee ni escribe
 * la asignación de otra.
 *
 * `status = pending` es una propuesta NO confirmada: no es un código
 * utilizable en venta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_cabys_assignments', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->string('code', 20);

            $table->foreignId('fiscal_catalog_version_id')
                ->nullable()
                ->constrained('fiscal_catalog_versions')
                ->nullOnDelete();

            /** manual | bulk | import */
            $table->string('source', 20)->default('manual');

            /** pending = propuesta ambigua sin confirmar; confirmed = decisión humana. */
            $table->string('status', 20)->default('confirmed');

            $table->decimal('confidence', 5, 4)->nullable();
            $table->json('candidates')->nullable();

            $table->string('previous_code', 20)->nullable();

            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();

            $table->unique(['company_id', 'product_id'], 'product_cabys_company_product_unique');
            $table->index(['company_id', 'status'], 'product_cabys_company_status_index');
            $table->index(['company_id', 'code'], 'product_cabys_company_code_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_cabys_assignments');
    }
};
