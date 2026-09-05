<?php

namespace App\Services\Inventory;

use App\Data\Purchases\PurchaseLineData;
use App\Models\Branch;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferItem;
use App\Models\LoyaltyRewardRedemption;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Sale;
use App\Models\SaleReturn;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryPostingService
{
    private const QUANTITY_SCALE = 4;

    public function postInitialMigration(
        Branch $branch,
        Product $product,
        int $userId,
        int $batchId,
        string $quantity,
        ?string $minimumStock,
        ?string $maximumStock,
        string $occurredAt,
        ?string $notes,
    ): InventoryMovement {
        $this->assertMigrationContext($branch, $product, $quantity);
        DB::table('branch_product')->insertOrIgnore([
            'branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => '0.0000',
            'minimum_stock' => $minimumStock, 'maximum_stock' => $maximumStock,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $inventory = DB::table('branch_product')->where('branch_id', $branch->id)
            ->where('product_id', $product->id)->lockForUpdate()->first();
        if ($inventory === null) {
            throw ValidationException::withMessages(['inventario' => 'No se pudo bloquear el inventario de la sucursal.']);
        }
        $previous = $this->inventoryDecimal($inventory->stock);
        DB::table('branch_product')->where('id', $inventory->id)->update([
            'stock' => $quantity, 'minimum_stock' => $minimumStock, 'maximum_stock' => $maximumStock, 'updated_at' => now(),
        ]);

        return $this->historicalMovement($branch, $product, $userId, $batchId, 'initial_balance', $quantity, $previous, $quantity, $occurredAt, $notes);
    }

    public function postHistoricalMigration(
        Branch $branch,
        Product $product,
        int $userId,
        int $batchId,
        string $movementType,
        string $quantity,
        string $previousStock,
        string $newStock,
        string $occurredAt,
        ?string $notes,
    ): InventoryMovement {
        $this->assertMigrationContext($branch, $product, $quantity);
        if (! in_array($movementType, ['entry', 'exit'], true)) {
            throw ValidationException::withMessages(['tipo_movimiento' => 'El movimiento histórico debe ser entrada o salida.']);
        }

        return $this->historicalMovement($branch, $product, $userId, $batchId, 'historical_'.$movementType, $quantity, $previousStock, $newStock, $occurredAt, $notes);
    }

    private function historicalMovement(Branch $branch, Product $product, int $userId, int $batchId, string $type, string $quantity, string $previousStock, string $newStock, string $occurredAt, ?string $notes): InventoryMovement
    {
        $movement = InventoryMovement::create([
            'company_id' => $branch->company_id, 'branch_id' => $branch->id, 'product_id' => $product->id,
            'user_id' => $userId, 'type' => $type, 'quantity' => $quantity,
            'previous_stock' => $previousStock, 'new_stock' => $newStock,
            'reason' => $type === 'initial_balance' ? 'Saldo inicial migrado' : 'Movimiento histórico migrado',
            'reference_type' => 'inventory_migration', 'reference_id' => $batchId,
            'notes' => $notes,
        ]);
        $movement->timestamps = false;
        $movement->forceFill(['created_at' => $occurredAt, 'updated_at' => $occurredAt])->save();

        return $movement;
    }

    private function assertMigrationContext(Branch $branch, Product $product, string $quantity): void
    {
        if ((int) $branch->company_id !== (int) $product->company_id || ! $branch->is_active || ! $product->track_inventory || bccomp($quantity, '0', self::QUANTITY_SCALE) < 0) {
            throw ValidationException::withMessages(['inventario' => 'La sucursal, producto o cantidad de migración no es válida.']);
        }
        if (! $product->unit?->allows_decimals && rtrim(substr($quantity.'.', strpos($quantity.'.', '.') + 1), '0.') !== '') {
            throw ValidationException::withMessages(['cantidad' => 'Este producto solo admite cantidades enteras.']);
        }
    }

    public function postTransfer(
        Branch $fromBranch,
        Branch $toBranch,
        Product $product,
        string $quantity,
        int $userId,
        ?string $notes = null,
    ): InventoryTransfer {
        $quantity = $this->transferQuantity($quantity);

        if ($fromBranch->company_id !== $toBranch->company_id
            || $fromBranch->company_id !== $product->company_id
            || ! $fromBranch->is_active
            || ! $toBranch->is_active) {
            throw ValidationException::withMessages([
                'to_branch_id' => 'Las sucursales y el producto deben pertenecer a la empresa activa.',
            ]);
        }

        if ($fromBranch->is($toBranch)) {
            throw ValidationException::withMessages([
                'to_branch_id' => 'La sucursal destino debe ser diferente a la sucursal origen.',
            ]);
        }

        if (! $product->track_inventory) {
            throw ValidationException::withMessages([
                'product_id' => 'El producto seleccionado no controla inventario.',
            ]);
        }

        if (! $product->unit?->allows_decimals && str_contains($quantity, '.') && rtrim(substr($quantity, strpos($quantity, '.') + 1), '0') !== '') {
            throw ValidationException::withMessages([
                'quantity' => 'Este producto solo admite cantidades enteras.',
            ]);
        }

        return DB::transaction(function () use ($fromBranch, $toBranch, $product, $quantity, $userId, $notes) {
            $lot = InventoryLot::query()
                ->where('company_id', $fromBranch->company_id)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->first();

            if ($lot !== null) {
                throw ValidationException::withMessages([
                    'product_id' => 'Los productos gestionados por lote requieren un flujo de traslado de lotes.',
                ]);
            }

            DB::table('branch_product')->insertOrIgnore([
                'branch_id' => $toBranch->id,
                'product_id' => $product->id,
                'stock' => '0.0000',
                'minimum_stock' => null,
                'maximum_stock' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $stocks = DB::table('branch_product')
                ->whereIn('branch_id', [$fromBranch->id, $toBranch->id])
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('branch_id');

            $fromInventory = $stocks->get($fromBranch->id);
            $toInventory = $stocks->get($toBranch->id);

            if ($fromInventory === null || $toInventory === null) {
                throw ValidationException::withMessages([
                    'quantity' => 'No se pudo obtener el inventario de las sucursales.',
                ]);
            }

            $fromPreviousStock = $this->inventoryDecimal($fromInventory->stock);
            $toPreviousStock = $this->inventoryDecimal($toInventory->stock);

            if (bccomp($fromPreviousStock, $quantity, self::QUANTITY_SCALE) < 0) {
                throw ValidationException::withMessages([
                    'quantity' => 'No hay suficiente inventario en la sucursal origen. Disponible: '.$fromPreviousStock,
                ]);
            }

            $fromNewStock = bcsub($fromPreviousStock, $quantity, self::QUANTITY_SCALE);
            $toNewStock = bcadd($toPreviousStock, $quantity, self::QUANTITY_SCALE);

            DB::table('branch_product')->where('id', $fromInventory->id)->update([
                'stock' => $fromNewStock,
                'updated_at' => now(),
            ]);
            DB::table('branch_product')->where('id', $toInventory->id)->update([
                'stock' => $toNewStock,
                'updated_at' => now(),
            ]);

            $transfer = InventoryTransfer::create([
                'company_id' => $fromBranch->company_id,
                'from_branch_id' => $fromBranch->id,
                'to_branch_id' => $toBranch->id,
                'user_id' => $userId,
                'transfer_number' => $this->nextTransferNumber(),
                'status' => 'completed',
                'notes' => $notes,
                'transferred_at' => now(),
            ]);

            InventoryTransferItem::create([
                'inventory_transfer_id' => $transfer->id,
                'product_id' => $product->id,
                'quantity' => $quantity,
                'from_previous_stock' => $fromPreviousStock,
                'from_new_stock' => $fromNewStock,
                'to_previous_stock' => $toPreviousStock,
                'to_new_stock' => $toNewStock,
            ]);

            InventoryMovement::create([
                'company_id' => $fromBranch->company_id,
                'branch_id' => $fromBranch->id,
                'product_id' => $product->id,
                'user_id' => $userId,
                'type' => 'transfer_out',
                'quantity' => $quantity,
                'previous_stock' => $fromPreviousStock,
                'new_stock' => $fromNewStock,
                'reason' => 'Transferencia a '.$toBranch->name,
                'reference_type' => 'inventory_transfer',
                'reference_id' => $transfer->id,
                'notes' => $notes,
            ]);

            InventoryMovement::create([
                'company_id' => $fromBranch->company_id,
                'branch_id' => $toBranch->id,
                'product_id' => $product->id,
                'user_id' => $userId,
                'type' => 'transfer_in',
                'quantity' => $quantity,
                'previous_stock' => $toPreviousStock,
                'new_stock' => $toNewStock,
                'reason' => 'Transferencia recibida',
                'reference_type' => 'inventory_transfer',
                'reference_id' => $transfer->id,
                'notes' => $notes,
            ]);

            return $transfer->load('items');
        });
    }

    public function prepareTransfer(
        InventoryTransfer $transfer,
        int $userId,
        ?string $notes = null
    ): InventoryTransfer {
        if (! $transfer->isPending()) {
            throw ValidationException::withMessages([
                'transfer' => 'Solo se puede preparar un traslado con estatus pending.',
            ]);
        }

        $transfer->status = InventoryTransfer::STATUS_PREPARED;
        $transfer->prepared_by = $userId;
        $transfer->prepared_at = now();
        $transfer->save();

        return $transfer;
    }

    public function dispatchTransfer(
        InventoryTransfer $transfer,
        int $userId,
        ?string $notes = null
    ): InventoryTransfer {
        if (! $transfer->isPrepared()) {
            throw ValidationException::withMessages([
                'transfer' => 'Solo se puede despachar un traslado con estatus prepared.',
            ]);
        }

        $fromBranch = Branch::query()->where('id', $transfer->from_branch_id)->lockForUpdate()->firstOrFail();
        $toBranch = Branch::query()->where('id', $transfer->to_branch_id)->lockForUpdate()->firstOrFail();

        $this->assertSameCompany($fromBranch, $toBranch, $transfer->items->first()->product);

        if ($fromBranch->is($toBranch)) {
            throw ValidationException::withMessages([
                'transfer' => 'La sucursal destino debe ser diferente a la origen.',
            ]);
        }

        $items = $transfer->items()->with('product')->lockForUpdate()->get();

        foreach ($items as $item) {
            $quantity = $this->transferQuantity($item->quantity ?? '0');
            $product = $item->product;

            $currentStock = DB::table('branch_product')
                ->where('branch_id', $fromBranch->id)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->value('stock') ?? '0';
            $fromPreviousStock = $this->inventoryDecimal($currentStock);

            if (bccomp($fromPreviousStock, $quantity, self::QUANTITY_SCALE) < 0) {
                throw ValidationException::withMessages([
                    'transfer' => "No hay suficiente inventario en la sucursal origen para {$product->name}.",
                ]);
            }

            $fromNewStock = bcsub($fromPreviousStock, $quantity, self::QUANTITY_SCALE);

            DB::table('branch_product')
                ->where('branch_id', $fromBranch->id)
                ->where('product_id', $product->id)
                ->update(['stock' => $fromNewStock, 'updated_at' => now()]);

            $item->sent_quantity = $quantity;
            $item->from_previous_stock = $fromPreviousStock;
            $item->from_new_stock = $fromNewStock;
            $item->save();

            InventoryMovement::create([
                'company_id' => $fromBranch->company_id,
                'branch_id' => $fromBranch->id,
                'product_id' => $product->id,
                'user_id' => $userId,
                'type' => 'transfer_out',
                'quantity' => $quantity,
                'previous_stock' => $fromPreviousStock,
                'new_stock' => $fromNewStock,
                'reason' => 'Transferencia a '.$toBranch->name,
                'reference_type' => 'inventory_transfer',
                'reference_id' => $transfer->id,
                'notes' => $notes,
            ]);
        }

        $transfer->status = InventoryTransfer::STATUS_IN_TRANSIT;
        $transfer->dispatched_by = $userId;
        $transfer->dispatched_at = now();
        $transfer->save();

        return $transfer;
    }

    public function receiveTransfer(
        InventoryTransfer $transfer,
        int $userId,
        ?string $notes = null,
        ?string $receivedQuantity = null
    ): InventoryTransfer {
        // Solo se puede recibir desde in_review, no desde pending ni otros estados
        if (! $transfer->isInReview()) {
            throw ValidationException::withMessages([
                'transfer' => 'Solo se puede recibir un traslado con estatus in_review.',
            ]);
        }

        $toBranch = Branch::query()->where('id', $transfer->to_branch_id)->lockForUpdate()->firstOrFail();
        $fromBranch = Branch::query()->where('id', $transfer->from_branch_id)->lockForUpdate()->firstOrFail();

        $this->assertSameCompany($fromBranch, $toBranch, $transfer->items->first()->product);

        $items = $transfer->items()->with('product')->lockForUpdate()->get();

        foreach ($items as $item) {
            $product = $item->product;
            $sentQuantity = $item->sent_quantity ?? $item->quantity ?? '0';

            // For single product, accept received_quantity from parameter
            $receivedQ = $receivedQuantity ?? $sentQuantity;

            $receivedQ = $this->transferQuantity((string) $receivedQ);

            // Calcular diferencia en backend
            $difference = bccomp($receivedQ, $sentQuantity, self::QUANTITY_SCALE);

            // Ingresar solo la cantidad recibida al inventario destino
            DB::table('branch_product')->insertOrIgnore([
                'branch_id' => $toBranch->id,
                'product_id' => $product->id,
                'stock' => '0.0000',
                'minimum_stock' => null,
                'maximum_stock' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $toCurrentStock = DB::table('branch_product')
                ->where('branch_id', $toBranch->id)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->value('stock') ?? '0';
            $toPreviousStock = $this->inventoryDecimal($toCurrentStock);
            $toNewStock = bcadd($toPreviousStock, $receivedQ, self::QUANTITY_SCALE);

            DB::table('branch_product')
                ->where('branch_id', $toBranch->id)
                ->where('product_id', $product->id)
                ->update(['stock' => $toNewStock, 'updated_at' => now()]);

            // Actualizar item de transferencia
            $item->received_quantity = $receivedQ;
            $item->difference = $difference !== 0 ? bcsub($receivedQ, $sentQuantity, self::QUANTITY_SCALE) : null;
            $item->to_previous_stock = $toPreviousStock;
            $item->to_new_stock = $toNewStock;
            $item->save();

            // Registrar movimiento de recibido
            InventoryMovement::create([
                'company_id' => $toBranch->company_id,
                'branch_id' => $toBranch->id,
                'product_id' => $product->id,
                'user_id' => $userId,
                'type' => 'transfer_received',
                'quantity' => $receivedQ,
                'previous_stock' => $toPreviousStock,
                'new_stock' => $toNewStock,
                'reason' => 'Recibo de transferencia',
                'reference_type' => 'inventory_transfer',
                'reference_id' => $transfer->id,
                'notes' => $notes,
            ]);
        }

        // Determinar si hay diferencias en algún item
        $hasDifferences = $items->contains(function ($item) {
            return $item->difference !== null;
        });

        $transfer->status = $hasDifferences
            ? InventoryTransfer::STATUS_RECEIVED_WITH_DIFFERENCES
            : InventoryTransfer::STATUS_RECEIVED;

        $transfer->received_by = $userId;
        $transfer->received_at = now();
        $transfer->confirmed_by = $userId;
        $transfer->confirmed_at = now();
        $transfer->received_quantity_total = $items->sum('received_quantity');
        $transfer->save();

        return $transfer;
    }

    private function assertSameCompany(Branch $fromBranch, Branch $toBranch, Product $product): void
    {
        if ((int) $fromBranch->company_id !== (int) $product->company_id
            || (int) $toBranch->company_id !== (int) $product->company_id
            || (int) $fromBranch->company_id !== (int) $toBranch->company_id) {
            throw ValidationException::withMessages([
                'transfer' => 'Las sucursales y el producto deben pertenecer a la misma empresa.',
            ]);
        }
    }

    public function transferQuantity(string $value): string
    {
        $value = trim($value);

        if (! preg_match('/^\d+(?:\.\d{1,4})?$/', $value)) {
            throw ValidationException::withMessages([
                'quantity' => 'La cantidad debe tener como máximo cuatro decimales.',
            ]);
        }

        $quantity = bcadd($value, '0', self::QUANTITY_SCALE);

        if (bccomp($quantity, '0', self::QUANTITY_SCALE) <= 0
            || bccomp($quantity, '99999999999.9999', self::QUANTITY_SCALE) > 0) {
            throw ValidationException::withMessages([
                'quantity' => 'La cantidad está fuera del rango permitido.',
            ]);
        }

        return $quantity;
    }

    private function inventoryDecimal(mixed $value): string
    {
        return bcadd((string) $value, '0', self::QUANTITY_SCALE);
    }

    public function nextTransferNumber(): string
    {
        do {
            $number = 'TR-'.now()->format('YmdHis').'-'.random_int(100000, 999999);
        } while (InventoryTransfer::query()->where('transfer_number', $number)->exists());

        return $number;
    }
}