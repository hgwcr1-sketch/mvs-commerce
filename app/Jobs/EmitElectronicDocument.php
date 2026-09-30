<?php

namespace App\Jobs;

use App\DTOs\Fiscal\FiscalEmissionRequest;
use App\Models\ElectronicDocument;
use App\Models\Sale;
use App\Services\Fiscal\FiscalManager;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Emisión electrónica (Factura Electrónica / Tiquete Electrónico) de una
 * venta POS ya confirmada.
 *
 * Solo consume FiscalManager, los contratos y los DTOs MVS: jamás un
 * proveedor concreto. El job es una cola de lado, nunca participa en la
 * transacción de la venta: si el fiscal falla, la venta, la caja y el
 * inventario quedan exactamente como se confirmaron.
 */
class EmitElectronicDocument implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    /** Un solo intento: los reintentos fiscales los gobierna el flujo/proveedor. */
    public int $tries = 1;

    /** Ventana en la que doble dispatch para la misma venta queda bloqueado. */
    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $saleId,
        public readonly string $documentType,
    ) {
    }

    public function uniqueId(): string
    {
        return "{$this->saleId}:{$this->documentType}";
    }

    public function handle(FiscalManager $fiscalManager): void
    {
        try {
            $sale = $this->confirmedElectronicSale();

            if ($sale === null) {
                return;
            }

            $providerCode = $fiscalManager->provider()->providerCode();

            if ($this->alreadyEmitted($sale, $providerCode)) {
                Log::info('fiscal.emission.skipped', [
                    'sale_id' => $sale->id,
                    'document_type' => $this->documentType,
                    'provider' => $providerCode,
                    'reason' => 'document_exists',
                ]);

                return;
            }

            $result = $fiscalManager->emit(new FiscalEmissionRequest(
                $sale,
                $sale->company,
                $sale->customer,
                $sale->items->all(),
                $sale->payments->first(),
            ));

            Log::info($result->isError() ? 'fiscal.emission.error' : 'fiscal.emission.result', [
                'sale_id' => $sale->id,
                'document_type' => $this->documentType,
                'provider' => $providerCode,
                'state' => $result->state,
                'electronic_document_id' => $result->electronicDocumentId,
                'error_code' => $result->error?->code,
                'error_message' => $result->error?->message,
            ]);
        } catch (Throwable $exception) {
            Log::warning('fiscal.emission.failed', [
                'sale_id' => $this->saleId,
                'document_type' => $this->documentType,
                'error' => $exception::class . ': ' . $exception->getMessage(),
            ]);
        }
    }

    private function confirmedElectronicSale(): ?Sale
    {
        $sale = Sale::query()->find($this->saleId);

        if ($sale === null) {
            Log::warning('fiscal.emission.skipped', [
                'sale_id' => $this->saleId,
                'document_type' => $this->documentType,
                'reason' => 'sale_not_found',
            ]);

            return null;
        }

        if (
            ! in_array($this->documentType, [Sale::DOCUMENT_ELECTRONIC_INVOICE, Sale::DOCUMENT_ELECTRONIC_TICKET], true)
            || $sale->document_type !== $this->documentType
        ) {
            Log::warning('fiscal.emission.skipped', [
                'sale_id' => $sale->id,
                'document_type' => $this->documentType,
                'reason' => 'not_electronic_document',
            ]);

            return null;
        }

        if ($sale->is_historical || $sale->status !== Sale::STATUS_COMPLETED) {
            Log::warning('fiscal.emission.skipped', [
                'sale_id' => $sale->id,
                'document_type' => $this->documentType,
                'reason' => 'sale_not_emittable',
                'status' => $sale->status,
                'is_historical' => $sale->is_historical,
            ]);

            return null;
        }

        if ($sale->customer_id === null || $sale->customer === null) {
            Log::warning('fiscal.emission.skipped', [
                'sale_id' => $sale->id,
                'document_type' => $this->documentType,
                'reason' => 'missing_customer',
            ]);

            return null;
        }

        return $sale;
    }

    /**
     * Una venta tiene un solo comprobante electrónico: si el proveedor ya
     * tiene documento para esta venta no se vuelve a emitir, ni en doble
     * dispatch ni en reintento, por lo que nunca se duplica ElectronicDocument.
     * Los estados finales accepted/rejected tampoco se reemiten.
     */
    private function alreadyEmitted(Sale $sale, string $providerCode): bool
    {
        return ElectronicDocument::query()
            ->where('company_id', $sale->company_id)
            ->where('sale_id', $sale->id)
            ->where('provider', $providerCode)
            ->exists();
    }
}
