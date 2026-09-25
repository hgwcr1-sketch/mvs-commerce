<?php

namespace App\Services\Fiscal;

use App\Contracts\Fiscal\FiscalProviderInterface;
use App\DTOs\Fiscal\FiscalDocumentStatus;
use App\DTOs\Fiscal\FiscalEmissionRequest;
use App\DTOs\Fiscal\FiscalEmissionResult;
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
}
