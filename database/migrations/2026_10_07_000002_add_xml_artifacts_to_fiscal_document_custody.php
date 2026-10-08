<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Artefactos XML reales del proveedor en la custodia del comprobante.
 *
 * Solo persiste lo que el proveedor devuelve por GET autenticado:
 * - xml_signed: XML firmado (GET /documents/{id}/xml).
 * - hacienda_response_xml: MensajeHacienda (GET /documents/{id}/xml-respuesta).
 * MVS no fabrica XML: si el proveedor no lo entrega, la columna queda null
 * y se completa en un polling/refresh posterior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_document_custody', function (Blueprint $table) {
            $table->text('xml_signed')->nullable()->after('response');
            $table->text('hacienda_response_xml')->nullable()->after('xml_signed');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_document_custody', function (Blueprint $table) {
            $table->dropColumn(['xml_signed', 'hacienda_response_xml']);
        });
    }
};
