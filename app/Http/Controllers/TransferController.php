<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Company;
use App\Models\InventoryTransfer;
use App\Models\Product;
use App\Services\Inventory\InventoryPostingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TransferController extends Controller
{
    /**
     * Listado de transferencias.
     */
    public function index(Request $request)
    {
        $companyId = (int) session('active_company_id');
        $company = Company::query()->findOrFail($companyId);
        $assignedBranchIds = $request->user()->branches()
            ->where('branches.company_id', $companyId)
            ->pluck('branches.id');

        $transfers = InventoryTransfer::with([
            'fromBranch',
            'toBranch',
            'user',
            'items.product',
        ])
            ->where('company_id', $companyId)
            ->when(
                ! $request->user()->hasPermission('inventario.ver_otras_sucursales', $company),
                fn ($query) => $query->where(function ($branches) use ($assignedBranchIds) {
                    $branches->whereIn('from_branch_id', $assignedBranchIds)
                        ->orWhereIn('to_branch_id', $assignedBranchIds);
                }),
            )
            ->latest()
            ->paginate(20);

        return view('transferencias.index', compact('transfers'));
    }

    /**
     * Formulario de nueva transferencia.
     */
    public function create(Request $request)
    {
        $companyId = (int) session('active_company_id');
        $branchId = (int) session('active_branch_id');

        $branches = Branch::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('id', '!=', $branchId)
            ->when(
                ! $request->user()->hasPermission('inventario.ver_otras_sucursales', Company::query()->findOrFail($companyId)),
                fn ($query) => $query->whereIn('id', $request->user()->branches()->select('branches.id')),
            )
            ->orderBy('name')->get();

        return view('transferencias.create', compact('branches'));
    }

    /**
     * Guardar transferencia.
     */
    public function searchProducts(Request $request)
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'max:100'],
        ]);
        $companyId = (int) session('active_company_id');
        $branchId = (int) session('active_branch_id');
        $search = trim($data['q']);

        if ($search === '') {
            return response()->json([]);
        }

        $products = Product::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('track_inventory', true)
            ->with(['unit:id,allows_decimals'])
            ->with(['branches' => fn ($query) => $query->where('branches.id', $branchId)])
            ->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('internal_code', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhereHas('barcodes', fn ($barcodes) => $barcodes
                        ->where('is_active', true)
                        ->where('barcode', 'like', "%{$search}%"));
            })
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'internal_code', 'barcode', 'unit_id']);

        return response()->json($products->map(function (Product $product) {
            $branch = $product->branches->first();

            return [
                'id' => $product->id,
                'name' => $product->name,
                'internal_code' => $product->internal_code,
                'barcode' => $product->barcode,
                'allows_decimals' => (bool) $product->unit?->allows_decimals,
                'branch_stock' => $branch?->pivot?->stock,
            ];
        }));
    }

    public function store(Request $request, InventoryPostingService $inventory)
    {
        $companyId = (int) session('active_company_id');
        $fromBranchId = (int) session('active_branch_id');

        $data = $request->validate([
            'to_branch_id' => ['required', 'integer'],
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'quantity' => ['required', 'decimal:0,4', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $company = Company::query()->findOrFail($companyId);
        $destinations = Branch::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereKeyNot($fromBranchId);

        if (! $request->user()->hasPermission('inventario.ver_otras_sucursales', $company)) {
            $destinations->whereIn('id', $request->user()->branches()->select('branches.id'));
        }

        $fromBranch = Branch::query()->where('company_id', $companyId)->findOrFail($fromBranchId);
        $toBranch = $destinations->findOrFail($data['to_branch_id']);
        $product = Product::query()->where('company_id', $companyId)->where('is_active', true)->findOrFail($data['product_id']);

        $inventory->postTransfer(
            $fromBranch,
            $toBranch,
            $product,
            (string) $data['quantity'],
            (int) $request->user()->id,
            $data['notes'] ?? null,
        );

        return redirect()
            ->route('transferencias.index')
            ->with(
                'success',
                'Transferencia realizada correctamente.'
            );
    }
}
