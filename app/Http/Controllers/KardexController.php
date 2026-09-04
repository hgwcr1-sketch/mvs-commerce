<?php

namespace App\Http\Controllers;

use App\Models\InventoryMovement;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        $likeOperator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
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

            ->when($search, function ($query) use ($search, $likeOperator) {

                $query->where(function ($query) use ($search, $likeOperator) {

                    $query->where('reason', $likeOperator, "%{$search}%")
                        ->orWhere('notes', $likeOperator, "%{$search}%")
                        ->orWhereHas('product', function ($query) use ($search, $likeOperator) {

                            $query->where('name', $likeOperator, "%{$search}%")
                                ->orWhere('internal_code', $likeOperator, "%{$search}%")
                                ->orWhere('barcode', $likeOperator, "%{$search}%");

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