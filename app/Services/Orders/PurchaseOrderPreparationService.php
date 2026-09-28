<?php

namespace App\Services\Orders;

use App\Models\Company;
use App\Models\CompanySequence;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductSupplier;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseOrderPreparationService
{
    public function prepare(array $data, User $user, int $companyId, int $branchId): Collection
    {
        return DB::transaction(function () use ($data, $user, $companyId, $branchId) {
            $company = Company::query()->whereKey($companyId)->where('is_active', true)->first();
            if (! $company || ! $user->hasPermission('pedidos.preparar_compra', $company)) {
                throw ValidationException::withMessages(['permission' => 'No está autorizado para preparar pedidos a proveedor.']);
            }

            $lines = collect($data['lines'] ?? [])->keyBy(fn ($line) => (int) ($line['order_item_id'] ?? 0));
            if ($lines->isEmpty() || $lines->has(0) || $lines->count() !== count($data['lines'] ?? [])) {
                throw ValidationException::withMessages(['lines' => 'Debe seleccionar líneas válidas sin duplicados.']);
            }

            $items = OrderItem::query()
                ->with(['order', 'product'])
                ->whereIn('id', $lines->keys())
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($items->count() !== $lines->count()) {
                throw ValidationException::withMessages(['lines' => 'Una o más líneas ya no existen.']);
            }

            $groups = [];
            $affectedOrders = [];
            foreach ($lines as $itemId => $input) {
                $item = $items->get($itemId);
                $order = $item->order;
                $quantity = round((float) $input['allocated_quantity'], 4);

                if ((int) $order->company_id !== $companyId || (int) $order->branch_id !== $branchId) {
                    throw ValidationException::withMessages(['lines' => 'Una línea no pertenece a la empresa y sucursal activas.']);
                }
                if (! in_array($order->status, [Order::STATUS_APPROVED, Order::STATUS_PARTIAL, Order::STATUS_IN_PURCHASE], true)) {
                    throw ValidationException::withMessages(['lines' => 'El pedido interno no está disponible para preparación.']);
                }
                if (! in_array($item->item_status, [OrderItem::STATUS_APPROVED, OrderItem::STATUS_PARTIAL], true)
                    || (float) $item->approved_quantity <= 0 || ! $item->supplier_id) {
                    throw ValidationException::withMessages(['lines' => 'Solo se pueden preparar líneas aprobadas con proveedor.']);
                }
                if ($quantity <= 0 || (! $item->allows_decimals_snapshot && floor($quantity) !== $quantity)) {
                    throw ValidationException::withMessages(['lines' => 'La cantidad a preparar no es válida para la unidad.']);
                }

                $allocated = (float) $item->purchaseOrderSources()->sum('allocated_quantity');
                $available = round((float) $item->approved_quantity - $allocated, 4);
                if ($quantity > $available) {
                    throw ValidationException::withMessages(['lines' => 'La cantidad a preparar supera la cantidad aprobada disponible.']);
                }

                $productSupplier = ProductSupplier::query()
                    ->where('company_id', $companyId)
                    ->where('product_id', $item->product_id)
                    ->where('supplier_id', $item->supplier_id)
                    ->where('is_active', true)
                    ->whereHas('supplier', fn ($query) => $query->where('company_id', $companyId)->where('is_active', true))
                    ->lockForUpdate()
                    ->first();
                if (! $productSupplier || ! $item->product) {
                    throw ValidationException::withMessages(['lines' => 'El proveedor o producto de una línea ya no está disponible.']);
                }

                $groups[$item->supplier_id][$item->product_id]['product_supplier'] = $productSupplier;
                $groups[$item->supplier_id][$item->product_id]['item'] = $item;
                $groups[$item->supplier_id][$item->product_id]['sources'][] = [$item, $quantity];
                $affectedOrders[$order->id] = $order->id;
            }

            $purchaseOrders = collect();
            foreach ($groups as $supplierId => $products) {
                $purchaseOrder = PurchaseOrder::create([
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'supplier_id' => $supplierId,
                    'number' => CompanySequence::nextPurchaseOrderNumber($companyId),
                    'status' => PurchaseOrder::STATUS_PREPARED,
                    'notes' => $data['notes'] ?? null,
                    'requested_at' => now(),
                    'prepared_at' => now(),
                    'prepared_by' => $user->id,
                ]);

                foreach ($products as $group) {
                    $quantity = collect($group['sources'])->sum(fn ($source) => $source[1]);
                    $item = $group['item'];
                    $relation = $group['product_supplier'];
                    $purchaseOrderItem = $purchaseOrder->items()->create([
                        'product_id' => $item->product_id,
                        'description' => $item->description,
                        'supplier_product_code' => $relation->supplier_product_code,
                        'unit_code' => $item->unit_code,
                        'requested_quantity' => $quantity,
                        'ordered_quantity' => $quantity,
                        'unit_cost_snapshot' => $relation->current_cost,
                    ]);
                    foreach ($group['sources'] as [$sourceItem, $sourceQuantity]) {
                        $purchaseOrderItem->sources()->create([
                            'order_item_id' => $sourceItem->id,
                            'allocated_quantity' => $sourceQuantity,
                        ]);
                    }
                }
                $purchaseOrders->push($purchaseOrder->load('items.sources'));
            }

            foreach ($affectedOrders as $orderId) {
                $order = Order::query()->lockForUpdate()->findOrFail($orderId);
                $positiveItems = $order->items()->where('approved_quantity', '>', 0)->withSum('purchaseOrderSources as allocated_quantity', 'allocated_quantity')->get();
                if ($positiveItems->isNotEmpty() && $positiveItems->every(fn ($item) => (float) $item->allocated_quantity >= (float) $item->approved_quantity)) {
                    $order->update(['status' => Order::STATUS_IN_PURCHASE]);
                }
            }

            return $purchaseOrders;
        }, 3);
    }
}
