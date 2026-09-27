<?php

namespace App\Services\Fiscal;

use App\Models\ElectronicDocument;
use App\Models\FiscalDocumentCustody;
use Illuminate\Support\Facades\DB;

/**
 * Custodia documental provider-neutral e inmutable.
 *
 * Guarda por documento: el payload enviado (inmutable, jamás se reescribe)
 * y la última respuesta del proveedor (caché actualizable vía polling o
 * webhook futuro). No fabrica XML local: si el proveedor no lo entrega,
 * no se inventa. Prepara la capa para MVS Fiscal.
 */
class FiscalCustodyService
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $response
     */
    public function record(ElectronicDocument $document, array $payload, ?array $response = null): FiscalDocumentCustody
    {
        return DB::transaction(function () use ($document, $payload, $response) {
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
    }

    /**
     * @param array<string, mixed> $response
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

        return $custody->fresh();
    }
}
