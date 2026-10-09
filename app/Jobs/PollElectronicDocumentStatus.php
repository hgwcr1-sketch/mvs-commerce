<?php

namespace App\Jobs;

use App\Models\ElectronicDocument;
use App\Services\Fiscal\FiscalManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Polling automático de comprobantes fiscales no finales (queued/pending/
 * signing/sent/polling) después del POST de emisión.
 *
 * Solo consulta estado vía FiscalManager::fetchStatus: exactamente el mismo
 * provider y client autenticado de la emisión normal (credenciales de
 * CompanyFiscalConfigService, nunca config global ni otra fuente). Petición
 * GET idempotente: jamás emite, jamás crea otro ElectronicDocument, jamás
 * altera clave/consecutivo/provider_document_id/idempotency ni consume cuota.
 *
 * accepted → persiste accepted, completa custodia y dispara CE-MAIL una sola
 * vez (idempotente en ElectronicDocumentMailDispatcher). rejected/error final
 * → persiste y detiene. Estado final → sin volver a consultar.
 */
class PollElectronicDocumentStatus implements ShouldQueue
{
    use Queueable;

    /** Segundos entre consultas de estado (reprogramación). */
    public const RETRY_DELAY_SECONDS = 30;

    /** Límite de consultas (~15 min). Agotado: queda pendiente + warning. */
    public const MAX_ATTEMPTS = 30;

    /** Un fallo transitorio de red se reprograma, no reintenta el job. */
    public int $tries = 1;

    public function __construct(
        public readonly int $electronicDocumentId,
        public readonly int $attempt = 1,
    ) {
        // Cola fiscal dedicada: emit y poll corren juntos, aislados de
        // default/mail, sin que el worker default los consuma.
        $this->queue = 'fiscal';
    }

    public function handle(FiscalManager $fiscalManager): void
    {
        $document = ElectronicDocument::query()->find($this->electronicDocumentId);

        // Documento eliminado o ya final: nada que consultar (sin HTTP).
        if ($document === null || $document->isFinal()) {
            return;
        }

        // Solo estados consultables; un error previo al proveedor no tiene
        // identidad fiscal que sincronizar.
        if (! in_array($document->status, ['pending', 'queued', 'signing', 'sent', 'polling'], true)) {
            return;
        }

        try {
            $fiscalManager->fetchStatus($document);
        } catch (Throwable $exception) {
            Log::warning('fiscal.polling.error', [
                'electronic_document_id' => $this->electronicDocumentId,
                'attempt' => $this->attempt,
                'error' => $exception::class.': '.$exception->getMessage(),
            ]);

            $this->scheduleNext($document);

            return;
        }

        $document->refresh();

        // accepted/rejected ya persistidos dentro de fetchStatus (custodia y
        // CE-MAIL incluidos): sin reconsulta ni reprogramación.
        if ($document->isFinal()) {
            return;
        }

        $this->scheduleNext($document);
    }

    private function scheduleNext(ElectronicDocument $document): void
    {
        if ($this->attempt >= self::MAX_ATTEMPTS) {
            // Límite agotado: el documento queda visible como pendiente /
            // procesando. Nunca se reemite ni se altera su identidad fiscal.
            Log::warning('fiscal.polling.timeout', [
                'electronic_document_id' => $document->id,
                'sale_id' => $document->sale_id,
                'status' => $document->status,
                'attempt' => $this->attempt,
            ]);

            return;
        }

        self::dispatch($this->electronicDocumentId, $this->attempt + 1)
            ->delay(now()->addSeconds(self::RETRY_DELAY_SECONDS));
    }
}
