<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo de reemisión tras rechazo fiscal.
 *
 * RETRY técnico (misma idempotency) = misma fila, sin duplicar.
 * REEMISIÓN tras rejected definitivo = NUEVO intento: nueva fila con
 * attempt_number+1, nueva idempotency, mismo source/original. El historial
 * del rechazado queda intacto; mientras exista un intento no-rechazado
 * (queued/pending/sent/polling/accepted/error) el servicio impide el
 * siguiente intento y la unique compuesta es el backstop de concurrencia.
 *
 * Rollback: solo es reversible en estado de intento único por
 * (company, sale, type); con múltiples intentos la unique anterior no
 * puede restaurarse sin borrar historial, y esta migración no borra nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('electronic_documents', function (Blueprint $table) {
            $table->unsignedInteger('attempt_number')->default(1)->after('original_document_id');
            $table->dropUnique('uq_electronic_documents_company_sale_type');
            $table->unique(
                ['company_id', 'sale_id', 'document_type', 'attempt_number'],
                'uq_electronic_documents_company_sale_type_attempt'
            );
        });
    }

    public function down(): void
    {
        Schema::table('electronic_documents', function (Blueprint $table) {
            $table->dropUnique('uq_electronic_documents_company_sale_type_attempt');
            $table->unique(
                ['company_id', 'sale_id', 'document_type'],
                'uq_electronic_documents_company_sale_type'
            );
            $table->dropColumn('attempt_number');
        });
    }
};
