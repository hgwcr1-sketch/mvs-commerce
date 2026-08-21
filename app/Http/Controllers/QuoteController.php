<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreQuoteRequest;
use App\Models\Quote;
use App\Services\Sales\QuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class QuoteController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'number' => ['nullable', 'string', 'max:50'],
            'customer' => ['nullable', 'string', 'max:150'],
            'date' => ['nullable', 'date'],
            'status' => ['nullable', 'in:active,expired,converted,cancelled'],
        ]);

        $quotes = Quote::query()
            ->where('company_id', session('active_company_id'))
            ->where('branch_id', session('active_branch_id'))
            ->with(['customer', 'user'])
            ->when($filters['number'] ?? null, fn ($query, $number) => $query->where('quote_number', 'like', '%'.$number.'%'))
            ->when($filters['customer'] ?? null, function ($query, $customer) {
                $query->whereHas('customer', fn ($customerQuery) => $customerQuery->where('name', 'like', '%'.$customer.'%'));
            })
            ->when($filters['date'] ?? null, fn ($query, $date) => $query->whereDate('created_at', $date))
            ->when($filters['status'] ?? null, function ($query, $status) {
                if ($status === 'expired') {
                    $query->where('status', Quote::STATUS_ACTIVE)->whereDate('expires_at', '<', today());
                } elseif ($status === Quote::STATUS_ACTIVE) {
                    $query->where('status', Quote::STATUS_ACTIVE)
                        ->where(fn ($active) => $active->whereNull('expires_at')->orWhereDate('expires_at', '>=', today()));
                } else {
                    $query->where('status', $status);
                }
            })
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('quotes.index', compact('quotes', 'filters'));
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
        $quote = $this->scoped($quote)->load(['items.product.unit', 'customer']);
        abort_unless($quote->status === Quote::STATUS_ACTIVE, 409, 'La cotización no está activa.');
        abort_if($quote->expires_at?->isBefore(today()), 409, 'La cotización está vencida.');

        $stocks = DB::table('branch_product')
            ->where('branch_id', $quote->branch_id)
            ->whereIn('product_id', $quote->items->pluck('product_id')->filter())
            ->pluck('stock', 'product_id');

        return response()->json(['quote_id' => $quote->id, 'quote_number' => $quote->quote_number, 'customer' => $quote->customer,
            'items' => $quote->items->map(function ($item) use ($stocks) {
                $product = $item->product;

                return ['product_id' => $item->product_id, 'name' => $item->description, 'code' => $item->product_code, 'barcode' => $item->barcode, 'quantity' => (float) $item->quantity, 'unit_price' => (float) $item->unit_price, 'discount_total' => (float) $item->discount_total, 'tax_rate' => (float) ($product?->tax_rate ?? 0), 'total' => (float) $item->total,
                    'sale_price' => (float) ($product?->sale_price ?? $item->unit_price), 'wholesale_price' => $product?->wholesale_price !== null ? (float) $product->wholesale_price : null, 'price_a' => $product?->price_a !== null ? (float) $product->price_a : null, 'price_b' => $product?->price_b !== null ? (float) $product->price_b : null, 'price_c' => $product?->price_c !== null ? (float) $product->price_c : null,
                    'available_stock' => (float) ($stocks[$item->product_id] ?? 0), 'controls_inventory' => (bool) $product?->track_inventory, 'allows_decimals' => (bool) $product?->unit?->allows_decimals, 'unavailable' => ! $product?->is_active];
            })->values(),
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
