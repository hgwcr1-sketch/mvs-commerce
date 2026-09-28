<?php

namespace App\Services\Loyalty;

use App\Models\Company;
use App\Models\LoyaltyPromotion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Publicidad/promociones del portal de Fidelización (F35).
 *
 * Contenido promocional administrable por empresa, independiente de los
 * multiplicadores de puntos (F12), que conservan su propia semántica. La
 * vigencia usa la misma semántica temporal/zona horaria empresarial que
 * LoyaltyMultiplierResolver: inicio y fin son inclusivos, comparando en UTC
 * contra fechas almacenadas en UTC.
 */
class LoyaltyPromotionService
{
    /** Promociones visibles en el portal: activas y vigentes ahora, ordenadas por prioridad. */
    public function vigentes(Company $company): Collection
    {
        $now = $this->utcNow($company->timezone);

        return LoyaltyPromotion::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>=', $now)
            ->orderBy('sort_order')
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->get(['id', 'company_id', 'title', 'description', 'starts_at', 'ends_at']);
    }

    /**
     * Estado administrativo de una promoción respecto al momento actual.
     *
     * @return string vigente|futura|vencida|inactiva
     */
    public function estado(LoyaltyPromotion $promotion, ?string $timezone): string
    {
        if (! $promotion->is_active) {
            return 'inactiva';
        }

        $now = $this->utcNow($timezone);

        if ($promotion->starts_at->gt($now)) {
            return 'futura';
        }

        if ($promotion->ends_at->lt($now)) {
            return 'vencida';
        }

        return 'vigente';
    }

    private function utcNow(?string $timezone): CarbonImmutable
    {
        return CarbonImmutable::now($timezone ?: config('app.timezone'))->utc();
    }
}
