<?php

namespace App\Services\Orders;

use App\Data\Purchases\PurchaseData;
use App\Data\Purchases\PurchaseLineData;
use App\Models\Company;
use App\Models\OrderItem;
use App\Models\ProductSupplier;
use App\Models\Purchase;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderItemSource;
use App\Models\User;
use App\Services\Purchases\PurchaseProcessor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseOrderConversionService
{
    public function __construct(private readonly PurchaseProcessor $processor) {}

    public function convert(
        PurchaseOrder $purchaseOrder,
        array $data,
        User $user,
        int $companyId,
        int $branchId
    ): Purchase {
        return DB::transaction(function () use (
            $purchaseOrder,
            $data,
            $user,
            $companyId,
            $branchId
        ) {
            $company = Company::query()
                ->whereKey($companyId)
                ->where('is_active', true)
                ->first();

            if (
                ! $company
                || ! $user->hasPermission('compras.ordenes', $company)
                || ! $user->hasPermission('compras.crear', $company)
            ) {
                throw ValidationException::withMessages([
                    'permission' => 'No está autorizado para convertir pedidos a proveedor.',
                ]);
            }

            $lockedOrder = PurchaseOrder::query()
                ->lockForUpdate()
                ->findOrFail($purchaseOrder->id);

            if (
                (int) $lockedOrder->company_id !== $companyId
                || (int) $lockedOrder->branch_id !== $branchId
            ) {
                throw ValidationException::withMessages([
                    'purchase_order' => 'El pedido a proveedor no pertenece al contexto activo.',
                ]);
            }

            if ($lockedOrder->status !== PurchaseOrder::STATUS_PREPARED) {
                throw ValidationException::withMessages([
                    'purchase_order' => 'Solo un pedido a proveedor preparado puede convertirse.',
                ]);
            }

            $inputs = collect($data['lines'] ?? [])
                ->keyBy(fn ($line) => (int) ($line['purchase_order_item_id'] ?? 0));

            if (
                $inputs->isEmpty()
                || $inputs->has(0)
                || $inputs->count() !== count($data['lines'] ?? [])
            ) {
                throw ValidationException::withMessages([
                    'lines' => 'Debe seleccionar líneas válidas sin duplicados.',
                ]);
            }

            $items = PurchaseOrderItem::query()
                ->where('purchase_order_id', $lockedOrder->id)
                ->whereIn('id', $inputs->keys())
                ->with([
                    'product',
                    'sources.conversions',
                    'sources.orderItem.order',
                ])
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($items->count() !== $inputs->count()) {
                throw ValidationException::withMessages([
                    'lines' => 'Una línea no pertenece al pedido a proveedor.',
                ]);
            }

            $sources = PurchaseOrderItemSource::query()
                ->whereIn('purchase_order_item_id', $items->keys())
                ->lockForUpdate()
                ->get();

            OrderItem::query()
                ->whereIn('id', $sources->pluck('order_item_id'))
                ->lockForUpdate()
                ->get();

            $purchaseLines = [];

            foreach ($inputs as $itemId => $input) {
                $item = $items->get($itemId);
                $quantity = round((float) $input['quantity'], 4);

                if (
                    ! $item->product
                    || (int) $item->product->company_id !== $companyId
                ) {
                    throw ValidationException::withMessages([
                        'lines' => 'Un producto no pertenece a la empresa activa.',
                    ]);
                }

                if (
                    $quantity <= 0
                    || (
                        ! $item->product->unit?->allows_decimals
                        && floor($quantity) !== $quantity
                    )
                ) {
                    throw ValidationException::withMessages([
                        'lines' => 'La cantidad a convertir no es válida.',
                    ]);
                }

                $converted = (float) $item->sources->sum(
                    fn ($source) => $source->conversions->sum('converted_quantity')
                );

                $pending = round(
                    (float) $item->ordered_quantity - $converted,
                    4
                );

                if ($pending <= 0 || $quantity > $pending) {
                    throw ValidationException::withMessages([
                        'lines' => 'La cantidad supera el saldo pendiente del pedido a proveedor.',
                    ]);
                }

                foreach ($item->sources as $source) {
                    if (
                        (int) $source->orderItem->order->company_id !== $companyId
                        || (int) $source->orderItem->order->branch_id !== $branchId
                        || (int) $source->orderItem->product_id !== (int) $item->product_id
                        || (int) $source->orderItem->supplier_id !== (int) $lockedOrder->supplier_id
                    ) {
                        throw ValidationException::withMessages([
                            'lines' => 'La trazabilidad de una línea no coincide con el contexto del pedido.',
                        ]);
                    }
                }

                $relation = ProductSupplier::query()
                    ->where('company_id', $companyId)
                    ->where('product_id', $item->product_id)
                    ->where('supplier_id', $lockedOrder->supplier_id)
                    ->where('is_active', true)
                    ->whereHas(
                        'supplier',
                        fn ($query) => $query
                            ->where('company_id', $companyId)
                            ->where('is_active', true)
                    )
                    ->lockForUpdate()
                    ->first();

                if (! $relation || $relation->current_cost === null) {
                    throw ValidationException::withMessages([
                        'lines' => 'No existe un costo autorizado activo para el producto y proveedor.',
                    ]);
                }

                $purchaseLines[$itemId] = new PurchaseLineData(
                    product_id: $item->product_id,
                    quantity: $quantity,
                    unit_cost: (float) $relation->current_cost,
                );
            }

            $purchase = $this->processor->process(
                new PurchaseData(
                    company_id: $companyId,
                    branch_id: $branchId,
                    supplier_id: $lockedOrder->supplier_id,
                    user_id: $user->id,
                    purchase_date: today()->toDateString(),
                    payment_type: $data['payment_type'],
                    supplier_invoice_number: $data['supplier_invoice_number'] ?? null,
                    due_date: $data['payment_type'] === 'credit'
                        ? ($data['due_date'] ?? null)
                        : null,
                    notes: $data['notes'] ?? 'Conversión de '.$lockedOrder->number,
                    lines: array_values($purchaseLines),
                )
            );

            $purchaseItems = $purchase->items()
                ->get()
                ->keyBy('product_id');

            foreach ($inputs as $itemId => $input) {
                $item = $items->get($itemId);
                $purchaseItem = $purchaseItems->get($item->product_id);
                $remaining = round((float) $input['quantity'], 4);

                foreach ($item->sources->sortBy('id') as $source) {
                    $sourceConverted = (float) $source->conversions
                        ->sum('converted_quantity');

                    $sourcePending = round(
                        (float) $source->allocated_quantity - $sourceConverted,
                        4
                    );

                    $take = min($remaining, $sourcePending);

                    if ($take > 0) {
                        $source->conversions()->create([
                            'purchase_item_id' => $purchaseItem->id,
                            'converted_quantity' => $take,
                        ]);

                        $remaining = round($remaining - $take, 4);
                    }

                    if ($remaining <= 0) {
                        break;
                    }
                }

                if ($remaining > 0) {
                    throw ValidationException::withMessages([
                        'lines' => 'La cantidad no pudo distribuirse entre sus fuentes.',
                    ]);
                }
            }

            $allConverted = $lockedOrder->items()
                ->with('sources.conversions')
                ->get()
                ->every(function ($item) {
                    $converted = $item->sources->sum(
                        fn ($source) => $source->conversions->sum('converted_quantity')
                    );

                    return (float) $converted >= (float) $item->ordered_quantity;
                });

            if ($allConverted) {
                $lockedOrder->update([
                    'status' => PurchaseOrder::STATUS_RECEIVED,
                ]);
            }

            return $purchase->fresh([
                'items.purchaseOrderSourceConversions.source.orderItem.order',
                'accountPayable',
            ]);
        }, 3);
    }
}
