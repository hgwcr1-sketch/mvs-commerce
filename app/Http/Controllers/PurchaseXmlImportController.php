<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Services\Imports\Managers\PurchaseImportManager;
use App\Services\Imports\Xml\PurchaseXmlImport;


class PurchaseXmlImportController extends Controller
{

    public function create()
    {
        return view('compras.import-xml');
    }

    public function store(
        Request $request,
        PurchaseImportManager $manager,
    )
    {

        $request->validate([

            'file' => [
                'required',
                'file',
                'extensions:xml'
            ]

        ]);


        $import = new PurchaseXmlImport();


        $data = $import->read(
            $request->file('file')->getRealPath()
        );

        $supplierName = $data['proveedor']['nombre'] ?? null;

        $items = array_map(
            function (array $line, int $index) use ($supplierName) {
                $sourceCode = trim((string) ($line['code'] ?? ''));
                $cabys = trim((string) ($line['cabys'] ?? ''));
                $normalizedName = Str::upper(
                    Str::slug((string) ($line['name'] ?? '')),
                );

                $code = $sourceCode !== ''
                    ? $sourceCode
                    : 'XML-' . ($cabys !== '' ? $cabys : $normalizedName);

                return [
                    'code' => $code,
                    'barcode' => null,
                    'cabys' => $line['cabys'] ?? null,
                    'name' => $line['name'] ?? null,
                    'quantity' => $line['quantity'] ?? null,
                    'cost' => $line['unit_cost'] ?? null,
                    'unit' => $line['unit'] ?? null,
                    'tax_rate' => $line['tax_rate'] ?? null,
                    'supplier' => $supplierName,
                    '_row_key' => 'xml-' . ($index + 1),
                ];
            },
            $data['lineas'] ?? [],
            array_keys($data['lineas'] ?? []),
        );

        $companyId = (int) session('active_company_id');
        $validation = $manager->validateProducts($items, $companyId);
        $validation['supplier_summary'] = $manager->supplierSummary($items);
        $validation['supplier'] = $manager->validateSupplier(
            $validation['supplier_summary']['name'],
            $companyId,
        );

        session(['purchase_import_validation' => $validation]);

        return redirect()->route('compras.import.review');

    }

}
