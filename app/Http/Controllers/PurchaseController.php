<?php

namespace App\Http\Controllers;

use App\Data\Purchases\PurchaseData;
use App\Data\Purchases\PurchaseLineData;
use App\Models\InventoryMovement;
use App\Models\PurchaseItem;
use Illuminate\Support\Facades\Auth;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\ProductCategory;
use App\Models\Brand;
use App\Models\Unit;
use App\Services\Purchases\PurchaseProcessor;
use App\Services\Purchases\PurchaseAccountPayableService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseController extends Controller
{
    /**
     * Listado de compras de la sucursal activa.
     */
    public function index()
    {
        $companyId = session('active_company_id');
        $branchId = session('active_branch_id');

        $purchases = Purchase::query()
            ->with([
                'supplier:id,name,commercial_name',
                'branch:id,name',
                'user:id,name',
            ])
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->orderByDesc('purchase_date')
            ->orderByDesc('id')
            ->paginate(20);

        return view('compras.index', compact('purchases'));
    }

    /**
     * Formulario para registrar una nueva compra.
     */
    public function create()
{
    $companyId = session('active_company_id');

    $categories = ProductCategory::where('company_id', $companyId)
        ->where('is_active', true)
        ->orderBy('name')
        ->get();

    $brands = Brand::where('company_id', $companyId)
        ->where('is_active', true)
        ->orderBy('name')
        ->get();

    $units = Unit::where('company_id', $companyId)
        ->where('is_active', true)
        ->orderBy('name')
        ->get();

    return view('compras.create', compact(
        'categories',
        'brands',
        'units'
    ));
}

    /**
     * Buscador interactivo de productos para Compras.
     */
    public function searchProducts(Request $request)
    {
        $companyId = session('active_company_id');
        $branchId = session('active_branch_id');

        $search = trim((string) $request->get('q', ''));

        if ($search === '') {
            return response()->json([]);
        }

        $products = Product::query()
            ->where('products.company_id', $companyId)
            ->where('products.is_active', true)
            ->where(function ($query) use ($search) {
                $query->where('products.name', 'like', "%{$search}%")
                    ->orWhere('products.internal_code', 'like', "%{$search}%")
                    ->orWhere('products.barcode', 'like', "%{$search}%")
                    ->orWhereHas('barcodes', function ($barcodeQuery) use ($search) {
                        $barcodeQuery
                            ->where('is_active', true)
                            ->where('barcode', 'like', "%{$search}%");
                    })
                    ->orWhereHas('brand', function ($brandQuery) use ($search) {
                        $brandQuery->where('name', 'like', "%{$search}%");
                    });
            })
            ->with([
                'brand:id,name',
                'category:id,name',
                'unit:id,name,allows_decimals',
                'barcodes' => function ($query) {
                    $query
                        ->where('is_active', true)
                        ->select([
                            'id',
                            'product_id',
                            'barcode',
                            'barcode_type',
                            'is_primary',
                        ]);
                },
            ])
            ->orderBy('products.name')
            ->limit(15)
            ->get([
                'products.id',
                'products.category_id',
                'products.brand_id',
                'products.unit_id',
                'products.name',
                'products.internal_code',
                'products.barcode',
                'products.cost',
                'products.sale_price',
                'products.tax_rate',
                'products.track_inventory',
            ]);

        $productIds = $products->pluck('id');

        $stocks = DB::table('branch_product')
            ->where('branch_id', $branchId)
            ->whereIn('product_id', $productIds)
            ->pluck('stock', 'product_id');

        $result = $products->map(function ($product) use ($stocks) {
            return [
                'id' => $product->id,
                'name' => $product->name,
                'internal_code' => $product->internal_code,
                'barcode' => $product->barcode,
                'barcodes' => $product->barcodes->map(function ($barcode) {
                    return [
                        'id' => $barcode->id,
                        'barcode' => $barcode->barcode,
                        'barcode_type' => $barcode->barcode_type,
                        'is_primary' => $barcode->is_primary,
                    ];
                })->values(),
                'brand' => $product->brand?->name,
                'category' => $product->category?->name,
                'unit' => $product->unit?->name,
                'allows_decimals' => (bool) $product->unit?->allows_decimals,
                'cost' => (float) $product->cost,
                'sale_price' => (float) $product->sale_price,
                'tax_rate' => (float) $product->tax_rate,
                'track_inventory' => (bool) $product->track_inventory,
                'stock' => (float) ($stocks[$product->id] ?? 0),
            ];
        });

        return response()->json($result);
    }

    /**
     * Guardar compra.
     */
   public function store(
    Request $request,
    PurchaseProcessor $purchaseProcessor,
)
{
    $companyId = session('active_company_id');
    $branchId = session('active_branch_id');

    $data = $request->validate([
        'supplier_id' => ['required', 'integer'],
        'supplier_invoice_number' => ['nullable', 'string', 'max:255'],
        'purchase_date' => ['required', 'date'],
        'payment_type' => ['required', 'in:cash,credit'],
        'due_date' => ['nullable', 'date'],
        'notes' => ['nullable', 'string'],

        'items' => ['required', 'array', 'min:1'],
        'items.*.product_id' => ['required', 'integer'],
        'items.*.quantity' => ['required', 'numeric', 'gt:0'],
        'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
        'items.*.new_sale_price' => ['nullable', 'numeric', 'min:0'],
    ]);

    if (
        $data['payment_type'] === 'credit' &&
        empty($data['due_date'])
    ) {
        return response()->json([
            'message' => 'Debe indicar la fecha de vencimiento.',
        ], 422);
    }

    try {
        $lines = collect($data['items'])
            ->map(fn (array $item) => new PurchaseLineData(
                product_id: (int) $item['product_id'],
                quantity: (float) $item['quantity'],
                unit_cost: (float) $item['unit_cost'],
                new_sale_price: array_key_exists('new_sale_price', $item)
                    && $item['new_sale_price'] !== null
                    ? (float) $item['new_sale_price']
                    : null,
            ))
            ->all();

        $purchase = $purchaseProcessor->process(new PurchaseData(
            company_id: (int) $companyId,
            branch_id: (int) $branchId,
            supplier_id: (int) $data['supplier_id'],
            user_id: Auth::id(),
            purchase_date: $data['purchase_date'],
            payment_type: $data['payment_type'],
            supplier_invoice_number: $data['supplier_invoice_number'] ?? null,
            due_date: $data['due_date'] ?? null,
            notes: $data['notes'] ?? null,
            lines: $lines,
        ));

        return response()->json([
            'message' => 'Compra guardada correctamente.',
            'purchase_id' => $purchase->id,
            'number' => $purchase->number,
        ]);

    } catch (ValidationException $e) {

        return response()->json([
            'message' => collect($e->errors())->flatten()->first()
                ?? 'La compra contiene datos inválidos.',
        ], 422);

    } catch (ModelNotFoundException) {

        return response()->json([
            'message' => 'No se encontró un recurso válido para registrar la compra.',
        ], 422);

    } catch (\Throwable $e) {

        report($e);

        return response()->json([
            'message' => 'No se pudo guardar la compra.',
        ], 500);
    }
}

    /**
     * Mostrar compra.
     */
    public function show(string $id)
{
    $companyId = session('active_company_id');
    $branchId = session('active_branch_id');

    $purchase = Purchase::with([
        'supplier',
        'branch',
        'user',
            'items.product.unit',
    ])
    ->where('company_id', $companyId)
    ->where('branch_id', $branchId)
    ->findOrFail($id);

    return view('compras.show', compact('purchase'));
}   

/**
 * Generar PDF de compra.
 */
/**
 * Generar PDF de compra.
 */
public function pdf(string $id)
{
    $companyId = session('active_company_id');
    $branchId = session('active_branch_id');

    $purchase = Purchase::with([
        'supplier',
        'branch',
        'user',
        'items.product',
    ])
    ->where('company_id', $companyId)
    ->where('branch_id', $branchId)
    ->findOrFail($id);


    $company = \App\Models\Company::find(
    session('active_company_id')
);


$pdf = \PDF::loadView(
    'compras.pdf.show',
    compact(
        'purchase',
        'company'
    )
);


    return $pdf->download(
        'Compra-'.$purchase->number.'.pdf'
    );
}

/**
 * Vista de impresión profesional de compra.
 */
public function print(string $id)
{
    $companyId = session('active_company_id');
    $branchId = session('active_branch_id');


    $purchase = Purchase::with([
        'supplier',
        'branch',
        'user',
        'items.product',
    ])
    ->where('company_id', $companyId)
    ->where('branch_id', $branchId)
    ->findOrFail($id);


    $company = \App\Models\Company::find(
        session('active_company_id')
    );


    return view(
        'compras.print.show',
        compact(
            'purchase',
            'company'
        )
    );
}


/**
 * Editar compra.
 */
public function edit(string $id)
{
    $companyId = session('active_company_id');
    $branchId = session('active_branch_id');


    $purchase = Purchase::with([
        'supplier',
        'items.product',
    ])
    ->where('company_id', $companyId)
    ->where('branch_id', $branchId)
    ->findOrFail($id);


    if ($purchase->status === 'cancelled') {

        return redirect()
            ->route('compras.show', $purchase->id)
            ->with(
                'error',
                'No se puede editar una compra anulada.'
            );

    }


    $categories = ProductCategory::where('company_id', $companyId)
        ->where('is_active', true)
        ->orderBy('name')
        ->get();


    $brands = Brand::where('company_id', $companyId)
        ->where('is_active', true)
        ->orderBy('name')
        ->get();


    $units = Unit::where('company_id', $companyId)
        ->where('is_active', true)
        ->orderBy('name')
        ->get();


    return view('compras.edit', compact(
        'purchase',
        'categories',
        'brands',
        'units'
    ));
}

/**
 * Actualizar compra.
 */
public function update(Request $request, string $id)
{
    $companyId = session('active_company_id');
    $branchId = session('active_branch_id');
    $purchase = Purchase::where('company_id', $companyId)
        ->where('branch_id', $branchId)
        ->findOrFail($id);


    $data = $request->validate([

        'supplier_id' => ['required','integer'],

        'supplier_invoice_number' => [
            'nullable',
            'string',
            'max:255'
        ],

        'purchase_date' => [
            'required',
            'date'
        ],

        'payment_type' => [
            'required',
            'in:cash,credit'
        ],

        'due_date' => [
            'nullable',
            'date'
        ],

        'notes' => [
            'nullable',
            'string'
        ],

        'items' => [
            'required',
            'array',
            'min:1'
        ],

        'items.*.product_id' => [
            'required',
            'integer'
        ],

        'items.*.quantity' => [
            'required',
            'numeric',
            'gt:0'
        ],

        'items.*.unit_cost' => [
            'required',
            'numeric',
            'min:0'
        ],

        'items.*.new_sale_price' => [
            'nullable',
            'numeric',
            'min:0'
        ],

    ]);

    $supplierExists = Supplier::query()
        ->where('id', $data['supplier_id'])
        ->where('company_id', $companyId)
        ->exists();


    if (!$supplierExists) {

        return back()
            ->withErrors([
                'supplier_id' =>
                    'El proveedor no pertenece a la empresa activa.'
            ])
            ->withInput();

    }


    try {

        DB::transaction(function () use (
            $data,
            $id,
            $companyId,
            $branchId
        ) {


            $purchase = Purchase::with('items.product')
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->findOrFail($id);


            if ($purchase->status === 'cancelled') {

                throw new \Exception(
                    'No se puede editar una compra anulada.'
                );

            }


            /*
             * Revertir inventario anterior
             */
            foreach ($purchase->items as $oldItem) {

                $branchProduct = DB::table('branch_product')
                    ->where('branch_id', $branchId)
                    ->where('product_id', $oldItem->product_id)
                    ->first();


                if ($branchProduct) {

                    $previousStock = (float) $branchProduct->stock;

                    $newStock =
                        $previousStock -
                        (float) $oldItem->quantity;


                    DB::table('branch_product')
                        ->where('id', $branchProduct->id)
                        ->update([
                            'stock' => $newStock,
                            'updated_at' => now(),
                        ]);

                }

            }


            $purchase->items()->delete();
            
                        $subtotalPurchase = 0;
            $taxPurchase = 0;
            $totalPurchase = 0;


            foreach ($data['items'] as $item) {

            \Log::info('ITEM UPDATE COMPRA', $item);

                $product = Product::query()
                    ->where('id', $item['product_id'])
                    ->where('company_id', $companyId)
                    ->firstOrFail();


                $quantity = (float) $item['quantity'];
                $unitCost = (float) $item['unit_cost'];
                $taxRate = (float) $product->tax_rate;


                $subtotal =
                    $quantity * $unitCost;

                $tax =
                    $subtotal * ($taxRate / 100);

                $total =
                    $subtotal + $tax;


                $subtotalPurchase += $subtotal;
                $taxPurchase += $tax;
                $totalPurchase += $total;



                $previousSalePrice =
                    (float) $product->sale_price;


                $newSalePrice =
                    isset($item['new_sale_price']) &&
                    $item['new_sale_price'] !== null
                        ? (float) $item['new_sale_price']
                        : null;



                PurchaseItem::create([

                    'purchase_id' =>
                        $purchase->id,

                    'product_id' =>
                        $product->id,

                    'quantity' =>
                        $quantity,

                    'unit_cost' =>
                        $unitCost,

                    'previous_sale_price' =>
                        $previousSalePrice,

                    'new_sale_price' =>
                        $newSalePrice,

                    'subtotal' =>
                        round($subtotal,2),

                    'discount' =>
                        0,

                    'tax_rate' =>
                        $taxRate,

                    'tax' =>
                        round($tax,2),

                    'total' =>
                        round($total,2),

                ]);



                $branchProduct = DB::table('branch_product')
                    ->where('branch_id', $branchId)
                    ->where('product_id', $product->id)
                    ->first();



                $previousStock = $branchProduct
                    ? (float) $branchProduct->stock
                    : 0;


                $newStock =
                    $previousStock + $quantity;



                if ($branchProduct) {

                    DB::table('branch_product')
                        ->where('id', $branchProduct->id)
                        ->update([
                            'stock' => $newStock,
                            'updated_at' => now(),
                        ]);

                } else {

                    DB::table('branch_product')
                        ->insert([
                            'branch_id' => $branchId,
                            'product_id' => $product->id,
                            'stock' => $newStock,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                }


                $product->cost = $unitCost;

                if ($newSalePrice !== null) {
                    $product->sale_price = $newSalePrice;
                }

                $product->save();



                InventoryMovement::create([

                    'company_id' =>
                        $companyId,

                    'branch_id' =>
                        $branchId,

                    'product_id' =>
                        $product->id,

                    'user_id' =>
                        Auth::id(),

                    'type' =>
                        'purchase_edit',

                    'quantity' =>
                        $quantity,

                    'previous_stock' =>
                        $previousStock,

                    'new_stock' =>
                        $newStock,

                    'reason' =>
                        'Edición de compra',

                    'reference_type' =>
                        Purchase::class,

                    'reference_id' =>
                        $purchase->id,

                    'notes' =>
                        'Compra editada ' . $purchase->number,

                ]);

            }

                        $purchase->update([

                'supplier_id' =>
                    $data['supplier_id'],

                'supplier_invoice_number' =>
                    $data['supplier_invoice_number'] ?? null,

                'purchase_date' =>
                    $data['purchase_date'],

                'payment_type' =>
                    $data['payment_type'],

                'due_date' =>
                    $data['payment_type'] === 'credit'
                        ? ($data['due_date'] ?? null)
                        : null,

                'subtotal' =>
                    round($subtotalPurchase, 2),

                'discount' =>
                    0,

                'tax' =>
                    round($taxPurchase, 2),

                'total' =>
                    round($totalPurchase, 2),

                'notes' =>
                    $data['notes'] ?? null,

            ]);

        });


         return response()->json([
    'message' => 'Compra actualizada correctamente.',
    'redirect' => route('compras.show', $id)
]);

} catch (\Throwable $e) {

    report($e);

    return response()->json([
        'message' => $e->getMessage()
    ], 500);

}

}
            /**
     * Anular compra.
     */
    public function destroy(string $id, PurchaseAccountPayableService $accountPayableService)
{
    $companyId = session('active_company_id');
    $branchId = session('active_branch_id');

    try {

        DB::transaction(function () use (
            $id,
            $companyId,
            $branchId,
            $accountPayableService
        ) {

            $purchase = Purchase::with('items.product')
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->findOrFail($id);


            if ($purchase->status === 'cancelled') {

                throw new \Exception(
                    'La compra ya está anulada.'
                );

            }


            foreach ($purchase->items as $item) {

                $branchProduct = DB::table('branch_product')
                    ->where('branch_id', $branchId)
                    ->where('product_id', $item->product_id)
                    ->first();


                $previousStock = $branchProduct
                    ? (float) $branchProduct->stock
                    : 0;


                $newStock =
                    $previousStock -
                    (float) $item->quantity;


                if ($branchProduct) {

                    DB::table('branch_product')
                        ->where('id', $branchProduct->id)
                        ->update([
                            'stock' => $newStock,
                            'updated_at' => now(),
                        ]);

                }


                InventoryMovement::create([
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'product_id' => $item->product_id,
                    'user_id' => Auth::id(),

                    'type' => 'purchase_cancel',

                    'quantity' => $item->quantity,

                    'previous_stock' =>
                        $previousStock,

                    'new_stock' =>
                        $newStock,

                    'reason' =>
                        'Anulación de compra',

                    'reference_type' =>
                        Purchase::class,

                    'reference_id' =>
                        $purchase->id,

                    'notes' =>
                        'Anulación ' . $purchase->number,
                ]);

            }


            $purchase->update([

                'status' => 'cancelled',

                'cancelled_by' =>
                    Auth::id(),

                'cancelled_at' =>
                    now(),

                'cancellation_reason' =>
                    'Anulación manual',

            ]);

            $accountPayableService->cancelFor(
                $purchase,
                Auth::user(),
                'Anulación manual'
            );

        });


        return redirect()
            ->route('compras.index')
            ->with(
                'success',
                'Compra anulada correctamente.'
            );


    } catch (\Throwable $e) {

        report($e);


        return back()
            ->with(
                'error',
                $e->getMessage()
            );
    }
}

}
