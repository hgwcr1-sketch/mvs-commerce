<?php

namespace App\Services\Facturaencr;

use App\Contracts\Fiscal\FiscalProviderInterface;
use App\Contracts\Fiscal\FiscalTaxpayerLookupInterface;
use App\DTOs\Fiscal\FiscalDocumentStatus;
use App\DTOs\Fiscal\FiscalError;
use App\DTOs\Fiscal\FiscalEmissionRequest;
use App\DTOs\Fiscal\FiscalEmissionResult;
use App\DTOs\Fiscal\FiscalTaxpayerInfo;
use App\Exceptions\Facturaencr\FacturaencrValidationException;
use App\Models\ElectronicDocument;

/**
 * Adaptador FacturaEnCR del contrato fiscal MVS.
 *
 * Encapsula todo lo propietario de FacturaEnCR (endpoints, mapper,
 * unidades, reintentos, códigos de error) y expone únicamente tipos
 * MVS: cualquier detalle FacturaEnCR sale traducido antes de devolver.
 */
class FacturaencrProvider implements FiscalProviderInterface, FiscalTaxpayerLookupInterface
{
    private readonly FacturaencrEmissionService $emissionService;

    private readonly FacturaencrTaxpayerService $taxpayerService;

    public function __construct(
        ?FacturaencrEmissionService $emissionService = null,
        ?FacturaencrTaxpayerService $taxpayerService = null,
    ) {
        $this->emissionService = $emissionService ?? new FacturaencrEmissionService();
        $this->taxpayerService = $taxpayerService ?? new FacturaencrTaxpayerService(new FacturaencrClient());
    }

    public function providerCode(): string
    {
        return 'facturaencr';
    }

    public function emit(FiscalEmissionRequest $request): FiscalEmissionResult
    {
        if ($request->adjustment !== null) {
            return $this->emitAdjustment($request);
        }

        try {
            $document = $this->emissionService->emit(
                $request->sale,
                $request->company,
                $request->customer,
                $request->saleItems,
                $request->salePayment,
            );
        } catch (FacturaencrValidationException $exception) {
            return $this->failedResult(new FiscalError(
                code: 'validation_failed',
                message: $exception->getMessage(),
                category: FiscalError::CATEGORY_VALIDATION,
                retryable: false,
                context: $exception->getErrors(),
            ));
        } catch (\InvalidArgumentException $exception) {
            return $this->failedResult(new FiscalError(
                code: 'invalid_request',
                message: $exception->getMessage(),
                category: FiscalError::CATEGORY_VALIDATION,
                retryable: false,
            ));
        }

        $error = $document->status === 'error'
            ? $this->toError($document->last_error_code, $document->last_error_message)
            : null;

        return new FiscalEmissionResult(
            state: $this->normalizeState($document->status),
            electronicDocumentId: $document->id,
            providerReference: $document->provider_document_id,
            fiscalReference: $document->clave,
            error: $error,
        );
    }

    private const ADJUSTMENT_CONDICION_VENTA_MAP = [
        'cash' => '01',
        'credit' => '02',
    ];

    private const ADJUSTMENT_MEDIO_PAGO_MAP = [
        'cash' => '01',
        'card' => '02',
        'bank_transfer' => '04',
        'sinpe' => '06',
    ];

    /**
     * Documentos modificadores (NC03/ND02) contra endpoints oficiales
     * (documents/nota-credito, documents/nota-debito). Flujo idéntico al
     * 01/04: idempotencia local, documento en queued, POST con
     * Idempotency-Key y conciliación de respuesta. Sin documento aceptado
     * no hay consumo (lo registra FiscalManager).
     */
    private function emitAdjustment(FiscalEmissionRequest $request): FiscalEmissionResult
    {
        $adjustment = $request->adjustment;

        try {
            $condicionVenta = self::ADJUSTMENT_CONDICION_VENTA_MAP[$request->sale->sale_condition ?? ''] ?? null;

            if ($condicionVenta === null) {
                throw new FacturaencrValidationException(['condicionVenta' => 'La venta original no tiene condición mapeable a NC/ND: cash/credit.']);
            }

            $original = ElectronicDocument::query()->find($adjustment->reference->electronicDocumentId);

            $medioPago = $this->medioPagoForAdjustment($request, $condicionVenta);

            $mapper = new FacturaencrAdjustmentMapper();
            $payload = $mapper->map($adjustment, $request->company, $original, $condicionVenta, $medioPago);
            $endpoint = $mapper->endpoint($adjustment->documentType);
        } catch (FacturaencrValidationException $exception) {
            return $this->failedResult(new FiscalError(
                code: 'validation_failed',
                message: $exception->getMessage(),
                category: FiscalError::CATEGORY_VALIDATION,
                retryable: false,
                context: $exception->getErrors(),
            ));
        }

        $idempotencyKey = $adjustment->idempotencyKey();

        $existing = ElectronicDocument::query()
            ->where('company_id', $request->company->id)
            ->where('sale_id', $request->sale->id)
            ->where('provider', 'facturaencr')
            ->where('document_type', $adjustment->documentType)
            ->where('environment', config('facturaencr.environment', 'sandbox'))
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            return new FiscalEmissionResult(
                state: $this->normalizeState($existing->status),
                electronicDocumentId: $existing->id,
                providerReference: $existing->provider_document_id,
                fiscalReference: $existing->clave,
            );
        }

        // Ciclo de reemisión: solo un rejected definitivo habilita el
        // siguiente intento (attempt = último + 1). Cualquier otro estado
        // (queued/pending/sent/polling/accepted/error) devuelve el intento
        // vigente SIN nueva fila y SIN POST: nunca dos intentos activos.
        $latest = ElectronicDocument::query()
            ->where('company_id', $request->company->id)
            ->where('sale_id', $request->sale->id)
            ->where('provider', 'facturaencr')
            ->where('document_type', $adjustment->documentType)
            ->where('environment', config('facturaencr.environment', 'sandbox'))
            ->orderByDesc('attempt_number')
            ->first();

        $expectedAttempt = $latest !== null ? $latest->attempt_number + 1 : 1;

        if ($adjustment->attempt !== $expectedAttempt) {
            return $this->failedResult(new FiscalError(
                code: 'invalid_attempt_sequence',
                message: "Secuencia de intentos inválida: se esperaba el intento {$expectedAttempt}.",
                category: FiscalError::CATEGORY_VALIDATION,
                retryable: false,
            ));
        }

        if ($latest !== null && $latest->status !== 'rejected') {
            return new FiscalEmissionResult(
                state: $this->normalizeState($latest->status),
                electronicDocumentId: $latest->id,
                providerReference: $latest->provider_document_id,
                fiscalReference: $latest->clave,
                error: $latest->status === 'error'
                    ? $this->toError($latest->last_error_code, $latest->last_error_message)
                    : null,
            );
        }

        try {
            $document = ElectronicDocument::create([
                'company_id' => $request->company->id,
                'sale_id' => $request->sale->id,
                'source_type' => $adjustment->sourceType,
                'source_id' => $adjustment->sourceId,
                'original_document_id' => $adjustment->reference->electronicDocumentId,
                'attempt_number' => $adjustment->attempt,
                'provider' => 'facturaencr',
                'document_type' => $adjustment->documentType,
                'environment' => config('facturaencr.environment', 'sandbox'),
                'idempotency_key' => $idempotencyKey,
                'status' => 'queued',
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Carrera real: otro proceso creó el intento concurrente. La
            // unique compuesta es el backstop: se devuelve el ganador sin
            // crear fila ni emitir de más. Máximo un nuevo attempt.
            $winner = ElectronicDocument::query()
                ->where('company_id', $request->company->id)
                ->where('sale_id', $request->sale->id)
                ->where('provider', 'facturaencr')
                ->where('document_type', $adjustment->documentType)
                ->where('environment', config('facturaencr.environment', 'sandbox'))
                ->orderByDesc('attempt_number')
                ->first();

            if ($winner !== null) {
                return new FiscalEmissionResult(
                    state: $this->normalizeState($winner->status),
                    electronicDocumentId: $winner->id,
                    providerReference: $winner->provider_document_id,
                    fiscalReference: $winner->clave,
                    error: $winner->status === 'error'
                        ? $this->toError($winner->last_error_code, $winner->last_error_message)
                        : null,
                );
            }

            throw new FacturaencrValidationException(['documento' => 'El intento fiscal no pudo registrarse por concurrencia.']);
        }

        $response = (new FacturaencrClient())->post($endpoint, $payload, $idempotencyKey);

        if ($response->isSuccess()) {
            $data = $response->data ?? [];
            $document->update([
                'status' => in_array($data['status'] ?? null, ['pending', 'queued', 'signing', 'sent', 'polling', 'accepted', 'rejected'], true)
                    ? $data['status']
                    : 'queued',
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

        $error = $document->status === 'error'
            ? $this->toError($document->last_error_code, $document->last_error_message)
            : null;

        return new FiscalEmissionResult(
            state: $this->normalizeState($document->status),
            electronicDocumentId: $document->id,
            providerReference: $document->provider_document_id,
            fiscalReference: $document->clave,
            error: $error,
        );
    }

    /**
     * Medio de pago derivado de los pagos COMPLETADOS de la venta original
     * (historial congelado, jamás inventado): uno → código simple; varios →
     * objetos tipo/monto (forma oficial para pago mixto). Crédito (02) va
     * sin medioPago por exención documentada. Contado sin pagos derivables
     * bloquea ANTES del POST (Hacienda -496 verificado en sandbox).
     *
     * @return array<int, string|array{tipo: string, monto: float}>
     *
     * @throws \App\Exceptions\Facturaencr\FacturaencrValidationException
     */
    private function medioPagoForAdjustment(FiscalEmissionRequest $request, string $condicionVenta): array
    {
        if ($condicionVenta === '02') {
            return [];
        }

        $payments = \App\Models\SalePayment::query()
            ->where('sale_id', $request->sale->id)
            ->where('status', \App\Models\SalePayment::STATUS_COMPLETED)
            ->orderBy('id')
            ->get();

        if ($payments->isEmpty()) {
            throw new FacturaencrValidationException(['medioPago' => 'La venta original no tiene pagos completados para derivar el medio de pago.']);
        }

        $mapped = [];

        foreach ($payments as $payment) {
            $code = self::ADJUSTMENT_MEDIO_PAGO_MAP[$payment->paymentMethod?->code ?? ''] ?? null;

            if ($code === null) {
                throw new FacturaencrValidationException(['medioPago' => 'Método de pago de la venta original no mapeable a FacturaEnCR.']);
            }

            $mapped[] = ['tipo' => $code, 'monto' => (float) $payment->amount];
        }

        if (count($mapped) === 1) {
            return [$mapped[0]['tipo']];
        }

        return $mapped;
    }

    public function fetchStatus(ElectronicDocument $document): FiscalDocumentStatus
    {
        $document = $this->emissionService->syncStatus($document);

        return new FiscalDocumentStatus(
            state: $this->normalizeState($document->status),
            final: $document->isFinal(),
            providerReference: $document->provider_document_id,
            fiscalReference: $document->clave,
            message: $document->last_error_message,
        );
    }

    /**
     * Consulta de contribuyentes vía FacturaEnCR: el servicio interno
     * devuelve su array propietario y aquí se traduce a FiscalTaxpayerInfo.
     */
    public function lookup(string $identification): FiscalTaxpayerInfo
    {
        $data = $this->taxpayerService->getRegimen($identification);

        if (($data['error'] ?? null) !== null) {
            $code = (string) $data['error'];
            [$category, $retryable] = $this->classify($code);

            return new FiscalTaxpayerInfo(
                found: false,
                error: new FiscalError(
                    code: $code,
                    message: $code,
                    category: $category,
                    retryable: $retryable,
                ),
            );
        }

        $regime = is_array($data['regimen'] ?? null) ? $data['regimen'] : null;

        $activities = [];
        foreach (($data['actividadesEconomicas'] ?? []) as $activity) {
            if (!is_array($activity)) {
                continue;
            }

            $activities[] = [
                'code' => (string) ($activity['codigo'] ?? ''),
                'description' => (string) ($activity['descripcion'] ?? ''),
            ];
        }

        return new FiscalTaxpayerInfo(
            found: (bool) ($data['encontrado'] ?? false),
            taxpayer: isset($data['contribuyente']) && $data['contribuyente'] !== null
                ? (bool) $data['contribuyente']
                : null,
            regimeCode: $regime !== null && ($regime['codigo'] ?? null) !== null
                ? (string) $regime['codigo']
                : null,
            regimeKey: $regime['clave'] ?? null,
            regimeDescription: $regime['descripcion'] ?? null,
            regimeSimplified: (bool) ($regime['simplificado'] ?? false),
            regimeTransfersTax: (bool) ($regime['trasladaIva'] ?? false),
            activities: $activities,
            situation: $data['situacion'] ?? null,
        );
    }

    private function failedResult(FiscalError $error): FiscalEmissionResult
    {
        return new FiscalEmissionResult(
            state: FiscalEmissionResult::STATE_ERROR,
            error: $error,
        );
    }

    /**
     * Estados crudos del proveedor → ciclo de vida propio MVS.
     */
    private function normalizeState(string $state): string
    {
        return match ($state) {
            'accepted' => FiscalEmissionResult::STATE_ACCEPTED,
            'rejected' => FiscalEmissionResult::STATE_REJECTED,
            'error' => FiscalEmissionResult::STATE_ERROR,
            'queued' => FiscalEmissionResult::STATE_QUEUED,
            default => FiscalEmissionResult::STATE_PENDING,
        };
    }

    /**
     * Traduce el error persistido (código crudo del proveedor) a un
     * FiscalError neutral con categoría + reintento.
     */
    private function toError(?string $code, ?string $message): FiscalError
    {
        $code = $code !== null && $code !== '' ? $code : 'unknown';
        [$category, $retryable] = $this->classify($code);

        return new FiscalError(
            code: $code,
            message: $message !== null && $message !== '' ? $message : $code,
            category: $category,
            retryable: $retryable,
        );
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function classify(string $code): array
    {
        if (in_array($code, ['CONNECTION_TIMEOUT', 'REQUEST_FAILED'], true)) {
            return [FiscalError::CATEGORY_NETWORK, true];
        }

        if ($code === 'UNKNOWN_ERROR') {
            return [FiscalError::CATEGORY_UNKNOWN, true];
        }

        if (str_starts_with($code, 'HTTP_')) {
            $status = (int) substr($code, 5);

            return match (true) {
                $status === 401 => [FiscalError::CATEGORY_AUTHENTICATION, false],
                $status === 403 => [FiscalError::CATEGORY_AUTHORIZATION, false],
                $status === 409 => [FiscalError::CATEGORY_CONFLICT, false],
                $status === 429 => [FiscalError::CATEGORY_RATE_LIMIT, true],
                $status >= 500 => [FiscalError::CATEGORY_SERVER, true],
                default => [FiscalError::CATEGORY_VALIDATION, false],
            };
        }

        return match (true) {
            str_contains($code, 'rate') => [FiscalError::CATEGORY_RATE_LIMIT, true],
            str_contains($code, 'unauth') => [FiscalError::CATEGORY_AUTHENTICATION, false],
            str_contains($code, 'forbid') => [FiscalError::CATEGORY_AUTHORIZATION, false],
            str_contains($code, 'conflict') => [FiscalError::CATEGORY_CONFLICT, false],
            str_contains($code, 'valid') => [FiscalError::CATEGORY_VALIDATION, false],
            default => [FiscalError::CATEGORY_UNKNOWN, false],
        };
    }
}
