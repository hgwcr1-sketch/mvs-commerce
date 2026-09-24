<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSaleReturnRequest;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Sale;
use App\Models\SaleReturnItem;
use App\Services\Sales\IssuedCreditNoteResult;
use App\Services\Sales\SaleReturnService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class ReturnController extends Controller
{
    /**
     * Muestra el formulario para iniciar una devolución de una venta.
     */
    public function create(Sale $venta): View
    {
        $this->assertIsolatedContext($venta);

        $venta->load(['items.product.unit', 'items.product.style', 'items.product.size', 'items.product.color']);

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

        $creditNoteResult = null;
        $saleReturn = $service->store(
            $venta,
            $request->user(),
            $request->validated('reason'),
            $request->validated('items'),
            $creditNoteResult,
        );

        if ($creditNoteResult instanceof IssuedCreditNoteResult && $creditNoteResult->applicationCode !== null) {
            $note = $creditNoteResult->creditNote;

            return redirect()
                ->route('notas-credito.consumer-final.delivered')
                ->with('consumer_final_delivery', [
                    'credit_note_number' => $note->credit_note_number,
                    'application_code' => $creditNoteResult->applicationCode,
                    'issued_amount' => $note->issued_amount,
                    'issued_at' => optional($note->issued_at)->toDateTimeString(),
                    'expires_at' => optional($note->expires_at)->toDateString(),
                    'sale_id' => $venta->id,
                    'sale_return_number' => $saleReturn->return_number,
                ]);
        }

        return redirect()
            ->route('ventas.show', $venta)
            ->with(
                'success',
                "Devolución {$saleReturn->return_number} registrada correctamente.",
            );
    }

    /**
     * Entrega única del código de aplicación de una NC Consumer Final.
     *
     * El plaintext vive únicamente en el flash session de la respuesta de la
     * devolución y se consume en este GET: un refresh posterior redirige sin
     * volver a mostrar el código. La respuesta usa Cache-Control: no-store
     * para que el navegador no cachee la página que muestra el secreto.
     */
    public function delivered(Request $request): Response|RedirectResponse
    {
        $delivery = session('consumer_final_delivery');

        if (! is_array($delivery) || empty($delivery['application_code'])) {
            return redirect()->route('dashboard');
        }

        $company = Company::find((int) session('active_company_id'));
        $branch = Branch::find((int) session('active_branch_id'));

        return response(view('notas-credito.consumer-final.delivered', [
            'delivery' => $delivery,
            'company' => $company,
            'branch' => $branch,
        ]))
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
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
