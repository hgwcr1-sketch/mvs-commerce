<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductSupplierRequest;
use App\Http\Requests\UpdateProductSupplierRequest;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ProductSupplierController extends Controller
{
    public function index(Request $request, Product $producto): View
    {
        $product = $this->productForActiveCompany($producto);
        $company = Company::query()->findOrFail((int) session('active_company_id'));

        $relations = $product->productSuppliers()
            ->with('supplier')
            ->orderByDesc('is_primary')
            ->orderByDesc('is_active')
            ->get();

        $suppliers = Supplier::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->whereNotIn('id', $relations->pluck('supplier_id'))
            ->orderBy('name')
            ->get(['id', 'name', 'commercial_name']);

        $canEdit = $request->user()->hasPermission('productos.editar', $company);
        $canManageCosts = $request->user()->hasPermission('compras.ordenes', $company);

        return view('productos.suppliers', compact('product', 'relations', 'suppliers', 'canEdit', 'canManageCosts'));
    }

    public function store(StoreProductSupplierRequest $request, Product $producto): RedirectResponse
    {
        $product = $this->productForActiveCompany($producto);
        $data = $request->validated();

        $this->createRelation($product, $data);

        return back()->with('success', 'Proveedor asociado correctamente.');
    }

    public function storeFromOrder(StoreProductSupplierRequest $request, Order $order, OrderItem $item): JsonResponse
    {
        abort_unless(
            (int) $order->company_id === (int) session('active_company_id')
            && (int) $order->branch_id === (int) session('active_branch_id')
            && (int) $item->order_id === (int) $order->id,
            404
        );
        abort_unless($order->status === Order::STATUS_PENDING && $item->item_status === OrderItem::STATUS_PENDING, 422);

        $product = $this->productForActiveCompany($item->product()->firstOrFail());
        $relation = $this->createRelation($product, $request->validated());

        return response()->json([
            'message' => 'Proveedor asociado correctamente.',
            'supplier' => [
                'id' => $relation->supplier_id,
                'name' => $relation->supplier->commercial_name ?: $relation->supplier->name,
            ],
        ], 201);
    }

    public function update(UpdateProductSupplierRequest $request, Product $producto, ProductSupplier $productSupplier): RedirectResponse
    {
        $product = $this->productForActiveCompany($producto);
        $relation = $this->relationForProduct($productSupplier, $product);

        DB::transaction(function () use ($product, $relation, $request) {
            Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $relation->update($request->validated());
        });

        return back()->with('success', 'Relación con proveedor actualizada correctamente.');
    }

    public function destroy(Request $request, Product $producto, ProductSupplier $productSupplier): RedirectResponse
    {
        $product = $this->productForActiveCompany($producto);
        $relation = $this->relationForProduct($productSupplier, $product);
        $company = Company::query()->findOrFail((int) session('active_company_id'));
        abort_unless($request->user()->hasPermission('productos.editar', $company), 403);

        $relation->delete();

        return back()->with('success', 'Relación con proveedor eliminada correctamente.');
    }

    private function productForActiveCompany(Product $product): Product
    {
        abort_unless((int) $product->company_id === (int) session('active_company_id'), 404);

        return $product;
    }

    private function relationForProduct(ProductSupplier $relation, Product $product): ProductSupplier
    {
        abort_unless(
            (int) $relation->company_id === (int) $product->company_id
            && (int) $relation->product_id === (int) $product->id,
            404
        );

        return $relation;
    }

    private function createRelation(Product $product, array $data): ProductSupplier
    {
        return DB::transaction(function () use ($product, $data): ProductSupplier {
            $lockedProduct = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $hasActiveSupplier = $lockedProduct->productSuppliers()->where('is_active', true)->exists();

            if (($data['is_active'] ?? false) && ! $hasActiveSupplier) {
                $data['is_primary'] = true;
            }

            return $lockedProduct->productSuppliers()
                ->create($data + ['company_id' => $lockedProduct->company_id])
                ->load('supplier');
        });
    }
}
