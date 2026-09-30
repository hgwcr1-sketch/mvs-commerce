<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 master fiscal: onboarding completo, series y custodia.
 *
 * - company_fiscal_configs: actividad económica + códigos fiscales de
 *   sucursal/terminal (dominio Hacienda, NO proveedor-específico).
 * - fiscal_series: autoridad provider-neutral de series por
 *   empresa+ambiente+sucursal+terminal+tipo. Observa consecutivos reales;
 *   la numeración productiva sigue en el proveedor hasta MvsFiscal.
 * - fiscal_document_custody: payload enviado + respuesta del proveedor por
 *   documento (payload inmutable, respuesta actualizable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_fiscal_configs', function (Blueprint $table) {
            $table->string('economic_activity', 30)->nullable()->after('provider');
            $table->string('fiscal_branch_code', 3)->nullable()->after('economic_activity');
            $table->string('fiscal_terminal_code', 5)->nullable()->after('fiscal_branch_code');
        });

        Schema::create('fiscal_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('environment', 20)->default('sandbox');
            $table->string('branch_code', 3);
            $table->string('terminal_code', 5);
            $table->string('document_type', 10);
            $table->unsignedBigInteger('last_sequence')->default(0);
            $table->string('last_consecutivo', 20)->nullable();
            $table->foreignId('last_document_id')->nullable()->constrained('electronic_documents')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['company_id', 'environment', 'branch_code', 'terminal_code', 'document_type'],
                'uq_fiscal_series_scope'
            );
        });

        Schema::create('fiscal_document_custody', function (Blueprint $table) {
            $table->id();
            $table->foreignId('electronic_document_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('payload');
            $table->json('response')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_document_custody');
        Schema::dropIfExists('fiscal_series');

        Schema::table('company_fiscal_configs', function (Blueprint $table) {
            $table->dropColumn(['economic_activity', 'fiscal_branch_code', 'fiscal_terminal_code']);
        });
    }
};
