<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DevolucionesController extends Controller
{
    /**
     * Lista de ventas elegibles para devolución.
     */
    public function index(Request $request): View
    {
        $companyId = (int) session('active_company_id');
        $branchId = (int) session('active_branch_id');

        $query = Sale::query()
            ->forCompany($companyId)
            ->forBranch($branchId)
            ->whereNot('is_historical', true)
            ->whereIn('status', [
                Sale::STATUS_COMPLETED,
                Sale::STATUS_PARTIALLY_RETURNED,
            ])
            ->with([
                'customer:id,name,identification',
                'user:id,name',
            ]);

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('sale_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($customerQuery) use ($search) {
                        $customerQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('identification', 'like', "%{$search}%");
                    });
            });
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($dateFrom = $request->query('date_from')) {
            $query->whereDate('completed_at', '>=', $dateFrom);
        }

        if ($dateTo = $request->query('date_to')) {
            $query->whereDate('completed_at', '<=', $dateTo);
        }

        $sales = $query
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('devoluciones.index', compact('sales'));
    }
}