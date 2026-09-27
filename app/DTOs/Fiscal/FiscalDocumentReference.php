<?php

namespace App\DTOs\Fiscal;

use App\Models\ElectronicDocument;

/**
 * Referencia inmutable al comprobante original que un documento modificador
 * (NC03/ND02) corrige. Solo datos congelados: jamás relee Sale/Product/Customer.
 */
final class FiscalDocumentReference
{
    public function __construct(
        public readonly int $electronicDocumentId,
        public readonly string $originalDocumentType,
        public readonly string $clave,
        public readonly ?string $consecutivo,
        public readonly ?string $issuedAt,
        public readonly string $referenceCode,
        public readonly string $reason,
        public readonly ?string $provider = null,
    ) {
    }

    /**
     * El original debe existir, pertenecer a la empresa, estar emitido
     * fiscalmente y admitir el tipo modificador. Sin referencias cruzadas.
     *
     * @throws FiscalReferenceException
     */
    public function validateAgainst(ElectronicDocument $original, int $companyId, string $modifierType): void
    {
        if ((int) $original->company_id !== $companyId) {
            throw new FiscalReferenceException('El documento original pertenece a otra empresa.', 'cross_company');
        }

        if ($original->id !== $this->electronicDocumentId || $original->clave !== $this->clave) {
            throw new FiscalReferenceException('La referencia no coincide con el documento original.', 'mismatch');
        }

        if (! in_array($original->status, ['sent', 'polling', 'accepted'], true)) {
            throw new FiscalReferenceException(
                "El documento original no está fiscalmente emitido (estado: {$original->status}).",
                'original_not_emitted'
            );
        }

        $allowed = [
            '03' => ['01', '04'],
            '02' => ['01', '04'],
        ];

        if (! in_array($original->document_type, $allowed[$modifierType] ?? [], true)) {
            throw new FiscalReferenceException(
                "El tipo {$modifierType} no puede modificar documentos {$original->document_type}.",
                'invalid_modifier'
            );
        }

        if (trim($this->referenceCode) === '' || trim($this->reason) === '') {
            throw new FiscalReferenceException('Código y motivo de referencia son obligatorios.', 'missing_reason');
        }
    }
}
