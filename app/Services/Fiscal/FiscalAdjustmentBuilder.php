<?php

namespace App\Services\Fiscal;

use App\DTOs\Fiscal\FiscalDocument;
use App\DTOs\Fiscal\FiscalDocumentLine;
use App\DTOs\Fiscal\FiscalDocumentReference;
use App\DTOs\Fiscal\FiscalReferenceException;

/**
 * Constructor neutral de documentos modificadores (NC03/ND02) desde datos
 * ya congelados. No lee Product/Sale/Customer mutables ni recalcula: el
 * dominio comercial (o el fixture) aporta snapshots y el builder solo
 * ensambla, valida estructura y aplica el tope contra el original.
 */
class FiscalAdjustmentBuilder
{
    /**
     * @param array<int, FiscalDocumentLine> $lines
     * @param array<string, string> $receptor
     * @param array<string, string> $totals
     */
    public static function creditNote(
        int $companyId,
        string $sourceType,
        string $sourceId,
        array $receptor,
        string $currency,
        string $exchangeRate,
        array $lines,
        array $totals,
        FiscalDocumentReference $reference,
        ?string $originalTotalCap = null,
    ): FiscalDocument {
        return self::build('03', $companyId, $sourceType, $sourceId, $receptor, $currency, $exchangeRate, $lines, $totals, $reference, $originalTotalCap);
    }

    /**
     * @param array<int, FiscalDocumentLine> $lines
     * @param array<string, string> $receptor
     * @param array<string, string> $totals
     */
    public static function debitNote(
        int $companyId,
        string $sourceType,
        string $sourceId,
        array $receptor,
        string $currency,
        string $exchangeRate,
        array $lines,
        array $totals,
        FiscalDocumentReference $reference,
        ?string $originalTotalCap = null,
    ): FiscalDocument {
        return self::build('02', $companyId, $sourceType, $sourceId, $receptor, $currency, $exchangeRate, $lines, $totals, $reference, $originalTotalCap);
    }

    /**
     * @param array<int, FiscalDocumentLine> $lines
     * @param array<string, string> $receptor
     * @param array<string, string> $totals
     */
    private static function build(
        string $documentType,
        int $companyId,
        string $sourceType,
        string $sourceId,
        array $receptor,
        string $currency,
        string $exchangeRate,
        array $lines,
        array $totals,
        FiscalDocumentReference $reference,
        ?string $originalTotalCap,
    ): FiscalDocument {
        if ($originalTotalCap !== null && bccomp((string) ($totals['total'] ?? '0'), $originalTotalCap, 4) > 0) {
            throw new FiscalReferenceException(
                "El total del documento {$documentType} supera el comprobante original.",
                'exceeds_original'
            );
        }

        $document = new FiscalDocument(
            $companyId, $documentType, $sourceType, $sourceId, $receptor,
            $currency, $exchangeRate, array_values($lines), $totals, $reference
        );
        $document->validate();

        return $document;
    }
}
