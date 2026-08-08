<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\Imports\Xml\PurchaseXmlImport;


class PurchaseXmlImportController extends Controller
{

    public function store(Request $request)
    {

        $request->validate([

            'file' => [
                'required',
                'file',
                'mimes:xml'
            ]

        ]);


        $import = new PurchaseXmlImport();


        $data = $import->read(
            $request->file('file')->getRealPath()
        );


        return response()->json([

            'message' => 'XML leído correctamente',

            'data' => $data

        ]);

    }

}