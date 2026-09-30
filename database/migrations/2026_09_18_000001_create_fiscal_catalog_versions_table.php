<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versión de un catálogo fiscal.
 *
 * Es una tabla de VERSIONES, no de datos: `cabys_catalog_entries` y
 * `fiscal_profiles` apuntan aquí para saber contra qué versión se validó cada
 * código o cada perfil.
 *
 * `kind` separa las familias que comparten la tabla: `cabys` (catálogo local
 * de producción) y `tax` (perfiles fiscales de Facturación Electrónica).
 *
 * Integración prod + FE: esta migración crea el esquema UNIÓN (todas las
 * columnas e índices del catálogo CABYS de producción más los que necesita
 * FE) solo si la tabla todavía no existe. Si la base ya la creó con
 * `2026_09_28_000002_create_fiscal_catalog_versions_table` (producción),
 * termina sin hacer nada y la tabla existente se conserva tal cual.
 */
return new class extends Migration
{
    public function up(): void
    {
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
        /*
         * Sin dropIfExists deliberadamente: en una base de producción la tabla
         * fue creada por `2026_09_28_000002` y un rollback de este archivo no
         * debe borrar el catálogo CABYS ya cargado.
         */
    }
};
