<?php

namespace App\DTOs\Fiscal;

/**
 * Documento fiscal neutral (provider-independent): 01 FE, 04 TE, 03 NC, 02 ND.
 * Todo dato comercial viaja congelado como snapshot; ningún adapter y ningún
 * proveedor forman parte de este contrato.
 *
 * RETRY técnico ≠ REEMISIÓN tras rechazo: reintentar el MISMO intento conserva
 * attempt e idempotency (una sola fila); reemitir tras un rejected definitivo
 * es un NUEVO intento (attempt+1, nueva idempotency, nueva fila histórica).
 */
final class FiscalDocument
{
    /**
     * @param array<string, string|null> $receptor identification_type,
     * identification_number, name, email?.
     * @param array<int, FiscalDocumentLine> $lines
     * @param array<string, string> $totals subtotal, discount_total,
     * tax_total, total (cadenas decimales).
     */
    public function __construct(
        public readonly int $companyId,
        public readonly string $documentType,
        public readonly string $sourceType,
        public readonly string $sourceId,
        public readonly array $receptor,
        public readonly string $currency,
        public readonly string $exchangeRate,
        public readonly array $lines,
        public readonly array $totals,
        public readonly ?FiscalDocumentReference $reference = null,
        public readonly int $attempt = 1,
    ) {
    }

    public function isModifier(): bool
    {
        return in_array($this->documentType, ['03', '02'], true);
    }

    /**
     * Identidad local estable: sin timestamps, random ni llaves externas.
     * Attempt 1 conserva el formato histórico; attempt ≥2 lo extiende.
     */
    public function idempotencyKey(): string
    {
        if ($this->attempt <= 1) {
            return md5("{$this->companyId}-{$this->sourceType}-{$this->sourceId}-{$this->documentType}");
        }

        return md5("{$this->companyId}-{$this->sourceType}-{$this->sourceId}-{$this->documentType}-attempt{$this->attempt}");
    }

    /**
     * Validación estructural interna (no legal): tipos, montos decimales,
     * CABYS 13 dígitos, receptor completo y referencia obligatoria en
     * modificadores.
     *
     * @throws FiscalReferenceException
     */
    public function validate(): void
    {
        if (! in_array($this->documentType, ['01', '04', '03', '02'], true)) {
            throw new FiscalReferenceException("Tipo fiscal no soportado: {$this->documentType}.", 'unsupported_type');
        }

        foreach (['subtotal', 'discount_total', 'tax_total', 'total'] as $key) {
            if (! isset($this->totals[$key]) || ! preg_match('/^\d{1,15}(?:\.\d{1,4})?$/', (string) $this->totals[$key])) {
                throw new FiscalReferenceException("Total inválido: {$key}.", 'invalid_totals');
            }
        }

        if ($this->lines === []) {
            throw new FiscalReferenceException('El documento requiere al menos una línea.', 'empty_lines');
        }

        foreach ($this->lines as $line) {
            if (! $line instanceof FiscalDocumentLine) {
                throw new FiscalReferenceException('Línea fiscal inválida.', 'invalid_line');
            }

            if (preg_match('/^\d{13}$/', $line->cabys) !== 1) {
                throw new FiscalReferenceException("CABYS inválido: {$line->cabys}.", 'invalid_cabys');
            }
        }

        foreach (['identification_type', 'identification_number', 'name'] as $key) {
            if (trim((string) ($this->receptor[$key] ?? '')) === '') {
                throw new FiscalReferenceException("Receptor incompleto: {$key}.", 'invalid_receptor');
            }
        }

        if ($this->isModifier() && $this->reference === null) {
            throw new FiscalReferenceException('Los documentos 03/02 requieren referencia al original.', 'missing_reference');
        }

        if (! $this->isModifier() && $this->reference !== null) {
            throw new FiscalReferenceException('Solo 03/02 admiten referencia.', 'unexpected_reference');
        }

        if ($this->attempt < 1) {
            throw new FiscalReferenceException('El intento fiscal debe ser mayor o igual a 1.', 'invalid_attempt');
        }
    }
}
