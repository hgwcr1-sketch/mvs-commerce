<?php

namespace App\Services\Fiscal;

use App\Jobs\EmitElectronicDocument;
use App\Models\Sale;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Disparador seguro de emisión electrónica desde el POS.
 *
 * Se invoca solo después de que la venta quedó confirmada y decidido el
 * despacho de EmitElectronicDocument; no conoce ningún proveedor fiscal
 * (POS habla con el job y el job con FiscalManager).
 *
 * Con fiscal.emission.auto_emit en false (valor por defecto) la venta se
 * procesa exactamente igual que hoy y no se despacha nada. Cualquier fallo
 * de este disparador queda registrado y nunca afecta la venta ya cobrada.
 */
class PosEmissionDispatcher
{
    public function forSale(Sale $sale): bool
    {
        try {
            if (! (bool) config('fiscal.emission.auto_emit', false)) {
                return false;
            }

            if (! app(FiscalConsumptionService::class)->isFiscalEnabled((int) $sale->company_id)) {
                return false;
            }

            if (! $this->emittable($sale)) {
                return false;
            }

            EmitElectronicDocument::dispatch($sale->id, $sale->document_type);

            return true;
        } catch (Throwable $exception) {
            Log::warning('fiscal.emission.dispatch_failed', [
                'sale_id' => $sale->id,
                'document_type' => $sale->document_type,
                'error' => $exception::class . ': ' . $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function emittable(Sale $sale): bool
    {
        if (! in_array($sale->document_type, [Sale::DOCUMENT_ELECTRONIC_INVOICE, Sale::DOCUMENT_ELECTRONIC_TICKET], true)) {
            return false;
        }

        return ! $sale->is_historical && $sale->status === Sale::STATUS_COMPLETED;
    }
}
