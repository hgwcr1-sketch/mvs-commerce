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
use App\DTOs\Fiscal\FiscalTaxpayerInfo;
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

    public function emit(FiscalEmissionRequest $request): FiscalEmissionResult
    {
        return $this->provider()->emit($request);
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
