<?php

namespace App\Http\Controllers;

use App\Services\Sales\SaleVoidService;
use App\Models\Sale;
use Illuminate\Http\Request;

class SaleController extends Controller
{
    public function index(Request $request)
    {
        $companyId = (int) session('active_company_id');
        $branchId = (int) session('active_branch_id');

        $query = Sale::query()
            ->forCompany($companyId)
            ->forBranch($branchId)
            ->with([
                'customer:id,name',
                'user:id,name',
            ]);

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('sale_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($customerQuery) use ($search) {
                        $customerQuery->where('name', 'like', "%{$search}%");
                    });
            });
        }

        if ($documentType = $request->query('document_type')) {
            $query->where('document_type', $documentType);
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

        return view('ventas.index', compact('sales'));
    }

    public function show(Sale $venta)
{
    $companyId = (int) session('active_company_id');
    $branchId = (int) session('active_branch_id');

    if (
        (int) $venta->company_id !== $companyId
        || (int) $venta->branch_id !== $branchId
    ) {
        abort(404);
    }

    $venta->load([
        'branch',
        'user',
        'customer',
        'items',
        'payments.paymentMethod',
        'cashSession.cashRegister',
    ]);

    return view('ventas.show', [
        'sale' => $venta,
    ]);
}

public function void(
    Request $request,
    Sale $venta,
    SaleVoidService $service,
)
{
    $data = $request->validate([
        'reason' => [
            'required',
            'string',
            'min:3',
            'max:255',
        ],
    ]);

    $service->void(
        $venta,
        $request->user(),
        $data['reason'],
    );

    return redirect()
        ->route('ventas.show', $venta)
        ->with('success', 'Venta anulada correctamente.');
}

}