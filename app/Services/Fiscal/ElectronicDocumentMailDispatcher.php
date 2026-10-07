<?php

namespace App\Services\Fiscal;

use App\Jobs\SendElectronicDocumentMail;
use App\Models\ElectronicDocument;
use App\Models\ElectronicDocumentMailDelivery;
use App\Models\Sale;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Dispara el envío automático del paquete del comprobante al receptor
 * cuando (y solo cuando) el documento queda ACEPTADO y MVS tiene su
 * custodia. Provider-neutral y agnóstico al tipo de documento: FE, TE,
 * NC y ND comparten este único flujo (FE/TE vía emisión, NC/ND vía
 * emitAdjustment, todos vía polling en syncStatus; los tres persisten
 * custodia a través de FiscalCustodyService, único punto de disparo).
 */
class ElectronicDocumentMailDispatcher
{
    /**
     * Crea la fila de auditoría (unique por documento+tipo) y encola el
     * envío. Segunda llamada: unique violation → null (sin duplicados).
     */
    public function dispatchAccepted(ElectronicDocument $document): ?ElectronicDocumentMailDelivery
    {
        $document = $document->fresh() ?? $document;

        if ($document->status !== 'accepted') {
            return null;
        }

        $recipient = $this->receiverEmail($document);

        try {
            $delivery = ElectronicDocumentMailDelivery::create([
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
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        if ($recipient !== null) {
            SendElectronicDocumentMail::dispatch($delivery->id);
        }

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
