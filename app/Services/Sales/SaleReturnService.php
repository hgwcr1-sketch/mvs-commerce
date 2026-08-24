<?php

namespace App\Services\Sales;

use App\Models\CompanySequence;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\User;
use App\Services\Inventory\InventoryPostingService;
use App\Services\Loyalty\LoyaltySaleReturnAdjustmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleReturnService
{
    /**
     * Tolerancia numérica al comparar cantidades decimales (19,4).
     */
    private const QTY_EPSILON = 0.0001;

    public function __construct(
        private readonly InventoryPostingService $inventoryPostingService,
        private readonly LoyaltySaleReturnAdjustmentService $loyaltyAdjustments,
    ) {
    }

    /**
     * Registra una devolución de mercancía sobre una venta completada
     * o parcialmente devuelta. NO realiza reembolso financiero ni de caja.
     *
     * @param  array<int, array{sale_item_id: int, quantity: float|string}>  $lines
     */
    public function store(Sale $sale, User $user, string $reason, array $lines): SaleReturn
    {
        return DB::transaction(function () use ($sale, $user, $reason, $lines) {
            $sale = Sale::query()
                ->whereKey($sale->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $sale->company_id !== (int) session('active_company_id')) {
                abort(404);
            }

            if ((int) $sale->branch_id !== (int) session('active_branch_id')) {
                abort(404);
            }

            if (! in_array($sale->status, [
                Sale::STATUS_COMPLETED,
                Sale::STATUS_PARTIALLY_RETURNED,
            ], true)) {
                throw ValidationException::withMessages([
                    'sale' => 'Solo se pueden devolver ventas completadas o parcialmente devueltas.',
                ]);
            }

            $reason = trim($reason);

            if ($reason === '') {
                throw ValidationException::withMessages([
                    'reason' => 'Debe indicar el motivo de la devolución.',
                ]);
            }

            $sale->load('items.product.unit');

            // Cantidad ya devuelta por línea, calculada dentro de la transacción.
            $previouslyReturned = $this->returnedQuantitiesBySaleItem($sale);

            // Importes financieros ya devueltos por línea, para el remanente final.
            $previouslyReturnedFinances = $this->returnedFinancialsBySaleItem($sale);

            $requested = $this->normalizeLineRequests($lines);

            // Validar pertenencia de líneas y límite de cantidad pendiente.
            foreach ($requested as $saleItemId => $quantity) {
                $item = $sale->items->firstWhere('id', $saleItemId);

                if ($item === null) {
                    throw ValidationException::withMessages([
                        'items' => 'Una o más líneas no pertenecen a la venta.',
                    ]);
                }

                if (! $item->product?->unit?->allows_decimals && floor($quantity) !== $quantity) {
                    throw ValidationException::withMessages([
                        'items' => "{$item->description} solo admite cantidades enteras.",
                    ]);
                }

                $already = (float) ($previouslyReturned[$saleItemId] ?? 0);
                $pending = (float) $item->quantity - $already;

                if ($quantity - self::QTY_EPSILON > $pending) {
                    throw ValidationException::withMessages([
                        'items' => "No se puede devolver más de lo pendiente para el producto {$item->description}.",
                    ]);
                }
            }

            // Crear primero el encabezado: aporta el ID para líneas e inventario.
            $saleReturn = SaleReturn::create([
                'company_id' => $sale->company_id,
                'branch_id' => $sale->branch_id,
                'sale_id' => $sale->id,
                'user_id' => $user->id,
                'return_number' => CompanySequence::nextSaleReturnNumber(
                    (int) $sale->company_id,
                ),
                'reason' => $reason,
                'status' => SaleReturn::STATUS_COMPLETED,
                'returned_at' => now(),
            ]);
foreach ($sale->items as $item) {
                if (! isset($requested[$item->id])) {
                    continue;
                }

                $quantity = round((float) $requested[$item->id], 4);

                $previousQty = (float) ($previouslyReturned[$item->id] ?? 0);
                $cumulativeQty = $previousQty + $quantity;
                $completesLine = ((float) $item->quantity - $cumulativeQty) <= self::QTY_EPSILON;

                if ($completesLine) {
                    // La última devolución absorbe el remanente financiero exacto.
                    $prev = $previouslyReturnedFinances[$item->id] ?? [
                        'gross' => 0,
                        'discount' => 0,
                        'subtotal' => 0,
                        'tax' => 0,
                        'total' => 0,
                    ];

                    $grossTotal = max(0.0, round((float) $item->gross_total - (float) $prev['gross'], 4));
                    $discountTotal = max(0.0, round((float) $item->discount_total - (float) $prev['discount'], 4));
                    $subtotal = max(0.0, round((float) $item->subtotal - (float) $prev['subtotal'], 4));
                    $taxTotal = max(0.0, round((float) $item->tax_total - (float) $prev['tax'], 4));
                    $total = max(0.0, round((float) $item->total - (float) $prev['total'], 4));
                } else {
                    // Devolución parcial: prorratear los importes REALES de SaleItem.
                    $ratio = $quantity / (float) $item->quantity;

                    $grossTotal = round((float) $item->gross_total * $ratio, 4);
                    $discountTotal = round((float) $item->discount_total * $ratio, 4);
                    $subtotal = round((float) $item->subtotal * $ratio, 4);
                    $taxTotal = round((float) $item->tax_total * $ratio, 4);
                    $total = round((float) $item->total * $ratio, 4);
                }

                SaleReturnItem::create([
                    'sale_return_id' => $saleReturn->id,
                    'sale_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'quantity' => $quantity,
                    'unit_price' => $item->unit_price,
                    'gross_total' => $grossTotal,
                    'discount_total' => $discountTotal,
                    'subtotal' => $subtotal,
                    'tax_rate' => $item->tax_rate,
                    'tax_total' => $taxTotal,
                    'total' => $total,
                ]);

                if ($item->product !== null && $item->product->track_inventory) {
                    $this->inventoryPostingService->saleReturn(
                        $sale,
                        $saleReturn,
                        $item->product,
                        $quantity,
                        $user->id,
                    );
                }
            }

            $totalReturned = $this->returnedByLineAfterSale(
                $sale,
                $previouslyReturned,
                $requested,
            );

            $hasPending = false;

            foreach ($sale->items as $item) {
                $returned = (float) ($totalReturned[$item->id] ?? 0);

                if ((float) $item->quantity - $returned > self::QTY_EPSILON) {
                    $hasPending = true;
                    break;
                }
            }

            $this->loyaltyAdjustments->adjust($sale, $saleReturn, $user);

            $sale->update([
                'status' => $hasPending
                    ? Sale::STATUS_PARTIALLY_RETURNED
                    : Sale::STATUS_RETURNED,
            ]);

            return $saleReturn->fresh(['items']);
        });
    }

    /**
     * @return array<int, float>
     */
    private function returnedQuantitiesBySaleItem(Sale $sale): array
    {
        $rows = SaleReturnItem::query()
            ->join('sale_returns', 'sale_returns.id', '=', 'sale_return_items.sale_return_id')
            ->where('sale_returns.sale_id', $sale->id)
            ->selectRaw('sale_return_items.sale_item_id, SUM(sale_return_items.quantity) as total_returned')
            ->groupBy('sale_return_items.sale_item_id')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $result[(int) $row->sale_item_id] = (float) $row->total_returned;
        }

        return $result;
    }

    /**
     * Importes financieros reales ya devueltos, agregados por línea de venta.
     *
     * @return array<int, array{gross: float, discount: float, subtotal: float, tax: float, total: float}>
     */
    private function returnedFinancialsBySaleItem(Sale $sale): array
    {
        $rows = SaleReturnItem::query()
            ->join('sale_returns', 'sale_returns.id', '=', 'sale_return_items.sale_return_id')
            ->where('sale_returns.sale_id', $sale->id)
            ->selectRaw(
                'sale_return_items.sale_item_id, '
                .'SUM(sale_return_items.gross_total) as gross, '
                .'SUM(sale_return_items.discount_total) as discount, '
                .'SUM(sale_return_items.subtotal) as subtotal, '
                .'SUM(sale_return_items.tax_total) as tax, '
                .'SUM(sale_return_items.total) as total'
            )
            ->groupBy('sale_return_items.sale_item_id')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $result[(int) $row->sale_item_id] = [
                'gross' => (float) $row->gross,
                'discount' => (float) $row->discount,
                'subtotal' => (float) $row->subtotal,
                'tax' => (float) $row->tax,
                'total' => (float) $row->total,
            ];
        }

        return $result;
    }

    /**
     * @param  array<int, array{sale_item_id: int, quantity: float|string}>  $lines
     * @return array<int, float>
     */
    private function normalizeLineRequests(array $lines): array
    {
        $requested = [];

        foreach ($lines as $line) {
            $saleItemId = (int) $line['sale_item_id'];
            $quantity = (float) $line['quantity'];

            if ($quantity <= 0) {
                throw ValidationException::withMessages([
                    'items' => 'La cantidad a devolver debe ser mayor que cero.',
                ]);
            }

            $requested[$saleItemId] = ($requested[$saleItemId] ?? 0) + $quantity;
        }

        return $requested;
    }

    /**
     * @param  array<int, float>  $previouslyReturned
     * @param  array<int, float>  $requested
     * @return array<int, float>
     */
    private function returnedByLineAfterSale(Sale $sale, array $previouslyReturned, array $requested): array
    {
        $totals = [];

        foreach ($sale->items as $item) {
            $totals[$item->id] = (float) ($previouslyReturned[$item->id] ?? 0)
                + (float) ($requested[$item->id] ?? 0);
        }

        return $totals;
    }
}
