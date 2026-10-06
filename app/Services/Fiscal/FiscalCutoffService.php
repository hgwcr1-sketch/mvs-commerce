<?php

namespace App\Services\Fiscal;

use App\Models\CompanyFiscalConfig;
use App\Models\Sale;
use Carbon\CarbonImmutable;

/**
 * Corte de inicio fiscal por empresa (`company_fiscal_configs.fiscal_start_at`).
 *
 * Protección global anti-emisión masiva: toda venta completada ANTES del
 * corte queda fuera del flujo automático de forma permanente, sin importar
 * auto_emit, licencia ni estado del job. Es la salvaguarda de las ventas
 * históricas creadas antes de la integración fiscal.
 *
 * Solo LEE configuración: nunca crea ni modifica filas. Sin valor configurado
 * no hay corte y aplican el resto de gates (tipo, estado, licencia, cuota).
 */
class FiscalCutoffService
{
    public function cutoffFor(int $companyId): ?CarbonImmutable
    {
        $raw = CompanyFiscalConfig::query()
            ->where('company_id', $companyId)
            ->value('fiscal_start_at');

        if ($raw === null || $raw === '') {
            return null;
        }

        return CarbonImmutable::parse($raw);
    }

    public function isBeforeCutoff(Sale $sale): bool
    {
        $cutoff = $this->cutoffFor((int) $sale->company_id);

        if ($cutoff === null) {
            return false;
        }

        $moment = $sale->completed_at ?? $sale->created_at;

        if ($moment === null) {
            return true;
        }

        return CarbonImmutable::parse($moment)->lt($cutoff);
    }
}
