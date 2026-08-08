<?php

namespace App\Services\Imports\Validation;

use App\Models\Product;
use App\Models\Supplier;

class PurchaseImportValidator
{

    public function validate(array $data, $companyId)
    {

        $result = [

            'supplier' => null,

            'supplier_missing' => false,

            'products' => [],

            'missing_products' => [],

            'errors' => [],

        ];


        foreach ($data['products'] ?? [] as $item) {


            $product = Product::where('company_id', $companyId)
                ->where(function ($q) use ($item) {

                    $q->where(
                        'internal_code',
                        $item['code'] ?? null
                    )
                    ->orWhere(
                        'name',
                        $item['name'] ?? null
                    );

                })
                ->first();



            if ($product) {


                $result['products'][] = [

                    'product_id' => $product->id,

                    'name' => $product->name,

                    'quantity' => $item['quantity'],

                    'cost' => $item['cost'],

                    'exists' => true,

                ];


            } else {


                $result['missing_products'][] = [

                    'code' => $item['code'] ?? null,

                    'name' => $item['name'] ?? null,

                    'quantity' => $item['quantity'],

                    'cost' => $item['cost'],

                ];

            }

        }


        return $result;

    }

}