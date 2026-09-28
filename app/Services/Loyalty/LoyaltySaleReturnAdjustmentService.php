<?php

namespace App\Services\Loyalty;

use App\Models\LoyaltyMovement;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ajuste proporcional de puntos por devoluciones (totales o parciales).
 *
 * Regla proporcional documentada (sin floats, BCMath escala 4, redondeo half-up):
 *  - Lado ganado:   proporcion = SUM(sale_return_items.subtotal acumulado de la venta)
 *                              / base_amount elegible del movimiento original de compra,
 *                   limitada a 1. puntos_a_revertir = round4(puntos_ganados × proporcion),
 *                   por delta sobre lo ya revertido en devoluciones anteriores.
 *  - Lado canjeado: proporcion = SUM(sale_return_items.total acumulado de la venta)
 *                              / total de la venta,
 *                   limitada a 1. puntos_a_restaurar = round4(puntos_canjeados × proporcion),
 *                   por delta sobre lo ya restaurado.
 * Los bonos fijos (cumpleaños/retorno/promoción) no se revierten: no son proporcionales
 * a la mercancía devuelta.
 */
class LoyaltySaleReturnAdjustmentService
{
    private const SCALE = 4;

    public function __construct(private readonly LoyaltyAccountService $accounts) {}

    public function adjust(Sale $sale, SaleReturn $return, User $user): void
    {
        $this->adjustEarnedSide($sale, $return, $user);
        $this->adjustRedeemedSide($sale, $return, $user);
    }

    private function adjustEarnedSide(Sale $sale, SaleReturn $return, User $user): void
    {
        $original = $this->movementFor($sale, LoyaltyMovement::TYPE_PURCHASE);

        if ($original === null) {
            return;
        }

        $baseAmount = (string) $original->base_amount;

        if (! $this->isPositive($baseAmount)) {
            return;
        }

        $cumulativeReturned = $this->cumulativeReturnedSubtotal($sale);
        $ratio = $this->cappedRatio($cumulativeReturned, $baseAmount);
        $target = $this->round4(bcmul((string) $original->points, $ratio, 6));
        $already = $this->alreadyAdjustedFor($original);
        $delta = bcsub($target, $already, self::SCALE);

        if (! $this->isPositive($delta)) {
            return;
        }

        $this->accounts->reverseMovement(
            $original,
            LoyaltyMovement::TYPE_RETURN,
            $this->context($sale, $return, $user, 'earned', [
                'eligible_base_amount' => $this->normalize($baseAmount),
                'cumulative_returned_subtotal' => $this->normalize($cumulativeReturned),
                'ratio' => $ratio,
                'original_points' => (string) $original->points,
                'previously_reverted_points' => $already,
            ]),
            $delta,
        );
    }

    private function adjustRedeemedSide(Sale $sale, SaleReturn $return, User $user): void
    {
        $original = $this->movementFor($sale, LoyaltyMovement::TYPE_REDEMPTION);

        if ($original === null) {
            return;
        }

        $saleTotal = (string) $sale->total;

        if (! $this->isPositive($saleTotal)) {
            return;
        }

        $redeemedPoints = ltrim((string) $original->points, '-');
        $cumulativeReturned = $this->cumulativeReturnedTotal($sale);
        $ratio = $this->cappedRatio($cumulativeReturned, $saleTotal);
        $target = $this->round4(bcmul($redeemedPoints, $ratio, 6));
        $already = $this->alreadyAdjustedFor($original);
        $delta = bcsub($target, $already, self::SCALE);

        if (! $this->isPositive($delta)) {
            return;
        }

        $this->accounts->reverseMovement(
            $original,
            LoyaltyMovement::TYPE_RETURN,
            $this->context($sale, $return, $user, 'redeemed', [
                'sale_total' => $this->normalize($saleTotal),
                'cumulative_returned_total' => $this->normalize($cumulativeReturned),
                'ratio' => $ratio,
                'redeemed_points' => $redeemedPoints,
                'previously_restored_points' => $already,
            ]),
            $delta,
        );
    }

    /**
     * Puntos ya ajustados vinculados al movimiento original mediante related_movement_id.
     */
    private function alreadyAdjustedFor(LoyaltyMovement $original): string
    {
        $rows = LoyaltyMovement::query()
            ->where('company_id', $original->company_id)
            ->where('type', LoyaltyMovement::TYPE_RETURN)
            ->where('related_movement_id', $original->id)
            ->get(['points']);

        $sum = '0.0000';

        foreach ($rows as $row) {
            $sum = bcadd($sum, ltrim((string) $row->points, '-'), self::SCALE);
        }

        return $sum;
    }

    private function movementFor(Sale $sale, string $type): ?LoyaltyMovement
    {
        return LoyaltyMovement::query()
            ->where('company_id', $sale->company_id)
            ->where('source_type', Sale::class)
            ->where('source_id', $sale->id)
            ->where('type', $type)
            ->first();
    }

    /**
     * Subtotal neto acumuladamente devuelto de la venta (incluye la devolución actual,
     * cuyas líneas ya fueron persistidas dentro de la misma transacción).
     */
    private function cumulativeReturnedSubtotal(Sale $sale): string
    {
        return $this->normalize((string) DB::table('sale_return_items')
            ->join('sale_returns', 'sale_returns.id', '=', 'sale_return_items.sale_return_id')
            ->where('sale_returns.sale_id', $sale->id)
            ->sum('sale_return_items.subtotal'));
    }

    private function cumulativeReturnedTotal(Sale $sale): string
    {
        return $this->normalize((string) DB::table('sale_return_items')
            ->join('sale_returns', 'sale_returns.id', '=', 'sale_return_items.sale_return_id')
            ->where('sale_returns.sale_id', $sale->id)
            ->sum('sale_return_items.total'));
    }

    private function cappedRatio(string $amount, string $denominator): string
    {
        if (! $this->isPositive($denominator)) {
            return '0.0000';
        }

        $ratio = bcdiv($amount, $denominator, 6);

        return bccomp($ratio, '1', 6) > 0 ? '1.000000' : $ratio;
    }

    private function context(Sale $sale, SaleReturn $return, User $user, string $kind, array $proportion): array
    {
        return [
            'branch' => $sale->branch_id,
            'user' => $user->id,
            'source_type' => SaleReturn::class,
            'source_id' => $return->id,
            'event_key' => "sale:return:{$return->id}:{$kind}",
            'description' => $kind === 'earned'
                ? "Reversión proporcional de puntos por devolución {$return->return_number}"
                : "Restauración proporcional de puntos por devolución {$return->return_number}",
            'effective_at' => $return->returned_at ?? now(),
            'metadata' => [
                'kind' => $kind,
                'sale_id' => $sale->id,
                'sale_number' => $sale->sale_number,
                'return_id' => $return->id,
                'return_number' => $return->return_number,
                'return_reason' => $return->reason,
                ...$proportion,
            ],
        ];
    }

    private function isPositive(string $value): bool
    {
        return bccomp($value, '0', self::SCALE) > 0;
    }

    private function normalize(string $value): string
    {
        return bcadd(trim($value) === '' ? '0' : $value, '0', self::SCALE);
    }

    /**
     * Redondeo half-up explícito a 4 decimales sobre un valor positivo en texto decimal.
     */
    private function round4(string $value): string
    {
        $floored = bcadd($value, '0', self::SCALE);
        $remainder = bcsub($value, $floored, 8);

        if (bccomp($remainder, '0.00005', 8) >= 0) {
            $floored = bcadd($floored, '0.0001', self::SCALE);
        }

        return $floored;
    }
}
