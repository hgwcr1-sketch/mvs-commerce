<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSaleReturnRequest;
use App\Models\Sale;
use App\Models\SaleReturnItem;
use App\Services\Sales\SaleReturnService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ReturnController extends Controller
{
    /**
     * Muestra el formulario para iniciar una devolución de una venta.
     */
    public function create(Sale $venta): View
    {
        $this->assertIsolatedContext($venta);

        $venta->load('items.product.unit');

        $lines = [];

        foreach ($venta->items as $item) {
            $alreadyReturned = (float) SaleReturnItem::query()
                ->where('sale_item_id', $item->id)
                ->sum('quantity');

            $lines[] = [
                'item' => $item,
                'sold' => (float) $item->quantity,
                'returned' => $alreadyReturned,
                'pending' => max(0.0, (float) $item->quantity - $alreadyReturned),
                'allows_decimals' => (bool) $item->product?->unit?->allows_decimals,
            ];
        }

        return view('devoluciones.crear', [
            'sale' => $venta,
            'lines' => $lines,
        ]);
    }

    /**
     * Registra la devolución de mercancía de la venta.
     */
    public function store(
        Sale $venta,
        StoreSaleReturnRequest $request,
        SaleReturnService $service,
    ): RedirectResponse {
        $this->assertIsolatedContext($venta);

        $saleReturn = $service->store(
            $venta,
            $request->user(),
            $request->validated('reason'),
            $request->validated('items'),
        );

        return redirect()
            ->route('ventas.show', $venta)
            ->with(
                'success',
                "Devolución {$saleReturn->return_number} registrada correctamente.",
            );
    }

    private function assertIsolatedContext(Sale $venta): void
    {
        if (
            (int) $venta->company_id !== (int) session('active_company_id')
            || (int) $venta->branch_id !== (int) session('active_branch_id')
        ) {
            abort(404);
        }
    }
}
