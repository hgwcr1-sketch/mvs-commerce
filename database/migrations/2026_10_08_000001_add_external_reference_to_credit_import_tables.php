<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 12B — referencia externa del crédito importado, aditivo y reversible.
 *
 * - cobros_initial_portfolio_records: clave de idempotencia de la importación
 *   por (company_id, external_reference); reimportar nunca duplica.
 * - accounts_receivable: la CxC importada queda anclada a la MISMA referencia,
 *   con unicidad por empresa, para que un reintento jamás duplique CxC.
 *
 * Nullable: los registros/cargos preexistentes de 5B no traen referencia y
 * no se migran ni reescriben (unicidad SQL tolera múltiples NULL).
 * No toca pagos, recibos ni cobros_credits.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('cobros_initial_portfolio_records', function (Blueprint $table) {
            $table->string('external_reference', 64)->nullable()->after('source');
            $table->unique(['company_id', 'external_reference'], 'cip_company_external_reference_unique');
        });

        Schema::table('accounts_receivable', function (Blueprint $table) {
            $table->string('external_reference', 64)->nullable();
            $table->unique(['company_id', 'external_reference'], 'ar_company_external_reference_unique');
        });
    }

    public function down(): void
    {
        Schema::table('accounts_receivable', function (Blueprint $table) {
            $table->dropUnique('ar_company_external_reference_unique');
            $table->dropColumn('external_reference');
        });

        Schema::table('cobros_initial_portfolio_records', function (Blueprint $table) {
            $table->dropUnique('cip_company_external_reference_unique');
            $table->dropColumn('external_reference');
        });
    }
};
