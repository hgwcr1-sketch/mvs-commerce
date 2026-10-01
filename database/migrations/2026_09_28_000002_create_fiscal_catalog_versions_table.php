<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versionado de catálogos fiscales (MF04 adaptado a producción).
 *
 * Es una tabla de VERSIONES, no una tabla de datos: `cabys_catalog_entries`
 * apunta aquí para saber contra qué versión se validó cada código.
 *
 * `kind` separa las familias que comparten la tabla (`cabys` hoy; `tax` queda
 * reservado para perfiles fiscales futuros) con un único sobre
 * `(kind, source, source_version)`: dos familias no pueden compartir la misma
 * versión.
 *
 * El catálogo es GLOBAL (sin company_id): el dato oficial es el mismo para
 * todos los tenants. Lo por-empresa es la asignación a producto.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Integración prod + FE: en una instalación limpia la tabla ya fue
         * creada por `2026_09_18_000001_create_fiscal_catalog_versions_table`
         * (esquema unión). En producción esta migración ya está aplicada, así
         * que este guard solo evita el error "table already exists" sin
         * provocar que Laravel la vuelva a ejecutar.
         */
        if (Schema::hasTable('fiscal_catalog_versions')) {
            return;
        }

        Schema::create('fiscal_catalog_versions', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->string('kind', 20)->default('tax');
            $table->string('source', 255);
            $table->string('source_version', 100);
            $table->timestamp('published_at')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('checksum', 128)->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->string('status', 20)->default('draft');

            $table->unique(['kind', 'source', 'source_version'], 'fiscal_catalog_versions_kind_source_version_unique');
            $table->index(['kind', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_catalog_versions');
    }
};
