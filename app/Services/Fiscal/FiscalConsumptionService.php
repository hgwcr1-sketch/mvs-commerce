<?php

namespace App\Services\Fiscal;

use App\Exceptions\FiscalQuotaException;
use App\Models\CompanyLicense;
use App\Models\ElectronicDocument;
use App\Models\FiscalConsumption;
use App\Models\Sale;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Consumo auditable de documentos fiscales por empresa.
 *
 * Fuente de verdad: el ledger inmutable `fiscal_consumptions` (una fila por
 * documento fiscal local como máximo). El consumo mensual se DETERMINA
 * contando el ledger del período; jamás se usa un contador mutable como
 * autoridad. El período es el mes calendario del documento (día 1).
 *
 * Política conservadora documentada:
 * - Solo consume cuando el proveedor ACEPTA el documento para proceso
 *   (POST exitoso: queued/pending/sent/accepted). Un fallo de transporte o
 *   validación (estado error, sin documento) no consume.
 * - Un rechazo posterior de Hacienda NO elimina la fila: el historial se
 *   preserva en electronic_documents.status y el consumo queda registrado.
 * - Tiquete interno jamás consume ni llega aquí.
 */
class FiscalConsumptionService
{
    /** Tipos fiscales Hacienda que pueden consumir cuota (abierto a futuros). */
    public const CONSUMABLE_TYPES = ['01', '04', '03', '02'];

    public function periodFor(?CarbonImmutable $date = null): CarbonImmutable
    {
        return ($date ?? CarbonImmutable::now())->startOfMonth()->startOfDay();
    }

    public function isFiscalEnabled(int $companyId): bool
    {
        return (bool) CompanyLicense::query()->where('company_id', $companyId)->value('fiscal_enabled');
    }

    public function monthlyUsage(int $companyId, ?CarbonImmutable $period = null): int
    {
        return FiscalConsumption::query()
            ->where('company_id', $companyId)
            ->whereDate('period', $this->periodFor($period)->toDateString())
            ->count();
    }

    /**
     * Autorización previa (asesora, sin lock): bloquea lo evidentemente
     * denegado ANTES del POST. La clasificación definitiva ocurre en
     * record(), con lock y recuento del ledger.
     *
     * @return array{allowed: bool, classification: string, unit_price: ?string, reason: string}
     */
    public function authorize(int $companyId, string $documentType): array
    {
        if (! in_array($documentType, self::CONSUMABLE_TYPES, true)) {
            return ['allowed' => false, 'classification' => '', 'unit_price' => null, 'reason' => 'non_consumable_type'];
        }

        $license = CompanyLicense::query()->where('company_id', $companyId)->first();

        if ($license === null || ! $license->fiscal_enabled) {
            return ['allowed' => false, 'classification' => '', 'unit_price' => null, 'reason' => 'fiscal_disabled'];
        }

        if ($license->fiscal_monthly_quota === null) {
            return ['allowed' => true, 'classification' => FiscalConsumption::CLASSIFICATION_INCLUDED, 'unit_price' => null, 'reason' => 'unlimited'];
        }

        if ($this->monthlyUsage($companyId) < $license->fiscal_monthly_quota) {
            return ['allowed' => true, 'classification' => FiscalConsumption::CLASSIFICATION_INCLUDED, 'unit_price' => null, 'reason' => 'within_quota'];
        }

        if ($license->fiscal_overage_enabled) {
            return ['allowed' => true, 'classification' => FiscalConsumption::CLASSIFICATION_OVERAGE, 'unit_price' => $license->fiscal_overage_unit_price, 'reason' => 'overage'];
        }

        return ['allowed' => false, 'classification' => '', 'unit_price' => null, 'reason' => 'quota_exhausted'];
    }

    /**
     * Registra el consumo de un documento ya aceptado por el proveedor.
     * Idempotente por electronic_document_id: reintentos del MISMO documento
     * devuelven la fila existente sin consumir de nuevo.
     */
    public function record(ElectronicDocument $document): FiscalConsumption
    {
        return DB::transaction(function () use ($document) {
            $existing = FiscalConsumption::query()
                ->where('electronic_document_id', $document->id)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $license = CompanyLicense::query()
                ->where('company_id', $document->company_id)
                ->lockForUpdate()
                ->first();

            $classification = FiscalConsumption::CLASSIFICATION_INCLUDED;
            $unitPrice = null;

            if ($license !== null && $license->fiscal_monthly_quota !== null) {
                $used = FiscalConsumption::query()
                    ->where('company_id', $document->company_id)
                    ->whereDate('period', $this->periodFor($document->created_at?->toImmutable() ?? null)->toDateString())
                    ->count();

                if ($used >= $license->fiscal_monthly_quota) {
                    $classification = FiscalConsumption::CLASSIFICATION_OVERAGE;
                    $unitPrice = $license->fiscal_overage_unit_price;
                }
            }

            return FiscalConsumption::create([
                'company_id' => $document->company_id,
                'electronic_document_id' => $document->id,
                'document_type' => $document->document_type,
                'period' => $this->periodFor($document->created_at?->toImmutable() ?? null)->toDateString(),
                'classification' => $classification,
                'unit_price' => $unitPrice,
            ]);
        });
    }

    /**
     * Tipo fiscal Hacienda derivado de la venta POS. El tiquete interno no
     * es consumible y se rechaza aquí como defensa en profundidad.
     */
    public function documentTypeForSale(Sale $sale): string
    {
        return match ($sale->document_type) {
            Sale::DOCUMENT_ELECTRONIC_INVOICE => '01',
            Sale::DOCUMENT_ELECTRONIC_TICKET => '04',
            default => throw new FiscalQuotaException(
                "El comprobante '{$sale->document_type}' no es un documento fiscal consumible.",
                'non_consumable_type'
            ),
        };
    }
}
