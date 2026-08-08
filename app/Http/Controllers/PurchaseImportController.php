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

        try {


            $purchase = DB::transaction(function () use (
                $rows,
                $companyId,
                $branchId
            ) {


                $subtotal = 0;
                $tax = 0;
                $total = 0;


                $items = [];


                foreach ($rows as $row) {


                    $product = Product::where(
                        'internal_code',
                        $row['code']
                    )
                    ->where('company_id',$companyId)
                    ->firstOrFail();


                    $quantity = (float)$row['quantity'];
                    $cost = (float)$row['cost'];


                    $lineSubtotal = $quantity * $cost;

                    $lineTax =
                        $lineSubtotal *
                        ($product->tax_rate / 100);


                    $subtotal += $lineSubtotal;
                    $tax += $lineTax;
                    $total += $lineSubtotal + $lineTax;


                    $items[] = [
                        'product'=>$product,
                        'quantity'=>$quantity,
                        'cost'=>$cost,
                        'tax'=>$lineTax
                    ];

                }



                $purchase = Purchase::create([

                    'company_id'=>$companyId,
                    'branch_id'=>$branchId,
                    'supplier_id'=>1,
                    'user_id'=>Auth::id(),

                    'number'=>
                        'CP-'.
                        now()->format('YmdHis').
                        '-'.
                        random_int(100,999),

                    'purchase_date'=>now(),

                    'payment_type'=>'cash',

                    'subtotal'=>round($subtotal,2),
                    'discount'=>0,
                    'tax'=>round($tax,2),
                    'total'=>round($total,2),

                    'status'=>'posted',

                    'notes'=>'Compra importada desde Excel'

                ]);




                foreach($items as $item){


                    $product=$item['product'];

                    PurchaseItem::create([

                        'purchase_id'=>$purchase->id,

                        'product_id'=>$product->id,

                        'quantity'=>$item['quantity'],

                        'unit_cost'=>$item['cost'],

                        'previous_sale_price'=>$product->sale_price,

                        'subtotal'=>
                            $item['quantity'] *
                            $item['cost'],

                        'discount'=>0,

                        'tax_rate'=>$product->tax_rate,

                        'tax'=>$item['tax'],

                        'total'=>
                            ($item['quantity'] *
                            $item['cost'])
                            +
                            $item['tax']

                    ]);



                    DB::table('branch_product')
                    ->where('branch_id',$branchId)
                    ->where('product_id',$product->id)
                    ->increment(
                        'stock',
                        $item['quantity']
                    );


                }


                return $purchase;


            });



            return response()->json([

                'message'=>'Compra importada correctamente',

                'purchase_id'=>$purchase->id,

                'redirect'=>route(
                    'compras.show',
                    $purchase->id
                )

            ]);



        } catch(\Throwable $e){


            return response()->json([

                'message'=>$e->getMessage()

            ],500);


        }


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


    $validation['supplier'] = [
        'found' => true,
        'id' => $request->id,
        'name' => $request->name,
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


        foreach ($validation['found'] as $item) {

            $lineSubtotal =
                $item['quantity'] * $item['cost'];

            $subtotal += $lineSubtotal;

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

            'subtotal' => $subtotal,

            'discount' => 0,

            'tax' => 0,

            'total' => $subtotal,

            'status' => 'posted',

            'notes' =>
                'Compra importada desde Excel'

        ]);



        foreach ($validation['found'] as $item) {


            $product = Product::find(
                $item['product_id']
            );


            PurchaseItem::create([

                'purchase_id' => $purchase->id,

                'product_id' => $product->id,

                'quantity' => $item['quantity'],

                'unit_cost' => $item['cost'],

                'previous_sale_price' =>
                    $product->sale_price,

                'subtotal' =>
                    $item['quantity'] *
                    $item['cost'],

                'discount' => 0,

                'tax_rate' =>
                    $product->tax_rate,

                'tax' => 0,

                'total' =>
                    $item['quantity'] *
                    $item['cost'],

            ]);



            DB::table('branch_product')
                ->where('branch_id',$branchId)
                ->where('product_id',$product->id)
                ->increment(
                    'stock',
                    $item['quantity']
                );

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


    $request->validate([

        'category_id' => 'required',

        'unit_id' => 'required',

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