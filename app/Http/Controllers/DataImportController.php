<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Product;
use App\Models\InventoryMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Barryvdh\DomPDF\Facade\Pdf;


class DataImportController extends Controller
{

public function inventory()
{
    $companyId = session('active_company_id');

    $branches = Branch::query()
        ->where('company_id', $companyId)
        ->where('is_active', true)
        ->get();

    $branchId = $branches->first()?->id;

    return view(
        'importaciones.inventario',
        compact(
            'branches',
            'branchId'
        )
    );
}

    public function inventoryPreview(Request $request)
    {

        $companyId = session('active_company_id');


        $request->validate([

            'branch_id' => [
                'required',
                'integer',
            ],

            'movement_type' => [
                'required',
                'in:entry,exit',
            ],

            'inventory_file' => [
                'required',
                'file',
                'mimes:xlsx,xls,csv',
                'max:10240',
            ],

        ]);



        $branch = Branch::query()

            ->where('id',$request->branch_id)

            ->where('company_id',$companyId)

            ->where('is_active',true)

            ->firstOrFail();



        $spreadsheet = IOFactory::load(

            $request->file('inventory_file')->getRealPath()

        );


        $rows = $spreadsheet

            ->getActiveSheet()

            ->toArray(null,true,true,false);



        $rows = array_values(

            array_filter($rows,function($row){

                foreach($row as $value){

                    if($value !== null && trim((string)$value) !== ''){

                        return true;

                    }

                }

                return false;

            })

        );



        $headers = array_map(

            fn($value)=>

                strtolower(

                    trim(

                        str_replace('*','',(string)$value)

                    )

                ),

            $rows[0]

        );



        foreach([

            'codigo',

            'cantidad',

            'minimo',

            'maximo',

        ] as $required){


            if(!in_array($required,$headers,true)){


                return back()

                    ->withErrors([

                        'inventory_file'=>

                        "Falta la columna obligatoria: {$required}"

                    ]);

            }

        }



        $codeIndex = array_search('codigo',$headers,true);

        $quantityIndex = array_search('cantidad',$headers,true);

        $minimumIndex = array_search('minimo',$headers,true);

        $maximumIndex = array_search('maximo',$headers,true);


        $nameIndex = array_search('nombre',$headers,true);

        $barcodeIndex = array_search('codigo_barras',$headers,true);

        $cabysIndex = array_search('cabys',$headers,true);

        $costIndex = array_search('costo',$headers,true);

        $salePriceIndex = array_search('precio_venta',$headers,true);

        $wholesaleIndex = array_search('precio_mayoreo',$headers,true);

        $specialIndex = array_search('precio_especial',$headers,true);

        $taxIndex = array_search('impuesto',$headers,true);

        $descriptionIndex = array_search('descripcion',$headers,true);



        $previewRows = [];



        foreach(array_slice($rows,1) as $row){

    if (
        empty(trim((string)($row[$codeIndex] ?? ''))) &&
        empty(trim((string)($row[$nameIndex] ?? '')))
    ) {
        continue;
    }

    $code = trim((string)($row[$codeIndex] ?? ''));


            $product = Product::query()

                ->where('company_id',$companyId)

                ->where(function($q) use($code){

                    $q->where('internal_code',$code)

                    ->orWhere('barcode',$code);

                })

                ->first();



            $previewRows[] = [


                'code'=>$code,


                'product_id'=>$product?->id,


                'product_name'=>

                    $product?->name ??

                    ($row[$nameIndex] ?? 'Producto nuevo'),



                'barcode'=>$row[$barcodeIndex] ?? null,


                'cabys'=>$row[$cabysIndex] ?? null,


                'cost'=>$row[$costIndex] ?? 0,


                'sale_price'=>$row[$salePriceIndex] ?? 0,


                'wholesale_price'=>$row[$wholesaleIndex] ?? null,


                'special_price'=>$row[$specialIndex] ?? null,


                'tax_rate'=>$row[$taxIndex] ?? 0,


                'description'=>$row[$descriptionIndex] ?? null,



                'quantity'=>$row[$quantityIndex] ?? 0,


                'minimum'=>$row[$minimumIndex] ?? 0,


                'maximum'=>$row[$maximumIndex] ?? 0,


                'current_stock'=>0,


                'is_new'=>!$product,


                'valid'=>true,


                'errors'=>[],


            ];

        }



        session([

            'inventory_import_preview'=>[

                'company_id'=>$companyId,

                'branch_id'=>$branch->id,

                'movement_type'=>$request->movement_type,

                'rows'=>$previewRows,

            ]

        ]);



       return view(
    'importaciones.inventario-preview',
    compact(
        'previewRows',
        'branch'
    )
)->with([
    'movementType' => $request->movement_type,
    'rows' => $previewRows,
]);

    }

        public function inventoryImport(Request $request)
    {

        $companyId = session('active_company_id');


        $preview = session('inventory_import_preview');


        if(!$preview){

            return redirect()

                ->route('importaciones.inventario')

                ->withErrors([

                    'inventory_file'=>

                    'La vista previa expiró. Cargue nuevamente el archivo.'

                ]);

        }



        $branch = Branch::query()

            ->where('id',$preview['branch_id'])

            ->where('company_id',$companyId)

            ->where('is_active',true)

            ->firstOrFail();



        $movementType = $preview['movement_type'];


        $rows = $preview['rows'];



        DB::transaction(function() use (

            $rows,

            $branch,

            $companyId,

            $movementType

        ){


            foreach($rows as $row){



                $product = null;



                if(!empty($row['product_id'])){


                    $product = Product::query()

                        ->where('id',$row['product_id'])

                        ->where('company_id',$companyId)

                        ->first();


                }



                if(!$product){



                    $product = Product::create([



                        'company_id'=>$companyId,


                        'category_id'=>1,


                        'unit_id'=>1,


                        'name'=>$row['product_name'],


                        'internal_code'=>$row['code'],


                        'barcode'=>$row['barcode'],


                        'cabys_code'=>$row['cabys'],


                        'cost'=>$row['cost'],


                        'sale_price'=>$row['sale_price'],


                        'wholesale_price'=>$row['wholesale_price'],


                        'special_price'=>$row['special_price'],


                        'tax_rate'=>$row['tax_rate'],


                        'description'=>$row['description'],


                        'product_type'=>'product',


                        'track_inventory'=>true,


                        'minimum_stock'=>$row['minimum'],


                        'maximum_stock'=>$row['maximum'],


                        'is_active'=>true,


                    ]);

                }




                $inventory = DB::table('branch_product')

                    ->where('branch_id',$branch->id)

                    ->where('product_id',$product->id)

                    ->first();




                $previousStock = $inventory

                    ? $inventory->stock

                    : 0;



                if($movementType === 'entry'){

                    $newStock = $previousStock + $row['quantity'];

                }else{

                    $newStock = $previousStock - $row['quantity'];

                }





                DB::table('branch_product')

                    ->updateOrInsert(

                        [

                            'branch_id'=>$branch->id,

                            'product_id'=>$product->id,

                        ],

                        [

                            'stock'=>$newStock,

                            'minimum_stock'=>$row['minimum'],

                            'maximum_stock'=>$row['maximum'],

                            'updated_at'=>now(),

                            'created_at'=>now(),

                        ]

                    );





                InventoryMovement::create([



                    'company_id'=>$companyId,


                    'branch_id'=>$branch->id,


                    'product_id'=>$product->id,


                    'user_id'=>auth()->id(),


                    'type'=>$movementType,


                    'quantity'=>$row['quantity'],


                    'previous_stock'=>$previousStock,


                    'new_stock'=>$newStock,


                    'reason'=>'Importación de inventario',


                    'reference_type'=>'inventory_import',


                    'notes'=>'Movimiento generado por importación Excel.',



                ]);



            }


        });




        session()->forget('inventory_import_preview');




        return redirect()

            ->route('inventario.index')

            ->with('success','Inventario importado correctamente.');

    }

        public function inventoryTemplate()
    {

        $spreadsheet = new Spreadsheet();


        $sheet = $spreadsheet->getActiveSheet();


        $sheet->setTitle('Inventario');


        $headers = [

            'codigo*',

            'nombre*',

            'cantidad*',

            'categoria',

            'marca',

            'unidad',

            'codigo_barras',

            'cabys',

            'costo',

            'precio_venta',

            'precio_mayoreo',

            'precio_especial',

            'impuesto',

            'minimo',

            'maximo',

            'descripcion',

        ];



        $sheet->fromArray(

            $headers,

            null,

            'A1'

        );



        $fileName = 'plantilla_importacion_inventario.xlsx';



        $writer = new Xlsx($spreadsheet);



        return response()->streamDownload(

            function() use($writer){

                $writer->save('php://output');

            },

            $fileName

        );

    }




    public function inventoryExample()
    {


        $spreadsheet = new Spreadsheet();


        $sheet = $spreadsheet->getActiveSheet();



        $sheet->fromArray([


            [

                'TEST-001',

                'Producto ejemplo',

                10,

                'Categoria',

                'Marca',

                'Unidad',

                '750000000',

                '123456789',

                1500,

                3000,

                2500,

                2800,

                13,

                2,

                20,

                'Producto de ejemplo'

            ]



        ],null,'A1');



        $fileName = 'ejemplo_importacion_inventario.xlsx';



        $writer = new Xlsx($spreadsheet);



        return response()->streamDownload(

            function() use($writer){

                $writer->save('php://output');

            },

            $fileName

        );

    }

        public function inventoryInstructions()
    {

        $pdf = Pdf::loadView(
            'pdf.instrucciones-inventario'
        );


        return $pdf->download(
            'instrucciones_importacion_inventario.pdf'
        );

    }


}