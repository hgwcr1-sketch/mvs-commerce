<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreQuoteRequest;
use App\Models\Quote;
use App\Services\Sales\QuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class QuoteController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): View
    {
        $quotes = Quote::query()->where('company_id', session('active_company_id'))->where('branch_id', session('active_branch_id'))->with(['customer', 'user'])->latest()->paginate(20);

        return view('quotes.index', compact('quotes'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreQuoteRequest $request, QuoteService $service): JsonResponse
    {
        $quote = $service->create($request->validated(), $request->user(), (int) session('active_company_id'), (int) session('active_branch_id'));

        return response()->json(['success' => true, 'message' => "Cotización {$quote->quote_number} creada correctamente.", 'quote_id' => $quote->id, 'quote_number' => $quote->quote_number, 'print_url' => route('cotizaciones.print', $quote)], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Quote $cotizacione): View
    {
        $quote = $this->scoped($cotizacione)->load(['items', 'customer', 'user', 'branch', 'cancelledBy', 'convertedSale']);

        return view('quotes.show', compact('quote'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function print(Quote $quote): View
    {
        $quote = $this->scoped($quote)->load(['items', 'customer', 'user', 'branch', 'company']);

        return view('quotes.print', compact('quote'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function load(Quote $quote): JsonResponse
    {
        $quote = $this->scoped($quote)->load(['items.product', 'customer']);
        abort_unless($quote->status === Quote::STATUS_ACTIVE, 409, 'La cotización no está activa.');
        abort_if($quote->expires_at?->isBefore(today()), 409, 'La cotización está vencida.');

        return response()->json(['quote_id' => $quote->id, 'quote_number' => $quote->quote_number, 'customer' => $quote->customer,
            'items' => $quote->items->map(fn ($item) => ['product_id' => $item->product_id, 'name' => $item->description, 'code' => $item->product_code, 'barcode' => $item->barcode, 'quantity' => (float) $item->quantity, 'unit_price' => (float) $item->unit_price, 'discount_total' => (float) $item->discount_total, 'tax_rate' => (float) $item->tax_rate, 'total' => (float) $item->total])->values(),
            'subtotal' => (float) $quote->subtotal, 'discount_total' => (float) $quote->discount_total, 'tax_total' => (float) $quote->tax_total, 'total' => (float) $quote->total]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function cancel(Request $request, Quote $quote): RedirectResponse
    {
        $quote = $this->scoped($quote);
        $data = $request->validate(['cancellation_reason' => ['required', 'string', 'min:3', 'max:255']]);
        abort_unless($quote->status === Quote::STATUS_ACTIVE, 409, 'La cotización no está activa.');
        $quote->update(['status' => Quote::STATUS_CANCELLED, 'cancelled_by' => $request->user()->id, 'cancelled_at' => now(), 'cancellation_reason' => $data['cancellation_reason']]);

        return back()->with('success', 'Cotización cancelada correctamente.');
    }

    private function scoped(Quote $quote): Quote
    {
        abort_unless((int) $quote->company_id === (int) session('active_company_id') && (int) $quote->branch_id === (int) session('active_branch_id'), 404);

        return $quote;
    }
}
