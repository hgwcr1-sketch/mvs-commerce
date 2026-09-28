<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\ReviewOrderItemRequest;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductSupplier;
use App\Models\Supplier;
use App\Services\Orders\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:pending,approved,partial,rejected,in_purchase,completed,cancelled'],
            'date' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:150'],
        ]);

        $orders = Order::query()
            ->forCompany((int) session('active_company_id'))
            ->forBranch((int) session('active_branch_id'))
            ->with(['requester', 'branch'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['date'] ?? null, fn ($query, $date) => $query->whereDate('created_at', $date))
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(fn ($searchQuery) => $searchQuery
                    ->where('number', 'like', '%'.$search.'%')
                    ->orWhereHas('requester', fn ($userQuery) => $userQuery->where('name', 'like', '%'.$search.'%')));
            })
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('orders.index', compact('orders', 'filters'));
    }

    public function store(StoreOrderRequest $request, OrderService $service): JsonResponse
    {
        $order = $service->create($request->validated(), $request->user(), (int) session('active_company_id'), (int) session('active_branch_id'));

        return response()->json([
            'success' => true,
            'message' => "Pedido creado correctamente: {$order->number}",
            'order_id' => $order->id,
            'number' => $order->number,
            'show_url' => route('pedidos.show', $order),
        ], 201);
    }

    public function show(Request $request, Order $order): View
    {
        $companyId = (int) session('active_company_id');
        $order = $this->scoped($order)->load([
            'items' => fn ($query) => $query->withSum('purchaseOrderSources as allocated_quantity', 'allocated_quantity'),
            'items.supplier',
            'items.product.unit',
            'items.product.productSuppliers' => fn ($query) => $query
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->whereHas('supplier', fn ($supplierQuery) => $supplierQuery
                    ->where('company_id', $companyId)
                    ->where('is_active', true))
                ->with('supplier'),
            'requester',
            'branch',
            'reviewedBy',
            'rejectedBy',
            'cancelledBy',
        ]);
        $company = Company::query()->findOrFail($companyId);
        $canApprove = $request->user()->hasPermission('pedidos.aprobar', $company);
        $canReject = $request->user()->hasPermission('pedidos.rechazar', $company);
        $canPrepare = $request->user()->hasPermission('pedidos.preparar_compra', $company);
        $canAssociateSuppliers = $request->user()->hasPermission('productos.editar', $company);
        $canManageSupplierCosts = $request->user()->hasPermission('compras.ordenes', $company);
        $availableSuppliers = $canAssociateSuppliers
            ? Supplier::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'commercial_name'])
            : collect();
        $existingProductSupplierIds = $canAssociateSuppliers
            ? ProductSupplier::query()
                ->where('company_id', $companyId)
                ->whereIn('product_id', $order->items->pluck('product_id'))
                ->get(['product_id', 'supplier_id'])
                ->groupBy('product_id')
                ->map(fn ($relations) => $relations->pluck('supplier_id'))
            : collect();
        $hasPendingPreparation = $order->items->contains(fn ($item) => (float) $item->approved_quantity > (float) $item->allocated_quantity && $item->supplier_id !== null);

        return view('orders.show', compact('order', 'canApprove', 'canReject', 'canPrepare', 'canAssociateSuppliers', 'canManageSupplierCosts', 'availableSuppliers', 'existingProductSupplierIds', 'hasPendingPreparation'));
    }

    public function reviewItem(ReviewOrderItemRequest $request, Order $order, OrderItem $item, OrderService $service)
    {
        $order = $this->scoped($order);
        abort_unless((int) $item->order_id === (int) $order->id, 404);

        $company = Company::query()->findOrFail((int) session('active_company_id'));
        $permission = (float) $request->validated('approved_quantity') === 0.0 ? 'pedidos.rechazar' : 'pedidos.aprobar';
        abort_unless($request->user()->hasPermission($permission, $company), 403);

        try {
            $service->reviewItem($order, $item, $request->validated(), $request->user(), $company->id, (int) session('active_branch_id'));
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'errors' => $exception->errors(),
                ], 422);
            }

            throw $exception;
        }

        return redirect()->route('pedidos.show', $order)->with('success', 'Decisión de línea guardada correctamente.');
    }

    private function scoped(Order $order): Order
    {
        abort_unless((int) $order->company_id === (int) session('active_company_id') && (int) $order->branch_id === (int) session('active_branch_id'), 404);

        return $order;
    }
}
