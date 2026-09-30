<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ledger INMUTABLE de consumo fiscal: fuente de verdad auditable.
     *
     * Un documento fiscal local (electronic_documents) consume como máximo
     * UNA unidad: la unicidad por electronic_document_id hace que retries,
     * polling o reenvíos del mismo documento jamás generen una segunda fila.
     * Sin updated_at: las filas nunca se modifican ni se borran (rechazos y
     * anulaciones conservan su historial en electronic_documents.status).
     */
    public function up(): void
    {
        Schema::create('fiscal_consumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('electronic_document_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('document_type', 10)->comment('Tipo fiscal Hacienda: 01=FE, 04=TE, 03=NC, 02=ND, futuros.');
            $table->date('period')->comment('Primer día del mes al que pertenece el consumo.');
            $table->string('classification', 10)->comment('included = dentro de cuota; overage = excedente cobrado.');
            $table->decimal('unit_price', 19, 4)->nullable()->comment('Precio excedente congelado al registrar overage.');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'period']);
            $table->unique(['company_id', 'electronic_document_id'], 'uq_fiscal_consumptions_company_document');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_consumptions');
    }
};
