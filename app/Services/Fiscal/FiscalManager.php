<?php

namespace App\Services\Fiscal;

use App\Contracts\Fiscal\FiscalCabysCatalogInterface;
use App\Contracts\Fiscal\FiscalProviderInterface;
use App\Contracts\Fiscal\FiscalTaxpayerLookupInterface;
use App\DTOs\Fiscal\FiscalCabysEntry;
use App\DTOs\Fiscal\FiscalCabysSearchResult;
use App\DTOs\Fiscal\FiscalDocumentStatus;
use App\DTOs\Fiscal\FiscalEmissionRequest;
use App\DTOs\Fiscal\FiscalEmissionResult;
use App\DTOs\Fiscal\FiscalReferenceException;
use App\DTOs\Fiscal\FiscalTaxpayerInfo;
use App\Exceptions\FiscalQuotaException;
use App\Models\ElectronicDocument;

/**
 * Fachada fiscal de MVS Commerce.
 *
 * Único punto de entrada para consumidores (POS, ventas, NC, etc.).
 * El proveedor concreto se resuelve por configuración (fiscal.provider)
 * y, en consulta de estado, por ElectronicDocument.provider; los
 * consumidores solo dependen de este manager y de los DTOs MVS.
 */
class FiscalManager
{
    /** @var array<string, FiscalProviderInterface> */
    private array $resolved = [];

    private ?FiscalCabysCatalogInterface $cabysCatalog = null;

    /**
     * Proveedor efectivo de la empresa: el configurado en su portal fiscal
     * (dentro de los registrados en config/fiscal.php; el futuro
     * MvsFiscalProvider se suma ahí sin cambiar consumidores).
     */
    public function providerForCompany(\App\Models\Company $company): FiscalProviderInterface
    {
        $code = app(CompanyFiscalConfigService::class)->providerCodeFor($company);

        return $this->provider($code);
    }

    public function provider(?string $providerCode = null): FiscalProviderInterface
    {
        $code = $providerCode !== null && $providerCode !== ''
            ? $providerCode
            : (string) config('fiscal.provider', 'facturaencr');

        if (isset($this->resolved[$code])) {
            return $this->resolved[$code];
        }

        $class = config("fiscal.providers.{$code}");

        if (!is_string($class) || $class === '' || !class_exists($class)) {
            throw new \InvalidArgumentException("Proveedor fiscal no configurado: {$code}");
        }

        $provider = app()->make($class);

        if (!$provider instanceof FiscalProviderInterface) {
            throw new \InvalidArgumentException(
                "El proveedor fiscal no implementa FiscalProviderInterface: {$class}"
            );
        }

        return $this->resolved[$code] = $provider;
    }

    /**
     * Emisión con gate fiscal común (manual y automática pasan por aquí):
     * servicio deshabilitado, tipo no consumible o cuota agotada sin
     * excedentes bloquean ANTES del POST. Solo un documento aceptado por
     * el proveedor registra consumo en el ledger (una unidad por documento).
     *
     * @throws \App\Exceptions\FiscalQuotaException
     */
    public function emit(FiscalEmissionRequest $request): FiscalEmissionResult
    {
        $consumption = app(FiscalConsumptionService::class);

        if ($request->adjustment !== null) {
            [$companyId, $documentType] = $this->authorizeAdjustment($request);
        } else {
            $companyId = (int) $request->company->id;
            $documentType = $consumption->documentTypeForSale($request->sale);
        }

        $decision = $consumption->authorize($companyId, $documentType);

        if (! $decision['allowed']) {
            throw new FiscalQuotaException(
                match ($decision['reason']) {
                    'fiscal_disabled' => 'El servicio fiscal no está habilitado para esta empresa.',
                    'quota_exhausted' => 'Cuota mensual de documentos fiscales agotada y sin excedentes permitidos.',
                    default => 'Documento fiscal no autorizable.',
                },
                $decision['reason']
            );
        }

        $result = $this->providerForCompany($request->company)->emit($request);

        if (! $result->isError() && $result->electronicDocumentId !== null) {
            $document = ElectronicDocument::query()->find($result->electronicDocumentId);

            if ($document !== null) {
                $consumption->record($document);
            }
        }

        return $result;
    }

    /**
     * Gate del documento modificador neutral: validación estructural,
     * mismo tenant, referencia contra el documento original emitido y tipo.
     *
     * @return array{0: int, 1: string}
     *
     * @throws \App\DTOs\Fiscal\FiscalReferenceException
     */
    private function authorizeAdjustment(FiscalEmissionRequest $request): array
    {
        $adjustment = $request->adjustment;
        $adjustment->validate();

        if ((int) $request->company->id !== $adjustment->companyId) {
            throw new FiscalReferenceException('El ajuste pertenece a otra empresa.', 'cross_company');
        }

        $original = ElectronicDocument::query()->find($adjustment->reference->electronicDocumentId);

        if ($original === null) {
            throw new FiscalReferenceException('Documento original no encontrado.', 'original_not_found');
        }

        $adjustment->reference->validateAgainst($original, $adjustment->companyId, $adjustment->documentType);

        return [$adjustment->companyId, $adjustment->documentType];
    }

    public function fetchStatus(ElectronicDocument $document): FiscalDocumentStatus
    {
        return $this->provider($document->provider)->fetchStatus($document);
    }

    /**
     * Catálogo CABYS: fuente oficial BCCR gestionada localmente por MVS
     * (tabla cabys). No usa proveedor de emisión ni hace red por búsqueda.
     */
    public function cabysCatalog(): FiscalCabysCatalogInterface
    {
        if ($this->cabysCatalog !== null) {
            return $this->cabysCatalog;
        }

        $class = config('fiscal.cabys_catalog', LocalCabysCatalog::class);

        if (!is_string($class) || $class === '' || !class_exists($class)) {
            throw new \InvalidArgumentException('Catálogo CABYS no configurado');
        }

        $catalog = app()->make($class);

        if (!$catalog instanceof FiscalCabysCatalogInterface) {
            throw new \InvalidArgumentException(
                "El catálogo CABYS no implementa FiscalCabysCatalogInterface: {$class}"
            );
        }

        return $this->cabysCatalog = $catalog;
    }

    public function searchCabys(string $query, int $limit = 30): FiscalCabysSearchResult
    {
        return $this->cabysCatalog()->search($query, $limit);
    }

    public function validateCabys(string $code): ?FiscalCabysEntry
    {
        return $this->cabysCatalog()->validate($code);
    }

    /**
     * Consulta de contribuyentes: delegada a la capacidad del proveedor
     * configurado (implementación actual FacturaEnCR; futuras fuentes
     * directas se cambian por configuración, no por consumidores).
     */
    public function lookupTaxpayer(string $identification): FiscalTaxpayerInfo
    {
        $provider = $this->provider();

        if (!$provider instanceof FiscalTaxpayerLookupInterface) {
            throw new \InvalidArgumentException(
                "El proveedor fiscal '{$provider->providerCode()}' no implementa consulta de contribuyentes"
            );
        }

        return $provider->lookup($identification);
    }
}
