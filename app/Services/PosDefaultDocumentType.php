<?php

namespace App\Services;

use App\Models\CompanyCashSetting;
use App\Models\Sale;

/**
 * Comprobante preseleccionado por defecto en el POS.
 *
 * Fuente única: `company_cash_settings.default_document_type` de la empresa
 * activa. Cualquier valor ausente o inválido cae al default seguro `ticket`
 * (interno, nunca Hacienda): la ausencia de configuración jamás puede
 * convertirse silenciosamente en un tipo fiscal.
 */
class PosDefaultDocumentType
{
    public function resolve(?int $companyId): string
    {
        if ($companyId === null || $companyId <= 0) {
            return Sale::DOCUMENT_TICKET;
        }

        $configured = CompanyCashSetting::query()
            ->where('company_id', $companyId)
            ->value('default_document_type');

        return in_array($configured, [
            Sale::DOCUMENT_TICKET,
            Sale::DOCUMENT_ELECTRONIC_TICKET,
            Sale::DOCUMENT_ELECTRONIC_INVOICE,
        ], true) ? $configured : Sale::DOCUMENT_TICKET;
    }
}
