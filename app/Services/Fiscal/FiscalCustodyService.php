<?php

namespace App\Services\Fiscal;

use App\Contracts\Fiscal\FiscalArtifactRetrievable;
use App\Models\ElectronicDocument;
use App\Models\FiscalDocumentCustody;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Custodia documental provider-neutral e inmutable.
 *
 * Guarda por documento: el payload enviado (inmutable, jamás se reescribe),
 * la última respuesta del proveedor (caché actualizable vía polling o
 * webhook futuro) y los artefactos XML REALES descargados del proveedor
 * (xml_signed / hacienda_response_xml). No fabrica XML local: si el
 * proveedor no lo entrega, la columna queda null y se completa en un
 * polling/refresh posterior.
 */
class FiscalCustodyService
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $response
     */
    public function record(ElectronicDocument $document, array $payload, ?array $response = null): FiscalDocumentCustody
    {
        $custody = DB::transaction(function () use ($document, $payload, $response) {
            $custody = FiscalDocumentCustody::query()
                ->where('electronic_document_id', $document->id)
                ->lockForUpdate()
                ->first();

            if ($custody === null) {
                return FiscalDocumentCustody::create([
                    'electronic_document_id' => $document->id,
                    'payload' => $payload,
                    'response' => $response,
                ]);
            }

            if ($response !== null) {
                $custody->update(['response' => $response]);
            }

            return $custody->fresh();
        });

        $this->harvestArtifacts($document);
        $this->notifyAccepted($document);

        return $custody;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function recordResponse(ElectronicDocument $document, array $response): ?FiscalDocumentCustody
    {
        $custody = FiscalDocumentCustody::query()
            ->where('electronic_document_id', $document->id)
            ->first();

        if ($custody === null) {
            return null;
        }

        $custody->update(['response' => $response]);

        $this->harvestArtifacts($document);
        $this->notifyAccepted($document);

        return $custody->fresh();
    }

    /**
     * Descarga (SOLO GET) de los artefactos XML reales que el proveedor
     * exponga para un comprobante ACEPTADO y aún no custodiados. Idempotente:
     * una vez persistida cada columna no vuelve a descargar, por lo que el
     * polling repetido no duplica archivos ni genera llamadas extra. Si el
     * proveedor aún no expone un artefacto, la columna queda null y se
     * intenta de nuevo en el siguiente refresh/polling; nunca se inventa.
     *
     * @return bool true si se persistió algún artefacto nuevo
     */
    public function harvestArtifacts(ElectronicDocument $document): bool
    {
        if ($document->status !== 'accepted') {
            return false;
        }

        $custody = FiscalDocumentCustody::query()
            ->where('electronic_document_id', $document->id)
            ->first();

        if ($custody === null) {
            return false;
        }

        if ($custody->xml_signed !== null && $custody->hacienda_response_xml !== null) {
            return false;
        }

        try {
            $provider = app(FiscalManager::class)->provider($document->provider);

            if (! $provider instanceof FiscalArtifactRetrievable) {
                return false;
            }

            $artifacts = $provider->downloadArtifacts($document);
        } catch (\Throwable $exception) {
            Log::warning('fiscal.custody.harvest_failed', [
                'electronic_document_id' => $document->id,
                'company_id' => $document->company_id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        $changed = false;

        foreach (['xml_signed', 'hacienda_response_xml'] as $key) {
            $value = $artifacts[$key] ?? null;

            if (is_string($value) && $value !== '' && $custody->{$key} === null) {
                $custody->{$key} = $value;
                $changed = true;
            }
        }

        if ($changed) {
            $custody->save();
        }

        return $changed;
    }

    /**
     * Único punto de disparo del aviso automático al receptor: después de
     * persistir la custodia (con los XML reales ya descargados cuando el
     * proveedor los expone), solo si el documento quedó aceptado. El
     * dispatcher es idempotente (unique documento+tipo), por lo que las
     * llamadas repetidas de polling no generan envíos duplicados.
     */
    private function notifyAccepted(ElectronicDocument $document): void
    {
        if ($document->status !== 'accepted') {
            return;
        }

        app(ElectronicDocumentMailDispatcher::class)->dispatchAccepted($document);
    }
}
