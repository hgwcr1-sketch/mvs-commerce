<?php

namespace App\Http\Controllers;

use App\Models\ProductBarcode;
use App\Services\Imports\Managers\PurchaseImportManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

use App\Services\Imports\PurchaseExcelImport;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use Illuminate\Validation\Rule;


class PurchaseImportController extends Controller
{

    public function store(Request $request)
    {

        $companyId = session('active_company_id');
        $branchId = session('active_branch_id');


        $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:xlsx,xls'
            ]
        ]);


        $import = new PurchaseExcelImport();


        $rows = $import->read(
            $request->file('file')->getRealPath()
        );

        $manager = new PurchaseImportManager();

$validation = $manager->validateProducts(
    $rows,
    $companyId
);


$validation['supplier'] =
    $manager->validateSupplier(
        $rows[0]['supplier'] ?? null,
        $companyId
    );

session([
    'purchase_import_validation' => $validation
]);

$validation = session('purchase_import_validation');


foreach ($validation['missing'] as $key => $item) {


    if (trim((string)$item['code']) == trim((string)$request->code)) {


        $validation['found'][] = [

            'product_id' => $product->id,

            'product' => $product->name,

            'quantity' => $item['quantity'],

            'cost' => $item['cost'],

        ];


        unset($validation['missing'][$key]);


        break;

    }

}


$validation['missing'] = array_values($validation['missing']);


session([

    'purchase_import_validation' => $validation

]);

return redirect()
    ->route('compras.import.review');
    }

/**
 * Pantalla de revisión de importación.
 */
public function review()
{
    $validation = session('purchase_import_validation');

    if (!$validation) {

        return redirect()
            ->route('compras.index')
            ->with(
                'error',
                'No existe una importación pendiente.'
            );

    }


    return view(
        'compras.import-review',
        compact('validation')
    );
}

public function supplierCreated(Request $request)
{
    $validation = session('purchase_import_validation');

    $request->validate([
        'id' => ['required', 'integer'],
    ]);

    $companyId = session('active_company_id');

    $supplier = Supplier::where('company_id', $companyId)
        ->where('is_active', true)
        ->findOrFail($request->integer('id'));

    $validation['supplier'] = [
        'found' => true,
        'id' => $supplier->id,
        'name' => $supplier->name,
    ];


    session([
        'purchase_import_validation' => $validation
    ]);


    return response()->json([
        'success' => true
    ]);
}

public function confirm()
{
    $validation = session('purchase_import_validation');

    $companyId = session('active_company_id');
    $branchId = session('active_branch_id');


    if (!$validation) {

        return redirect()
            ->route('compras.index')
            ->with('error','No existe importación pendiente.');

    }

    if (!empty($validation['missing'])) {

        return redirect()
            ->route('compras.import.review')
            ->with(
                'error',
                'Debe resolver todos los productos pendientes antes de confirmar.'
            );

    }


    if (!$validation['supplier']['found']) {

        return back()
            ->with('error','Debe crear el proveedor antes de confirmar.');

    }


    $purchase = DB::transaction(function () use (
        $validation,
        $companyId,
        $branchId
    ) {


        $subtotal = 0;
        $tax = 0;
        $total = 0;
        $items = [];


        foreach ($validation['found'] as $item) {

            $product = Product::where('company_id', $companyId)
                ->findOrFail($item['product_id']);

            $lineSubtotal =
                $item['quantity'] * $item['cost'];

            $lineTax =
                $lineSubtotal *
                ((float) $product->tax_rate / 100);

            $subtotal += $lineSubtotal;
            $tax += $lineTax;
            $total += $lineSubtotal + $lineTax;

            $items[] = [
                'product' => $product,
                'quantity' => $item['quantity'],
                'cost' => $item['cost'],
                'subtotal' => $lineSubtotal,
                'tax' => $lineTax,
            ];

        }


        $purchase = Purchase::create([

            'company_id' => $companyId,
            'branch_id' => $branchId,

            'supplier_id' =>
                $validation['supplier']['id'],

            'user_id' => Auth::id(),

            'number' =>
                'CP-' .
                now()->format('YmdHis') .
                '-' .
                random_int(100,999),

            'purchase_date' => now(),

            'payment_type' => 'cash',

            'subtotal' => round($subtotal, 2),

            'discount' => 0,

            'tax' => round($tax, 2),

            'total' => round($total, 2),

            'status' => 'posted',

            'notes' =>
                'Compra importada desde Excel'

        ]);



        foreach ($items as $item) {


            $product = $item['product'];


            PurchaseItem::create([

                'purchase_id' => $purchase->id,

                'product_id' => $product->id,

                'quantity' => $item['quantity'],

                'unit_cost' => $item['cost'],

                'previous_sale_price' =>
                    $product->sale_price,

                'subtotal' => round($item['subtotal'], 2),

                'discount' => 0,

                'tax_rate' =>
                    $product->tax_rate,

                'tax' => round($item['tax'], 2),

                'total' => round(
                    $item['subtotal'] + $item['tax'],
                    2
                ),

            ]);



            $branchProduct = DB::table('branch_product')
                ->where('branch_id',$branchId)
                ->where('product_id',$product->id)
                ->first();

            $previousStock = $branchProduct
                ? (float) $branchProduct->stock
                : 0;

            $newStock = $previousStock + $item['quantity'];

            if ($branchProduct) {

                DB::table('branch_product')
                    ->where('id', $branchProduct->id)
                    ->update([
                        'stock' => $newStock,
                        'updated_at' => now(),
                    ]);

            } else {

                DB::table('branch_product')->insert([
                    'branch_id' => $branchId,
                    'product_id' => $product->id,
                    'stock' => $newStock,
                    'minimum_stock' => null,
                    'maximum_stock' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            \App\Models\InventoryMovement::create([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'product_id' => $product->id,
                'user_id' => Auth::id(),

                'type' => 'purchase',
                'quantity' => $item['quantity'],
                'previous_stock' => $previousStock,
                'new_stock' => $newStock,

                'reason' => 'Entrada por compra',
                'reference_type' => Purchase::class,
                'reference_id' => $purchase->id,
                'notes' => 'Compra ' . $purchase->number,
            ]);

        }


        return $purchase;


    });


    session()->forget('purchase_import_validation');


    return redirect()
        ->route('compras.show',$purchase->id)
        ->with(
            'success',
            'Compra importada correctamente.'
        );
}

public function createProduct(Request $request)
{
    $companyId = session('active_company_id');


    return view('compras.product-create-import', [

        'code' => $request->code,

        'name' => $request->name,

        'cost' => $request->cost,


        'categories' => \App\Models\ProductCategory::where(
            'company_id',
            $companyId
        )
        ->where('is_active', true)
        ->orderBy('name')
        ->get(),


        'units' => \App\Models\Unit::where(
            'company_id',
            $companyId
        )
        ->where('is_active', true)
        ->orderBy('name')
        ->get(),

    ]);

}


public function storeProduct(Request $request)
{
    $companyId = session('active_company_id');
    $branchId = session('active_branch_id');

    $branchId = \App\Models\Branch::where('id', $branchId)
        ->where('company_id', $companyId)
        ->where('is_active', true)
        ->value('id');

    abort_unless($branchId, 403, 'No tiene una sucursal activa válida.');


    $request->validate([

        'category_id' => [
            'required',
            'integer',
            Rule::exists('product_categories', 'id')
                ->where('company_id', $companyId)
                ->where('is_active', true),
        ],

        'unit_id' => [
            'required',
            'integer',
            Rule::exists('units', 'id')
                ->where('company_id', $companyId)
                ->where('is_active', true),
        ],

        'name' => 'required',

        'code' => 'required',

        'cost' => 'required',

    ]);


    $product = Product::create([

        'company_id' => $companyId,

        'category_id' => $request->category_id,

        'unit_id' => $request->unit_id,

        'name' => $request->name,

        'internal_code' => $request->code,

        'cost' => $request->cost,

        'sale_price' => 0,

        'tax_rate' => 13,

        'is_active' => true,

    ]);

    DB::table('branch_product')->insert([
        'branch_id' => $branchId,
        'product_id' => $product->id,
        'stock' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);


    ProductBarcode::create([

        'product_id' => $product->id,

        'barcode' => $request->code,

        'barcode_type' => 'supplier',

        'is_primary' => true,

        'is_active' => true,

    ]);

    $validation = session('purchase_import_validation');


if ($validation) {


    foreach ($validation['missing'] as $key => $item) {


        if (
            trim((string)$item['code']) ==
            trim((string)$request->code)
        ) {


            $validation['found'][] = [

                'product_id' => $product->id,

                'product' => $product->name,

                'quantity' => $item['quantity'],

                'cost' => $item['cost'],

            ];


            unset($validation['missing'][$key]);


            break;

        }

    }


    $validation['missing'] =
        array_values($validation['missing']);


    session([

        'purchase_import_validation' => $validation

    ]);

}


    return redirect()

        ->route('compras.import.review')

        ->with(
            'success',
            'Producto creado correctamente'
        );
}

}
