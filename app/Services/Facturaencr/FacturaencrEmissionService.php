<?php

namespace App\Services\Facturaencr;

use App\Models\Company;
use App\Models\Customer;
use App\Models\ElectronicDocument;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;

class FacturaencrEmissionService
{
    public function emit(
        Sale $sale,
        Company $company,
        Customer $customer,
        array $saleItems,
        ?SalePayment $salePayment = null
    ): ElectronicDocument {
        $mapper = new FacturaencrInvoiceMapper();
        $documentType = $mapper->documentType($sale);
        $this->assertScope($sale, $company, $customer);
        $idempotencyKey = $mapper->idempotencyKey($sale, $documentType);

        $existing = ElectronicDocument::query()
            ->where('company_id', $company->id)
            ->where('sale_id', $sale->id)
            ->where('provider', 'facturaencr')
            ->where('document_type', $documentType)
            ->where('environment', config('facturaencr.environment', 'sandbox'))
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return $existing;
        }

        $payload = $mapper->map($sale, $saleItems, $customer, $company, $salePayment, $idempotencyKey);

        $document = ElectronicDocument::create([
            'company_id' => $company->id,
            'sale_id' => $sale->id,
            'provider' => 'facturaencr',
            'document_type' => $documentType,
            'environment' => config('facturaencr.environment', 'sandbox'),
            'idempotency_key' => $idempotencyKey,
            'status' => 'queued',
        ]);

        $client = new FacturaencrClient();
        $response = $client->post($this->endpointFor($documentType), $payload, $idempotencyKey);

        if ($response->isSuccess()) {
            $data = $response->data ?? [];
            $document->update([
                'status' => $this->normalizeStatus($response),
                'provider_document_id' => $data['documentId'] ?? null,
                'clave' => $data['clave'] ?? null,
                'consecutivo' => $data['consecutivo'] ?? null,
                'provider_request_id' => $data['requestId'] ?? $idempotencyKey,
            ]);
        } else {
            $document->update([
                'status' => 'error',
                'last_error_code' => $response->errorCode,
                'last_error_message' => $response->errorMessage,
                'provider_request_id' => $response->httpStatusCode ? (string) $response->httpStatusCode : $idempotencyKey,
            ]);
        }

        return $document;
    }

    /**
     * Estados finales y no finales definidos por el contrato DocumentDetail
     * de Facturaencr: solo accepted/rejected cierran el ciclo de vida.
     */
    private const DOCUMENT_STATUSES = [
        'pending',
        'queued',
        'signing',
        'sent',
        'polling',
        'accepted',
        'rejected',
    ];

    /**
     * Consulta GET /documents/{id} (o /documents/clave/{clave}) para sincronizar
     * el estado de un documento no final. Es idempotente: documentos en estado
     * final o sin identificador de proveedor no generan HTTP.
     */
    public function syncStatus(ElectronicDocument $document): ElectronicDocument
    {
        if ($document->isFinal()) {
            return $document;
        }

        $endpoint = $document->provider_document_id !== null
            ? 'documents/' . $document->provider_document_id
            : ($document->clave !== null ? 'documents/clave/' . $document->clave : null);

        if ($endpoint === null) {
            return $document;
        }

        $client = new FacturaencrClient();
        $response = $client->get($endpoint);

        if (!$response->isSuccess()) {
            $document->update([
                'last_error_code' => $response->errorCode,
                'last_error_message' => $response->errorMessage,
            ]);

            return $document;
        }

        $data = $response->data ?? [];
        $remoteStatus = $data['status'] ?? null;

        $attributes = [
            'provider_document_id' => $data['documentId'] ?? $document->provider_document_id,
            'clave' => $data['clave'] ?? $document->clave,
            'consecutivo' => $data['consecutivo'] ?? $document->consecutivo,
        ];

        if (in_array($remoteStatus, self::DOCUMENT_STATUSES, true)) {
            $attributes['status'] = $remoteStatus;
        }

        if (($attributes['status'] ?? $document->status) === 'rejected') {
            $attributes['last_error_code'] = 'HACIENDA_REJECTED';
            $attributes['last_error_message'] = $data['haciendaMessage']
                ?? ($data['rechazo']['resumen'] ?? null);
        } elseif (($attributes['status'] ?? $document->status) === 'accepted') {
            $attributes['last_error_code'] = null;
            $attributes['last_error_message'] = null;
        }

        $document->update($attributes);

        return $document;
    }

    private function endpointFor(string $documentType): string
    {
        return match ($documentType) {
            '01' => 'documents/factura',
            '04' => 'documents/tiquete',
            default => throw new \InvalidArgumentException(
                "Tipo de documento no soportado por Facturaencr: {$documentType}"
            ),
        };
    }

    private function assertScope(Sale $sale, Company $company, Customer $customer): void
    {
        if ((int) $sale->company_id !== (int) $company->id) {
            throw new \InvalidArgumentException('La venta no pertenece a la empresa indicada.');
        }

        if ((int) $customer->company_id !== (int) $company->id) {
            throw new \InvalidArgumentException('El cliente no pertenece a la empresa indicada.');
        }
    }

    private function normalizeStatus(FacturaencrResponse $response): string
    {
        if ($response->httpStatusCode === 202) {
            return in_array($response->data['status'] ?? null, ['pending', 'queued', 'signing', 'sent', 'polling'], true)
                ? $response->data['status']
                : 'queued';
        }

        return in_array($response->data['status'] ?? null, ['accepted', 'rejected'], true)
            ? $response->data['status']
            : 'accepted';
    }
}
