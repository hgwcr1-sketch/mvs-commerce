<?php

namespace App\Services\Imports\Managers;

use App\Models\Product;
use App\Models\Supplier;

class PurchaseImportManager
{

    public function validateProducts(array $items, $companyId)
    {

        $result = [

            'found' => [],

            'missing' => [],

        ];


        foreach ($items as $item) {


            $product = Product::where(
                'company_id',
                $companyId
            )
            ->where(function ($query) use ($item) {


                if (!empty($item['code'])) {

                    $query->orWhere(
                        'internal_code',
                        $item['code']
                    );

                }


                if (!empty($item['barcode'])) {

                    $query->orWhere(
                        'barcode',
                        $item['barcode']
                    );

                }


                if (!empty($item['cabys'])) {

                    $query->orWhere(
                        'cabys_code',
                        $item['cabys']
                    );

                }


                if (!empty($item['name'])) {

                    $query->orWhere(
                        'name',
                        $item['name']
                    );

                }


            })
            ->first();



            if ($product) {


                $result['found'][] = [

                    'product_id' => $product->id,

                    'product' => $product->name,

                    'quantity' => $item['quantity'],

                    'cost' => $item['cost'],

                ];


            } else {


                $result['missing'][] = $item;


            }


        }


        return $result;

    }



    public function findSupplier($identification, $companyId)
    {

        return Supplier::where(
            'company_id',
            $companyId
        )
        ->where(
            'identification',
            $identification
        )
        ->first();

    }
public function validateSupplier($supplierName, $companyId)
{
    $supplier = Supplier::where('company_id', $companyId)
        ->where(function ($query) use ($supplierName) {

            $query->where('name', $supplierName)
                ->orWhere('commercial_name', $supplierName);

        })
        ->first();


    if ($supplier) {

        return [
            'found' => true,
            'id' => $supplier->id,
            'name' => $supplier->name,
        ];

    }


    return [
        'found' => false,
        'name' => $supplierName,
    ];
}

}