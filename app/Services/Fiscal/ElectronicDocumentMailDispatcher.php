<?php

namespace App\Services\Fiscal;

use App\Jobs\SendElectronicDocumentMail;
use App\Models\ElectronicDocument;
use App\Models\ElectronicDocumentMailDelivery;
use App\Models\Sale;
use App\Services\Fiscal\FiscalCustodyService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Dispara el envío automático del paquete del comprobante al receptor
 * cuando (y solo cuando) el documento queda ACEPTADO y MVS tiene su
 * custodia. Provider-neutral y agnóstico al tipo de documento: FE, TE,
 * NC y ND comparten este único flujo (FE/TE vía emisión, NC/ND vía
 * emitAdjustment, todos vía polling en syncStatus; los tres persisten
 * custodia a través de FiscalCustodyService, único punto de disparo).
 * El envío manual del usuario reutiliza este dispatcher y el mismo job.
 */
class ElectronicDocumentMailDispatcher
{
    /**
     * Crea la fila de auditoría automática y encola el envío. Como máximo
     * UN envío automático por documento: la guarda lockForUpdate evita
     * duplicados aunque el índice sea simple (el manual sí permite varias
     * filas). Segunda llamada → null (sin duplicados).
     */
    public function dispatchAccepted(ElectronicDocument $document): ?ElectronicDocumentMailDelivery
    {
        $document = $document->fresh() ?? $document;

        if ($document->status !== 'accepted') {
            return null;
        }

        $recipient = $this->receiverEmail($document);

        try {
            $delivery = DB::transaction(function () use ($document, $recipient) {
                $exists = ElectronicDocumentMailDelivery::query()
                    ->where('electronic_document_id', $document->id)
                    ->where('delivery_type', ElectronicDocumentMailDelivery::TYPE_AUTOMATIC)
                    ->lockForUpdate()
                    ->exists();

                if ($exists) {
                    return null;
                }

                return ElectronicDocumentMailDelivery::create([
                    'company_id' => $document->company_id,
                    'electronic_document_id' => $document->id,
                    'recipient' => $recipient,
                    'delivery_type' => ElectronicDocumentMailDelivery::TYPE_AUTOMATIC,
                    'status' => $recipient === null
                        ? ElectronicDocumentMailDelivery::STATUS_SKIPPED
                        : ElectronicDocumentMailDelivery::STATUS_PENDING,
                    'message' => $recipient === null ? 'Sin correo registrado' : null,
                    'user_id' => null,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        if ($delivery === null) {
            return null;
        }

        if ($recipient !== null) {
            SendElectronicDocumentMail::dispatch($delivery->id);
        }

        return $delivery;
    }

    /**
     * Envío/reenvío manual solicitado por un usuario (CE-MAIL 2): misma
     * fila de auditoría y mismo job que el automático, con delivery_type
     * manual y user_id del solicitante. Crea una entrega NUEVA aunque ya
     * exista un envío automático. Jamás crea ElectronicDocument, no emite,
     * no consume cuota y no altera consecutivo/clave/estado.
     */
    public function dispatchManual(
        ElectronicDocument $document,
        string $recipient,
        ?int $userId,
    ): ?ElectronicDocumentMailDelivery {
        $document = $document->fresh() ?? $document;

        if ($document->status !== 'accepted') {
            return null;
        }

        $recipient = trim($recipient);

        if ($recipient === '' || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        // Completar custodia de artefactos XML reales (GET only, idempotente)
        // antes de construir el correo: cero POST, cero consumo, misma clave/consecutivo.
        app(FiscalCustodyService::class)->harvestArtifacts($document);

        $delivery = ElectronicDocumentMailDelivery::create([
            'company_id' => $document->company_id,
            'electronic_document_id' => $document->id,
            'recipient' => $recipient,
            'delivery_type' => ElectronicDocumentMailDelivery::TYPE_MANUAL,
            'status' => ElectronicDocumentMailDelivery::STATUS_PENDING,
            'user_id' => $userId,
        ]);

        SendElectronicDocumentMail::dispatch($delivery->id);

        return $delivery;
    }

    /**
     * Email fiscal/comercial del receptor según el modelo existente
     * (Customer.email). No se inventa ni se toma de otra empresa.
     */
    private function receiverEmail(ElectronicDocument $document): ?string
    {
        if ($document->sale_id === null) {
            return null;
        }

        $sale = Sale::query()
            ->where('company_id', $document->company_id)
            ->find($document->sale_id);

        $email = trim((string) ($sale?->customer?->email ?? ''));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $email;
    }
}
