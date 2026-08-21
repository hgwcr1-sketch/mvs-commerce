<?php

namespace App\Http\Controllers;

use App\Http\Requests\CancelQuoteRequest;
use App\Http\Requests\StoreQuoteRequest;
use App\Models\Company;
use App\Models\Quote;
use App\Services\Quotes\QuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class QuoteController extends Controller
{
    public function __construct(
        private readonly QuoteService $quoteService,
    ) {
    }

    public function index(Request $request): View
    {
        $companyId = (int) session('active_company_id');
        $branchId = (int) session('active_branch_id');

        $quotes = Quote::query()
            ->with('user', 'customer')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->orderByDesc('id')
            ->paginate(25);

        return view('cotizaciones.index', compact('quotes'));
    }

    public function create(Request $request)
    {
        return redirect()->route('pos.index');
    }

    public function store(StoreQuoteRequest $request, QuoteService $service): JsonResponse
    {
        try {
            $quote = $service->create(
                $request->validated(),
                $request->user(),
                (int) session('active_company_id'),
                (int) session('active_branch_id'),
            );
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => collect($exception->errors())->flatten()->first() ?? 'No fue posible crear la cotización.',
                'errors' => $exception->errors(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => "Cotización {$quote->quote_number} creada correctamente.",
            'quote' => [
                'id' => $quote->id,
                'quote_number' => $quote->quote_number,
                'total' => $quote->total,
                'expires_at' => $quote->expires_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function show(Request $request, Quote $quote): View
    {
        $this->assertCompanyScope($quote);

        $quote->load(['branch', 'user', 'customer', 'items']);
        $company = Company::query()->findOrFail((int) session('active_company_id'));

        return view('cotizaciones.show', compact('quote', 'company'));
    }

    public function load(Quote $quote): JsonResponse
    {
        $this->assertCompanyScope($quote);

        if (! $quote->isActive()) {
            return response()->json(['message' => 'La cotización no está activa.'], 422);
        }

        $quote->load('items', 'customer');

        return response()->json([
            'quote' => [
                'id' => $quote->id,
                'quote_number' => $quote->quote_number,
                'customer_id' => $quote->customer_id,
                'customer_name' => $quote->customer?->name,
            ],
            'items' => $quote->items->map(function ($item) {
                return [
                    'product_id' => (int) $item->product_id,
                    'product_name' => $item->description,
                    'product_code' => $item->product_code,
                    'barcode' => $item->barcode,
                    'quantity' => (float) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'discount_total' => (float) $item->discount_total,
                    'tax_rate' => (float) $item->tax_rate,
                    'tax_total' => (float) $item->tax_total,
                    'total' => (float) $item->total,
                ];
            })->all(),
            'totals' => [
                'subtotal' => (float) $quote->subtotal,
                'discount_total' => (float) $quote->discount_total,
                'tax_total' => (float) $quote->tax_total,
                'total' => (float) $quote->total,
            ],
        ]);
    }

    public function edit(Request $request, Quote $quote): View
    {
        $this->assertCompanyScope($quote);

        return view('cotizaciones.edit', compact('quote'));
    }

    public function update(CancelQuoteRequest $request, Quote $quote): JsonResponse
    {
        $this->assertCompanyScope($quote);

        try {
            $this->quoteService->cancel(
                $quote,
                $request->user(),
                (int) session('active_company_id'),
                $request->validated('cancellation_reason'),
            );
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => collect($exception->errors())->flatten()->first() ?? 'No fue posible cancelar la cotización.',
                'errors' => $exception->errors(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => "Cotización {$quote->quote_number} cancelada correctamente.",
        ]);
    }

    public function print(Request $request, Quote $quote)
    {
        $this->assertCompanyScope($quote);

        $quote->load(['branch', 'user', 'customer', 'items']);
        $company = Company::query()->findOrFail((int) session('active_company_id'));

        return view('cotizaciones.print', compact('quote', 'company'));
    }

    private function assertCompanyScope(Quote $quote): void
    {
        if ((int) $quote->company_id !== (int) session('active_company_id')) {
            abort(404);
        }
    }
}
