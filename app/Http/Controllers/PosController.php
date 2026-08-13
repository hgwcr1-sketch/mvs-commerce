<?php

namespace App\Http\Controllers;

use App\Http\Requests\QuickStoreCustomerRequest;
use App\Http\Requests\StorePosSaleRequest;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Services\Sales\PosSaleProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PosController extends Controller
{
    public function index(Request $request): View
    {
        $companyId = (int) session('active_company_id');
        $branchId = (int) session('active_branch_id');

        $company = Company::query()->findOrFail($companyId);
        $branch = Branch::query()
            ->where('company_id', $companyId)
            ->findOrFail($branchId);
        $paymentMethods = PaymentMethod::forCompany($companyId)
            ->active()
            ->ordered()
            ->get(['id', 'code', 'name', 'type', 'allows_change', 'requires_reference']);

        return view('pos.index', [
            'company' => $company,
            'branch' => $branch,
            'cashier' => $request->user(),
            'paymentMethods' => $paymentMethods,
        ]);
    }

    public function searchProducts(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('q', ''));

        if ($search === '') {
            return response()->json([]);
        }

        $search = mb_substr($search, 0, 100);
        $companyId = (int) session('active_company_id');
        $branchId = (int) session('active_branch_id');
        $like = '%'.$search.'%';
        $canViewOtherBranches = $request->user()->hasPermission(
            'inventario.ver_otras_sucursales',
            Company::query()->findOrFail($companyId),
        );

        $products = Product::query()
            ->where('products.company_id', $companyId)
            ->where('products.is_active', true)
            ->where(function ($query) use ($like) {
                $query->where('products.name', 'like', $like)
                    ->orWhere('products.internal_code', 'like', $like)
                    ->orWhere('products.barcode', 'like', $like)
                    ->orWhereHas('barcodes', function ($barcodeQuery) use ($like) {
                        $barcodeQuery
                            ->where('is_active', true)
                            ->where('barcode', 'like', $like);
                    });
            })
            ->with(['barcodes' => function ($query) use ($like) {
                $query
                    ->where('is_active', true)
                    ->where('barcode', 'like', $like)
                    ->select(['id', 'product_id', 'barcode']);
            }])
            ->select([
                'products.id',
                'products.name',
                'products.internal_code',
                'products.barcode',
                'products.image',
                'products.sale_price',
                'products.tax_rate',
                'products.track_inventory',
            ])
            ->addSelect([
                'available_stock' => DB::table('branch_product')
                    ->select('stock')
                    ->whereColumn('branch_product.product_id', 'products.id')
                    ->where('branch_product.branch_id', $branchId)
                    ->limit(1),
            ])
            ->orderByRaw(
                'CASE WHEN products.track_inventory = ? OR COALESCE((SELECT branch_product.stock FROM branch_product WHERE branch_product.product_id = products.id AND branch_product.branch_id = ? LIMIT 1), 0) > 0 THEN 0 ELSE 1 END',
                [false, $branchId],
            )
            ->orderByRaw(
                'CASE WHEN products.barcode = ? OR EXISTS (SELECT 1 FROM product_barcodes WHERE product_barcodes.product_id = products.id AND product_barcodes.is_active = ? AND product_barcodes.barcode = ?) THEN 0 ELSE 1 END',
                [$search, true, $search],
            )
            ->orderBy('products.name')
            ->limit(10)
            ->get();

        $otherBranchStock = collect();

        if ($canViewOtherBranches && $products->isNotEmpty()) {
            $otherBranchStock = DB::table('branch_product')
                ->join('branches', 'branches.id', '=', 'branch_product.branch_id')
                ->whereIn('branch_product.product_id', $products->pluck('id'))
                ->where('branches.company_id', $companyId)
                ->where('branches.is_active', true)
                ->where('branches.id', '!=', $branchId)
                ->where('branch_product.stock', '>', 0)
                ->orderBy('branches.name')
                ->get([
                    'branch_product.product_id',
                    'branches.id as branch_id',
                    'branches.name as branch_name',
                    'branch_product.stock as available_stock',
                ])
                ->groupBy('product_id');
        }

        return response()->json($products->map(function (Product $product) use (
            $search,
            $canViewOtherBranches,
            $otherBranchStock,
        ) {
            $matchedBarcode = null;

            if ($product->barcode !== null && str_contains(mb_strtolower($product->barcode), mb_strtolower($search))) {
                $matchedBarcode = $product->barcode;
            } elseif ($product->barcodes->isNotEmpty()) {
                $matchedBarcode = $product->barcodes->first()->barcode;
            }

            $availableStock = (float) ($product->available_stock ?? 0);
            $imagePath = $this->safeProductImagePath($product->image);
            $hasImage = $imagePath !== null && Storage::disk('public')->exists($imagePath);
            $result = [
                'id' => $product->id,
                'name' => $product->name,
                'internal_code' => $product->internal_code,
                'matched_barcode' => $matchedBarcode,
                'sale_price' => (float) $product->sale_price,
                'tax_rate' => (float) ($product->tax_rate ?? 0),
                'controls_inventory' => (bool) $product->track_inventory,
                'available_stock' => $availableStock,
                'can_add_to_cart' => !$product->track_inventory || $availableStock > 0,
                'has_image' => $hasImage,
                'image_url' => $hasImage ? Storage::disk('public')->url($imagePath) : null,
            ];

            if ($canViewOtherBranches) {
                $result['other_branch_stock'] = collect($otherBranchStock->get($product->id, []))
                    ->map(fn ($stock) => [
                        'branch_id' => (int) $stock->branch_id,
                        'branch_name' => $stock->branch_name,
                        'available_stock' => (float) $stock->available_stock,
                    ])
                    ->values()
                    ->all();
            }

            return $result;
        })->values());
    }

    public function searchCustomers(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('q', ''));

        if ($search === '') {
            return response()->json([]);
        }

        $search = mb_substr($search, 0, 100);
        $like = '%'.$search.'%';

        $customers = Customer::forCompany((int) session('active_company_id'))
            ->where('is_active', true)
            ->where(function ($query) use ($like) {
                $query->where('name', 'like', $like)
                    ->orWhere('identification', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('mobile', 'like', $like)
                    ->orWhere('email', 'like', $like);
            })
            ->orderByRaw('CASE WHEN identification = ? THEN 0 ELSE 1 END', [$search])
            ->orderBy('name')
            ->limit(10)
            ->get([
                'id',
                'name',
                'identification',
                'phone',
                'mobile',
                'email',
                'customer_type',
                'credit_limit',
                'credit_days',
            ]);

        return response()->json($customers->map(fn (Customer $customer) => [
            'id' => $customer->id,
            'name' => $customer->name,
            'identification' => $customer->identification,
            'phone' => $customer->phone,
            'mobile' => $customer->mobile,
            'email' => $customer->email,
            'customer_type' => $customer->customer_type,
            'credit_limit' => (float) $customer->credit_limit,
            'credit_days' => (int) ($customer->credit_days ?? 0),
        ])->values());
    }

    public function storeQuickCustomer(QuickStoreCustomerRequest $request): JsonResponse
    {
        $data = $request->validated();

        $customer = Customer::create([
            'company_id' => (int) session('active_company_id'),
            'name' => $data['name'],
            'customer_type' => $data['customer_type'],
            'identification_type' => $data['identification_type'] ?? null,
            'identification' => $data['identification'] ?? null,
            'phone' => $data['phone'] ?? null,
            'mobile' => $data['mobile'] ?? null,
            'email' => $data['email'] ?? null,
            'accepts_email_invoice' => false,
            'credit_limit' => 0,
            'credit_days' => 0,
            'points' => 0,
            'is_active' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Cliente creado correctamente.',
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'identification' => $customer->identification,
                'phone' => $customer->phone,
                'mobile' => $customer->mobile,
                'email' => $customer->email,
                'customer_type' => $customer->customer_type,
            ],
        ], 201);
    }

    public function checkout(StorePosSaleRequest $request, PosSaleProcessor $processor): JsonResponse
    {
        try {
            $result = $processor->process(
                $request->validated(),
                $request->user(),
                (int) session('active_company_id'),
                (int) session('active_branch_id'),
            );
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => collect($exception->errors())->flatten()->first() ?? 'El cobro contiene datos inválidos.',
                'errors' => $exception->errors(),
            ], 422);
        }
        $sale = $result['sale']->load('payments.paymentMethod');
        $payments = $sale->payments;
        $firstPayment = $payments->first();

        return response()->json([
            'success' => true,
            'duplicate' => $result['duplicate'],
            'message' => $result['duplicate'] ? 'Esta venta ya había sido procesada.' : 'Venta cobrada correctamente.',
            'sale_id' => $sale->id,
            'sale_number' => $sale->sale_number,
            'subtotal' => $sale->subtotal,
            'tax_total' => $sale->tax_total,
            'rounding_total' => $sale->rounding_total,
            'total' => $sale->total,
            'paid_total' => $sale->paid_total,
            'total_change' => number_format($payments->sum(fn ($payment) => (float) $payment->change_amount), 4, '.', ''),
            'payments' => $payments->map(fn ($payment) => [
                'method_name' => $payment->paymentMethod->name,
                'amount' => $payment->amount,
                'received_amount' => $payment->received_amount,
                'change_amount' => $payment->change_amount,
                'reference' => $payment->reference,
            ])->values(),
            'received_amount' => $firstPayment?->received_amount,
            'change_amount' => $firstPayment?->change_amount,
            'receipt_url' => route('pos.receipt', $sale),
        ]);
    }

    public function receipt(Request $request, Sale $sale): View
    {
        $companyId = (int) session('active_company_id');

        if ((int) $sale->company_id !== $companyId) {
            abort(404);
        }

        $company = Company::query()->findOrFail($companyId);
        $isCreator = (int) $sale->user_id === (int) $request->user()->id;

        if (!$isCreator && !$request->user()->hasPermission('ventas.ver', $company)) {
            abort(403);
        }

        $sale->load(['branch', 'user', 'customer', 'items', 'payments.paymentMethod']);

        return view('pos.receipt', compact('sale', 'company'));
    }

    private function safeProductImagePath(?string $image): ?string
    {
        if ($image === null) {
            return null;
        }

        $path = str_replace('\\', '/', trim($image));

        if ($path === '' || !str_starts_with($path, 'products/') || str_contains($path, '..')) {
            return null;
        }

        return $path;
    }
}
