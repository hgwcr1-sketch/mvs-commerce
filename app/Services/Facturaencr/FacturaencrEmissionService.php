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
        $documentType = '01';
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
        $response = $client->post('documents/factura', $payload, $idempotencyKey);

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
