<?php

namespace App\Services\Facturaencr;

use App\DTOs\Fiscal\FiscalDocument;
use App\DTOs\Fiscal\FiscalDocumentLine;
use App\Exceptions\Facturaencr\FacturaencrValidationException;
use App\Models\Company;
use App\Models\ElectronicDocument;
use App\Services\Fiscal\FiscalTaxService;
use Carbon\Carbon;

/**
 * Mapper neutral → FacturaEnCR para documentos modificadores (NC03/ND02).
 *
 * Contrato verificado contra la documentación oficial
 * (https://facturaencr.com/docs, API v2 v4.4):
 * - NC03 → POST documents/nota-credito, ND02 → POST documents/nota-debito.
 * - `referencia` (array) obligatoria con tipoDocumento/numero/fechaEmision/
 *   codigo/razon; codigo obligatorio en NC/ND; numero = clave de 50 para
 *   comprobantes electrónicos; la API rellena codigo/razon si faltan, pero
 *   aquí siempre se envían (nunca se pisan).
 * - `receptor` opcional; `detalle` con la misma estructura de factura; los
 *   totales los calcula la plataforma y no se envían.
 *
 * Límite explícito: condicionVenta se deriva de la venta original (único dato
 * comercial disponible); medioPago/plazoCredito no se envían por no estar
 * documentados como requeridos para NC/ND.
 */
class FacturaencrAdjustmentMapper
{
    /**
     * Códigos de medio de pago que el adapter sabe derivar (mismo mapa
     * verificado del mapper FE/TE). Nada fuera de esta lista se envía.
     */
    private const MEDIO_PAGO_CODES = ['01', '02', '04', '06'];

    public function __construct(
        private readonly FiscalTaxService $fiscalTaxService = new FiscalTaxService(),
        private readonly ?string $environment = null,
    ) {
    }

    /**
     * @param array<int, string|array{tipo: string, monto: float}> $medioPago
     * @throws FacturaencrValidationException
     */
    public function map(FiscalDocument $document, Company $company, ?ElectronicDocument $original = null, string $condicionVenta = '01', array $medioPago = []): array
    {
        $document->validate();

        if (! $document->isModifier()) {
            throw new FacturaencrValidationException(['tipo_documento' => 'Solo 03/02 pasan por este mapper.']);
        }

        if ((int) $company->id !== $document->companyId) {
            throw new FacturaencrValidationException(['empresa' => 'La empresa no coincide con el documento.']);
        }

        if (! in_array($condicionVenta, ['01', '02'], true)) {
            throw new FacturaencrValidationException(['condicionVenta' => 'Condición de venta no soportada para NC/ND: 01/02.']);
        }

        // Verificado contra Hacienda sandbox (NC03 doc3, error -496): con
        // condicionVenta 01 el nodo Medio de Pago es obligatorio. Crédito
        // (02) está exento por documentación oficial.
        if ($condicionVenta === '01' && $medioPago === []) {
            throw new FacturaencrValidationException(['medioPago' => 'Contado requiere medio de pago derivado de la venta original.']);
        }

        $this->validateMedioPago($medioPago);

        $payload = [
            'emisorLegalId' => $this->emisorLegalId($company),
            'tipoDocumento' => $document->documentType,
            'condicionVenta' => $condicionVenta,
            'currency' => $document->currency,
            'exchangeRate' => (float) $document->exchangeRate,
            'receptor' => $this->mapReceptor($document->receptor),
            'detalle' => $this->mapDetalle($document),
            'referencia' => [$this->mapReferencia($document, $original)],
        ];

        if ($medioPago !== []) {
            $payload['medioPago'] = array_values($medioPago);
        }

        return array_filter($payload, static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Endpoints oficiales (docs FacturaEnCR, API v2 v4.4):
     * NC03 → documents/nota-credito, ND02 → documents/nota-debito.
     *
     * @throws FacturaencrValidationException
     */
    public function endpoint(string $documentType): string
    {
        return match ($documentType) {
            '03' => 'documents/nota-credito',
            '02' => 'documents/nota-debito',
            default => throw new FacturaencrValidationException([
                'endpoint' => "Tipo de documento no soportado por FacturaEnCR: {$documentType}.",
            ]),
        };
    }

    /**
     * @param array<int, string|array{tipo: string, monto: float}> $medioPago
     * @throws FacturaencrValidationException
     */
    private function validateMedioPago(array $medioPago): void
    {
        foreach ($medioPago as $medio) {
            if (is_string($medio)) {
                if (! in_array($medio, self::MEDIO_PAGO_CODES, true)) {
                    throw new FacturaencrValidationException(['medioPago' => "Medio de pago no soportado para NC/ND: {$medio}."]);
                }
                continue;
            }

            $tipo = is_array($medio) ? ($medio['tipo'] ?? null) : null;
            $monto = is_array($medio) ? ($medio['monto'] ?? null) : null;

            if (! is_string($tipo) || ! in_array($tipo, self::MEDIO_PAGO_CODES, true) || ! is_numeric($monto) || (float) $monto <= 0) {
                throw new FacturaencrValidationException(['medioPago' => 'Medio de pago múltiple requiere tipo válido y monto mayor a 0.']);
            }
        }
    }

    /**
     * Referencia oficial: tipoDocumento/numero/fechaEmision/codigo/razon.
     * Para comprobantes electrónicos el numero es la clave de 50 (se valida
     * el largo localmente para no quemar consecutivos con un -80).
     *
     * @throws FacturaencrValidationException
     */
    private function mapReferencia(FiscalDocument $document, ?ElectronicDocument $original): array
    {
        $reference = $document->reference;

        $clave = trim((string) $reference->clave);

        if (
            in_array($reference->originalDocumentType, ['01', '02', '03', '04', '08', '09', '10', '19', '20'], true)
            && strlen($clave) !== 50
        ) {
            throw new FacturaencrValidationException(['referencia.numero' => 'La clave del comprobante electrónico referenciado debe tener 50 caracteres.']);
        }

        return [
            'tipoDocumento' => $reference->originalDocumentType,
            'numero' => $clave,
            'fechaEmision' => $this->referenceIssuedAt($reference, $original),
            'codigo' => $reference->referenceCode,
            'razon' => $reference->reason,
        ];
    }

    /**
     * @throws FacturaencrValidationException
     */
    private function referenceIssuedAt(\App\DTOs\Fiscal\FiscalDocumentReference $reference, ?ElectronicDocument $original): string
    {
        $raw = $reference->issuedAt ?? $original?->created_at;

        if ($raw === null || trim((string) $raw) === '') {
            throw new FacturaencrValidationException(['referencia.fechaEmision' => 'La fecha de emisión del documento referenciado es obligatoria.']);
        }

        try {
            return Carbon::parse($raw)->setTimezone('America/Costa_Rica')->toIso8601String();
        } catch (\Throwable) {
            throw new FacturaencrValidationException(['referencia.fechaEmision' => 'Fecha de emisión del documento referenciado inválida.']);
        }
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
        if (($this->environment ?? config('facturaencr.environment', 'sandbox')) === 'sandbox') {
            return config('facturaencr.sandbox_emisor', 'EMISORPRUEBA');
        }

        return (string) $company->identification_number;
    }
}
