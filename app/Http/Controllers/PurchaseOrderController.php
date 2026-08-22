<?php

namespace App\Http\Controllers;

use App\Http\Requests\PreparePurchaseOrdersRequest;
use App\Http\Requests\ConvertPurchaseOrderRequest;
use App\Models\Company;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use App\Services\Orders\PurchaseOrderPreparationService;
use App\Services\Orders\PurchaseOrderConversionService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PurchaseOrderController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['supplier_id' => ['nullable', 'integer'], 'status' => ['nullable', 'in:draft,prepared,sent,received,cancelled'], 'date' => ['nullable', 'date'], 'search' => ['nullable', 'string', 'max:100']]);
        $companyId = (int) session('active_company_id');
        $branchId = (int) session('active_branch_id');
        $orders = PurchaseOrder::query()->forCompany($companyId)->forBranch($branchId)
            ->with(['supplier:id,name,commercial_name', 'branch:id,name', 'items'])
            ->when($filters['supplier_id'] ?? null, fn ($query, $id) => $query->where('supplier_id', $id))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['date'] ?? null, fn ($query, $date) => $query->whereDate('requested_at', $date))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($searchQuery) => $searchQuery
                ->where('number', 'like', '%'.$search.'%')
                ->orWhereHas('supplier', fn ($supplierQuery) => $supplierQuery->where('name', 'like', '%'.$search.'%')->orWhere('commercial_name', 'like', '%'.$search.'%'))))
            ->latest('requested_at')->paginate(20)->withQueryString();
        $suppliers = \App\Models\Supplier::query()->where('company_id', $companyId)->orderBy('name')->get(['id', 'name']);

        return view('purchase-orders.index', compact('orders', 'suppliers', 'filters'));
    }

    public function show(PurchaseOrder $purchaseOrder): View
    {
        $purchaseOrder = $this->scoped($purchaseOrder)->load(['supplier', 'branch', 'preparedBy', 'items.product', 'items.sources.orderItem.order', 'items.sources.conversions.purchaseItem.purchase']);
        $company = Company::query()->findOrFail((int) session('active_company_id'));
        $canConvert = $purchaseOrder->status === PurchaseOrder::STATUS_PREPARED
            && $purchaseOrder->items->contains(fn ($item) => $item->pending_quantity > 0)
            && request()->user()->hasPermission('compras.crear', $company);

        return view('purchase-orders.show', compact('purchaseOrder', 'canConvert'));
    }

    public function convertForm(Request $request, PurchaseOrder $purchaseOrder): View
    {
        $purchaseOrder = $this->scoped($purchaseOrder)->load(['supplier', 'branch', 'items.product', 'items.sources.conversions']);
        abort_unless($purchaseOrder->status === PurchaseOrder::STATUS_PREPARED, 422);
        $company = Company::query()->findOrFail((int) session('active_company_id'));
        abort_unless($request->user()->hasPermission('compras.ordenes', $company) && $request->user()->hasPermission('compras.crear', $company), 403);
        abort_unless($purchaseOrder->items->contains(fn ($item) => $item->pending_quantity > 0), 422);

        return view('purchase-orders.convert', compact('purchaseOrder'));
    }

    public function convert(ConvertPurchaseOrderRequest $request, PurchaseOrder $purchaseOrder, PurchaseOrderConversionService $service)
    {
        $purchase = $service->convert($this->scoped($purchaseOrder), $request->validated(), $request->user(), (int) session('active_company_id'), (int) session('active_branch_id'));

        return redirect()->route('compras.show', $purchase)->with('success', 'Pedido a proveedor convertido correctamente en '.$purchase->number.'.');
    }

    public function prepare(Request $request): View
    {
        $companyId = (int) session('active_company_id');
        $branchId = (int) session('active_branch_id');
        $company = Company::query()->findOrFail($companyId);
        abort_unless($request->user()->hasPermission('pedidos.preparar_compra', $company), 403);

        $lines = OrderItem::query()->whereIn('item_status', [OrderItem::STATUS_APPROVED, OrderItem::STATUS_PARTIAL])
            ->where('approved_quantity', '>', 0)->whereNotNull('supplier_id')
            ->whereHas('order', fn ($query) => $query->where('company_id', $companyId)->where('branch_id', $branchId))
            ->when($request->integer('order_id'), fn ($query, $orderId) => $query->where('order_id', $orderId))
            ->with(['order:id,number,company_id,branch_id', 'supplier:id,name', 'product.productSuppliers' => fn ($query) => $query->where('company_id', $companyId)])
            ->withSum('purchaseOrderSources as allocated_quantity', 'allocated_quantity')->get()
            ->filter(fn ($line) => (float) $line->approved_quantity > (float) $line->allocated_quantity)->values();
        $canViewCosts = $request->user()->hasPermission('compras.ordenes', $company);

        return view('purchase-orders.prepare', compact('lines', 'canViewCosts'));
    }

    public function store(PreparePurchaseOrdersRequest $request, PurchaseOrderPreparationService $service)
    {
        $orders = $service->prepare($request->validated(), $request->user(), (int) session('active_company_id'), (int) session('active_branch_id'));

        return redirect()->route('pedidos.index')->with('success', $orders->count().' pedido(s) a proveedor preparado(s) correctamente.');
    }

    private function scoped(PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        abort_unless((int) $purchaseOrder->company_id === (int) session('active_company_id') && (int) $purchaseOrder->branch_id === (int) session('active_branch_id'), 404);

        return $purchaseOrder;
    }
}
