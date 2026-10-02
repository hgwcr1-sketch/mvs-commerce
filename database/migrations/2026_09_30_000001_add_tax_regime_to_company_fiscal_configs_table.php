<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Régimen fiscal de la empresa en la configuración fiscal canónica.
 *
 * Solo la respuesta oficial de Hacienda o la confirmación manual del
 * tenant determinan el régimen (general | simplified | unknown).
 * `tax_regime_source` audita la procedencia (hacienda | manual) y
 * `tax_regime_verified_at` cuándo se determinó. Nunca se infiere desde
 * CABYS, actividad económica ni tasa impositiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_fiscal_configs', function (Blueprint $table) {
            $table->string('tax_regime', 20)->nullable()->after('fiscal_terminal_code');
            $table->string('tax_regime_source', 20)->nullable()->after('tax_regime');
            $table->timestamp('tax_regime_verified_at')->nullable()->after('tax_regime_source');
        });
    }

    public function down(): void
    {
        Schema::table('company_fiscal_configs', function (Blueprint $table) {
            $table->dropColumn(['tax_regime', 'tax_regime_source', 'tax_regime_verified_at']);
        });
    }
};
