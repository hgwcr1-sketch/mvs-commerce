<?php

namespace App\Contracts\Fiscal;

use App\Models\ElectronicDocument;

/**
 * Descarga de artefactos XML REALES del proveedor por GET autenticado.
 *
 * Separada de FiscalProviderInterface para no romper implementaciones
 * existentes (mismo criterio que FiscalTaxpayerLookupInterface). Los
 * proveedores que no la implementan simplemente no custodian XML: nunca
 * se fabrica, renombra ni inventa un artefacto.
 */
interface FiscalArtifactRetrievable
{
    /**
     * @return array{xml_signed: ?string, hacienda_response_xml: ?string}
     *                Claves solo con contenido XML real; null cuando el
     *                proveedor no expone el artefacto (o no aplica).
     */
    public function downloadArtifacts(ElectronicDocument $document): array;
}
