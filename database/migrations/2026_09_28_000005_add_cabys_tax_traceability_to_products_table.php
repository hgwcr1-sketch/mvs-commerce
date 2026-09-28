<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trazabilidad de la tarifa oficial CABYS sobre el impuesto del producto.
 *
 * La tarifa OFICIAL se conserva tal cual la fuente la publica
 * (`tax_rate_official_raw` texto, `tax_rate_official_pct` traducción
 * numérica) y `tax_rate_source` distingue el origen del impuesto VIGENTE:
 *
 *  - `manual`: lo definió el usuario en el formulario.
 *  - `cabys_confirmed`: lo propuso la confirmación de un CABYS con tarifa
 *    oficial inequívoca.
 *
 * La sincronización no asume 13% ni inventa tarifa: un CABYS pending, ambiguo
 * o sin tasa interpretable NO toca el impuesto del producto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('tax_rate_source', 20)->default('manual')->after('tax_rate');
            $table->decimal('tax_rate_official_pct', 5, 2)->nullable()->after('tax_rate_source');
            $table->string('tax_rate_official_raw', 30)->nullable()->after('tax_rate_official_pct');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['tax_rate_source', 'tax_rate_official_pct', 'tax_rate_official_raw']);
        });
    }
};
