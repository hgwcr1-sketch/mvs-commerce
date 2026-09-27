<?php

namespace App\Services\Facturaencr;

use App\DTOs\Fiscal\FiscalDocument;
use App\DTOs\Fiscal\FiscalDocumentLine;
use App\Exceptions\Facturaencr\FacturaencrValidationException;
use App\Models\Company;
use App\Services\Fiscal\FiscalTaxService;

/**
 * Mapper neutral → FacturaEnCR para documentos modificadores (NC03/ND02).
 *
 * Límite verificable: reutiliza bloques probados del mapper FE/TE
 * (emisor, receptor, detalle, impuestos congelados). El ENDPOINT de emisión
 * 03/02 no está documentado en el repositorio: endpoint() lo marca como
 * pendiente en lugar de inventarlo. El bloque informacionReferencia sigue la
 * normativa pública de Hacienda y queda pendiente de confirmación contra el
 * proveedor antes de cualquier POST real.
 */
class FacturaencrAdjustmentMapper
{
    public function __construct(private readonly FiscalTaxService $fiscalTaxService = new FiscalTaxService())
    {
    }

    /**
     * @throws FacturaencrValidationException
     */
    public function map(FiscalDocument $document, Company $company): array
    {
        $document->validate();

        if (! $document->isModifier()) {
            throw new FacturaencrValidationException(['tipo_documento' => 'Solo 03/02 pasan por este mapper.']);
        }

        if ((int) $company->id !== $document->companyId) {
            throw new FacturaencrValidationException(['empresa' => 'La empresa no coincide con el documento.']);
        }

        $reference = $document->reference;

        return array_filter([
            'emisorLegalId' => $this->emisorLegalId($company),
            'tipoDocumento' => $document->documentType,
            'currency' => $document->currency,
            'exchangeRate' => (float) $document->exchangeRate,
            'receptor' => $this->mapReceptor($document->receptor),
            'detalle' => $this->mapDetalle($document),
            'informacionReferencia' => [
                'tipoDoc' => $reference->originalDocumentType,
                'numero' => $reference->clave,
                'fechaEmision' => $reference->issuedAt,
                'codigo' => $reference->referenceCode,
                'razon' => $reference->reason,
            ],
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Endpoint de emisión 03/02 PENDIENTE: no documentado en el repositorio.
     * Se marca explícitamente en lugar de inventar la ruta del proveedor.
     *
     * @throws FacturaencrValidationException
     */
    public function endpoint(string $documentType): string
    {
        throw new FacturaencrValidationException([
            'endpoint' => "Emisión {$documentType} pendiente de endpoint verificado en FacturaEnCR.",
        ]);
    }

    /**
     * @param array<string, string|null> $receptor
     */
    private function mapReceptor(array $receptor): array
    {
        $mapped = [
            'tipoIdentificacion' => $receptor['identification_type'],
            'numeroIdentificacion' => $receptor['identification_number'],
            'nombre' => $receptor['name'],
        ];

        $email = trim((string) ($receptor['email'] ?? ''));

        if ($email !== '') {
            $mapped['correoElectronico'] = $email;
        }

        return $mapped;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mapDetalle(FiscalDocument $document): array
    {
        $unitMapper = new FacturaencrUnitMapper();
        $detalle = [];

        foreach ($document->lines as $line) {
            $this->validateTaxes($line, $document->documentType);

            $mapped = [
                'codigoCabys' => $line->cabys,
                'detalle' => $line->description,
                'cantidad' => (float) $line->quantity,
                'unidadMedida' => $unitMapper->map($line->unitCode),
                'precioUnitario' => (float) $line->unitPrice,
                'impuesto' => $line->taxes,
            ];

            if (bccomp($line->discountTotal, '0', 4) > 0) {
                $mapped['descuento'] = (float) $line->discountTotal;
            }

            $detalle[] = $mapped;
        }

        return $detalle;
    }

    /**
     * @throws FacturaencrValidationException
     */
    private function validateTaxes(FiscalDocumentLine $line, string $documentType): void
    {
        try {
            $this->fiscalTaxService->validateSnapshot([
                'source' => 'frozen',
                'source_version' => '1',
                'taxes' => $line->taxes,
            ], $documentType);
        } catch (\Throwable $exception) {
            throw new FacturaencrValidationException(['impuesto' => $exception->getMessage()]);
        }
    }

    private function emisorLegalId(Company $company): string
    {
        if (config('facturaencr.environment', 'sandbox') === 'sandbox') {
            return config('facturaencr.sandbox_emisor', 'EMISORPRUEBA');
        }

        return (string) $company->identification_number;
    }
}
