<?php

namespace App\Contracts\Fiscal;

use App\DTOs\Fiscal\FiscalDocumentStatus;
use App\DTOs\Fiscal\FiscalEmissionRequest;
use App\DTOs\Fiscal\FiscalEmissionResult;
use App\Models\ElectronicDocument;

/**
 * Contrato fiscal propio de MVS Commerce.
 *
 * Los consumidores (POS, ventas, notas de crédito, etc.) dependen de este
 * contrato y de los DTOs MVS, nunca de un proveedor concreto. El proveedor
 * es responsable de traducir su API particular a estos tipos antes de
 * devolver el control.
 */
interface FiscalProviderInterface
{
    /**
     * Código estable del proveedor (ej. "facturaencr", "mvsfiscal").
     * Se usa para persistir ElectronicDocument.provider.
     */
    public function providerCode(): string;

    /**
     * Emite un documento fiscal a partir de una solicitud MVS.
     * Nunca lanza excepciones del proveedor: los fallos vuelven como
     * FiscalEmissionResult con state "error" y un FiscalError traducido.
     */
    public function emit(FiscalEmissionRequest $request): FiscalEmissionResult;

    /**
     * Consulta/actualiza el estado de un documento no final en el proveedor.
     * Para documentos en estado final no debe realizarse llamada externa.
     */
    public function fetchStatus(ElectronicDocument $document): FiscalDocumentStatus;
}
