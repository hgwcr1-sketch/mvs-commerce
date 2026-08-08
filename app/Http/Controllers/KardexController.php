<?php

namespace App\Http\Controllers;

use App\Models\InventoryMovement;
use App\Models\Product;
use Illuminate\Http\Request;

class KardexController extends Controller
{
    /**
     * Historial de movimientos de la sucursal activa.
     */
    public function index(Request $request)
    {
        $companyId = session('active_company_id');
        $branchId = session('active_branch_id');

        $search = $request->get('search');
        $productId = $request->get('product_id');
        $type = $request->get('type');
        $dateFrom = $request->get('date_from');
$dateTo = $request->get('date_to');

        $movements = InventoryMovement::with([
                'product',
                'user',
                'branch',
            ])
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)

            ->when($productId, function ($query) use ($productId) {
                $query->where('product_id', $productId);
            })

            ->when($type, function ($query) use ($type) {
                $query->where('type', $type);
            })

            ->when($dateFrom, function ($query) use ($dateFrom) {
    $query->whereDate('created_at', '>=', $dateFrom);
})

->when($dateTo, function ($query) use ($dateTo) {
    $query->whereDate('created_at', '<=', $dateTo);
})

            ->when($search, function ($query) use ($search) {

                $query->where(function ($query) use ($search) {

                    $query->where('reason', 'like', "%{$search}%")
                        ->orWhere('notes', 'like', "%{$search}%")
                        ->orWhereHas('product', function ($query) use ($search) {

                            $query->where('name', 'like', "%{$search}%")
                                ->orWhere('internal_code', 'like', "%{$search}%")
                                ->orWhere('barcode', 'like', "%{$search}%");

                        });

                });

            })

            ->latest()
            ->paginate(20)
            ->withQueryString();

        $products = Product::where('company_id', $companyId)
    ->where('is_active', true)
    ->orderBy('name')
    ->get();
    
        return view('kardex.index', compact(
           'movements',
    'products',
    'search',
    'productId',
    'type',
    'dateFrom',
    'dateTo'
));

    }
}