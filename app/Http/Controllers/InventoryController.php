<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    /**
     * Inventario de la sucursal activa.
     */
    public function index()
{
    $companyId = session('active_company_id');
    $branchId = session('active_branch_id');

    $search = request('search');
    $likeOperator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

    $products = Product::where('company_id', $companyId)
        ->with([
            'category',
            'brand',
            'unit',
            'branches' => function ($query) use ($branchId) {
                $query->where('branches.id', $branchId);
            },
        ])
                    ->when($search, function ($query) use ($search, $likeOperator) {

                $query->where(function ($query) use ($search, $likeOperator) {

                    $query->where('name', $likeOperator, "%{$search}%")
                        ->orWhere('internal_code', $likeOperator, "%{$search}%")
                        ->orWhere('barcode', $likeOperator, "%{$search}%");

                });

            })
            ->where('is_active', true)
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        $products->getCollection()->each(function ($product) {

            $branch = $product->branches->first();

            $product->branch_stock = $branch
                ? $branch->pivot->stock
                : 0;

            $product->branch_minimum_stock = $branch
                ? $branch->pivot->minimum_stock
                : 0;

            $product->branch_maximum_stock = $branch
                ? $branch->pivot->maximum_stock
                : 0;
        });

        return view('inventario.index', compact(
            'products',
            'search'
        ));
    }
}