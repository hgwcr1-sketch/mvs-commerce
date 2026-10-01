<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Puente de integración: producción + Facturación Electrónica.
 *
 * `fiscal_catalog_versions` puede llegar a la base por dos caminos:
 *
 *  - producción ya la creó con `2026_09_28_000002` (esquema CABYS);
 *  - una instalación limpia la crea con `2026_09_18_000001` (esquema unión).
 *
 * El índice de vigencia que consulta FE no lo crea ninguno de los dos para
 * que este puente pueda añadirlo exactamente una vez en ambos caminos y los
 * dos terminen con el mismo esquema. No crea tablas ni columnas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fiscal_catalog_versions')) {
            return;
        }

        Schema::table('fiscal_catalog_versions', function (Blueprint $table) {
            $table->index(['status', 'valid_from', 'valid_until'], 'fiscal_catalog_versions_status_valid_from_valid_until_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('fiscal_catalog_versions')) {
            return;
        }

        Schema::table('fiscal_catalog_versions', function (Blueprint $table) {
            $table->dropIndex('fiscal_catalog_versions_status_valid_from_valid_until_index');
        });
    }
};
