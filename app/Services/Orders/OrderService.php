<?php

namespace App\Services\Orders;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanySequence;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\PurchaseItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function create(array $data, User $user, int $companyId, int $branchId): Order
    {
        return DB::transaction(function () use ($data, $user, $companyId, $branchId): Order {
            $this->validateContext($user, $companyId, $branchId);

            $inputItems = collect($data['items'] ?? []);
            if ($inputItems->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'El pedido debe contener al menos un producto.']);
            }

            $items = $inputItems
                ->groupBy(fn (array $item): int => (int) ($item['product_id'] ?? 0))
                ->map(function ($productItems, int $productId): array {
                    $notes = $productItems->pluck('request_note')
                        ->filter(fn ($note) => is_string($note) && trim($note) !== '')
                        ->map(fn (string $note): string => trim($note))
                        ->unique()
                        ->implode("\n");

                    return [
                        'product_id' => $productId,
                        'requested_quantity' => $productItems->sum(fn (array $item): float => (float) ($item['requested_quantity'] ?? 0)),
                        'request_note' => $notes !== '' ? $notes : null,
                    ];
                });

            if ($items->has(0)) {
                throw ValidationException::withMessages(['items' => 'El pedido contiene productos inválidos.']);
            }

            $products = Product::query()
                ->with('unit:id,abbreviation,allows_decimals')
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->whereIn('id', $items->keys())
                ->get()
                ->keyBy('id');

            if ($products->count() !== $items->count()) {
                throw ValidationException::withMessages(['items' => 'Uno o más productos no pertenecen a la empresa o están inactivos.']);
            }

            foreach ($inputItems as $inputItem) {
                $product = $products->get((int) $inputItem['product_id']);
                $quantity = $this->decimal((float) ($inputItem['requested_quantity'] ?? 0));
                if ($quantity <= 0 || (! $product->unit?->allows_decimals && floor($quantity) !== $quantity)) {
                    throw ValidationException::withMessages(['items' => "La cantidad solicitada de {$product->name} no es válida."]);
                }
            }

            $stocks = DB::table('branch_product')
                ->where('branch_id', $branchId)
                ->whereIn('product_id', $items->keys())
                ->pluck('stock', 'product_id');

            $lastCosts = PurchaseItem::query()
                ->select('purchase_items.product_id', 'purchase_items.unit_cost')
                ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
                ->where('purchases.company_id', $companyId)
                ->where('purchases.status', 'posted')
                ->whereIn('purchase_items.product_id', $items->keys())
                ->orderByDesc('purchases.purchase_date')
                ->orderByDesc('purchase_items.id')
                ->get()
                ->unique('product_id')
                ->pluck('unit_cost', 'product_id');

            $order = Order::create([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'user_id' => $user->id,
                'number' => CompanySequence::nextOrderNumber($companyId),
                'status' => Order::STATUS_PENDING,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($items as $productId => $item) {
                $product = $products->get($productId);
                $quantity = $this->decimal((float) ($item['requested_quantity'] ?? 0));
                if ($quantity <= 0 || (! $product->unit?->allows_decimals && floor($quantity) !== $quantity)) {
                    throw ValidationException::withMessages(['items' => "La cantidad solicitada de {$product->name} no es válida."]);
                }

                $order->items()->create([
                    'product_id' => $product->id,
                    'description' => $product->name,
                    'internal_code' => $product->internal_code,
                    'barcode' => $product->barcode,
                    'unit_code' => $product->unit->abbreviation,
                    'allows_decimals_snapshot' => (bool) $product->unit->allows_decimals,
                    'requested_quantity' => $quantity,
                    'stock_snapshot' => $this->decimal((float) ($stocks[$product->id] ?? 0)),
                    'sale_price_snapshot' => $this->decimal((float) $product->sale_price),
                    'cost_snapshot' => $product->cost !== null ? $this->decimal((float) $product->cost) : null,
                    'last_cost_snapshot' => isset($lastCosts[$product->id]) ? $this->decimal((float) $lastCosts[$product->id]) : null,
                    'approved_quantity' => 0,
                    'supplier_id' => null,
                    'item_status' => OrderItem::STATUS_PENDING,
                    'request_note' => $item['request_note'] ?? null,
                ]);
            }

            return $order->load(['items.product.unit', 'requester', 'branch']);
        }, 3);
    }

    public function reviewItem(Order $order, OrderItem $item, array $data, User $user, int $companyId, int $branchId): Order
    {
        return DB::transaction(function () use ($order, $item, $data, $user, $companyId, $branchId): Order {
            $this->validateContext($user, $companyId, $branchId);

            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ((int) $lockedOrder->company_id !== $companyId || (int) $lockedOrder->branch_id !== $branchId) {
                throw ValidationException::withMessages(['order' => 'El pedido no pertenece al contexto activo.']);
            }
            if ($lockedOrder->status !== Order::STATUS_PENDING) {
                throw ValidationException::withMessages(['order' => 'El pedido ya no admite decisiones de revisión.']);
            }

            $lockedItem = OrderItem::query()->lockForUpdate()->where('order_id', $lockedOrder->id)->findOrFail($item->id);
            if ($lockedItem->item_status !== OrderItem::STATUS_PENDING) {
                throw ValidationException::withMessages(['item' => 'La línea ya fue revisada.']);
            }

            $approved = $this->decimal((float) $data['approved_quantity']);
            $requested = (float) $lockedItem->requested_quantity;
            if ($approved < 0 || $approved > $requested) {
                throw ValidationException::withMessages(['approved_quantity' => 'La cantidad aprobada debe estar entre cero y la cantidad solicitada.']);
            }
            if (! $lockedItem->allows_decimals_snapshot && floor($approved) !== $approved) {
                throw ValidationException::withMessages(['approved_quantity' => 'La unidad del producto solo admite cantidades enteras.']);
            }

            $supplierId = isset($data['supplier_id']) ? (int) $data['supplier_id'] : null;
            if ($approved > 0 && ! $supplierId) {
                throw ValidationException::withMessages(['supplier_id' => 'Debe seleccionar un proveedor para una cantidad aprobada.']);
            }
            if ($approved === 0.0 && $supplierId) {
                throw ValidationException::withMessages(['supplier_id' => 'Una línea rechazada no puede tener proveedor.']);
            }
            if ($approved > 0) {
                $validSupplier = ProductSupplier::query()
                    ->where('company_id', $companyId)
                    ->where('product_id', $lockedItem->product_id)
                    ->where('supplier_id', $supplierId)
                    ->where('is_active', true)
                    ->whereHas('supplier', fn ($query) => $query
                        ->where('company_id', $companyId)
                        ->where('is_active', true))
                    ->lockForUpdate()
                    ->exists();

                if (! $validSupplier) {
                    throw ValidationException::withMessages(['supplier_id' => 'El proveedor seleccionado no está activo y asociado al producto.']);
                }
            }

            $lineStatus = $approved === 0.0
                ? OrderItem::STATUS_REJECTED
                : ($approved === $requested ? OrderItem::STATUS_APPROVED : OrderItem::STATUS_PARTIAL);

            $lockedItem->update([
                'approved_quantity' => $approved,
                'supplier_id' => $approved === 0.0 ? null : $supplierId,
                'item_status' => $lineStatus,
                'review_note' => $data['review_note'] ?? null,
            ]);

            $items = $lockedOrder->items()->get();
            $headerStatus = match (true) {
                $items->contains(fn (OrderItem $line) => $line->item_status === OrderItem::STATUS_PENDING) => Order::STATUS_PENDING,
                $items->every(fn (OrderItem $line) => (float) $line->approved_quantity === 0.0) => Order::STATUS_REJECTED,
                $items->every(fn (OrderItem $line) => (float) $line->approved_quantity === (float) $line->requested_quantity) => Order::STATUS_APPROVED,
                default => Order::STATUS_PARTIAL,
            };

            $lockedOrder->update([
                'status' => $headerStatus,
                'reviewed_at' => now(),
                'reviewed_by' => $user->id,
            ]);

            return $lockedOrder->fresh(['items', 'reviewedBy']);
        }, 3);
    }

    private function validateContext(User $user, int $companyId, int $branchId): void
    {
        $companyExists = Company::query()->whereKey($companyId)->where('is_active', true)->exists();
        if (! $companyExists || ! $user->is_active || ! $user->companies()->whereKey($companyId)->exists()) {
            throw ValidationException::withMessages(['company' => 'La empresa activa no está autorizada.']);
        }

        $branchExists = Branch::query()->whereKey($branchId)->where('company_id', $companyId)->where('is_active', true)->exists();
        if (! $branchExists || ! $user->branches()->whereKey($branchId)->exists()) {
            throw ValidationException::withMessages(['branch' => 'La sucursal activa no está autorizada.']);
        }
    }

    private function decimal(float $value): float
    {
        return round($value, 4, PHP_ROUND_HALF_UP);
    }
}
