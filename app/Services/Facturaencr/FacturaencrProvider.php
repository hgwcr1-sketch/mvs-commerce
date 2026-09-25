<?php

namespace App\Services\Facturaencr;

use App\Contracts\Fiscal\FiscalProviderInterface;
use App\DTOs\Fiscal\FiscalDocumentStatus;
use App\DTOs\Fiscal\FiscalError;
use App\DTOs\Fiscal\FiscalEmissionRequest;
use App\DTOs\Fiscal\FiscalEmissionResult;
use App\Exceptions\Facturaencr\FacturaencrValidationException;
use App\Models\ElectronicDocument;

/**
 * Adaptador FacturaEnCR del contrato fiscal MVS.
 *
 * Encapsula todo lo propietario de FacturaEnCR (endpoints, mapper,
 * unidades, reintentos, códigos de error) y expone únicamente tipos
 * MVS: cualquier detalle FacturaEnCR sale traducido antes de devolver.
 */
class FacturaencrProvider implements FiscalProviderInterface
{
    private readonly FacturaencrEmissionService $emissionService;

    public function __construct(?FacturaencrEmissionService $emissionService = null)
    {
        $this->emissionService = $emissionService ?? new FacturaencrEmissionService();
    }

    public function providerCode(): string
    {
        return 'facturaencr';
    }

    public function emit(FiscalEmissionRequest $request): FiscalEmissionResult
    {
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
