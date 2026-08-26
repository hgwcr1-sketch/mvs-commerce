<?php

namespace App\Services\Inventory;

use App\Data\Purchases\PurchaseLineData;
use App\Models\Branch;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
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
    public function postImportMovement(
        Branch $branch,
        Product $product,
        int $userId,
        string $movementType,
        float $quantity,
        float $minimumStock,
        float $maximumStock,
    ): InventoryMovement {
        if ($branch->company_id !== $product->company_id || ! in_array($movementType, ['entry', 'exit'], true) || $quantity <= 0) {
            throw ValidationException::withMessages(['inventory' => 'El movimiento de importación no es válido.']);
        }

        DB::table('branch_product')->insertOrIgnore([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'stock' => 0,
            'minimum_stock' => $minimumStock,
            'maximum_stock' => $maximumStock,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stock = DB::table('branch_product')->where('branch_id', $branch->id)
            ->where('product_id', $product->id)->lockForUpdate()->first();
        if ($stock === null) {
            throw ValidationException::withMessages(['inventory' => 'No se pudo obtener el inventario de la sucursal.']);
        }

        $previousStock = (float) $stock->stock;
        $newStock = round($movementType === 'entry' ? $previousStock + $quantity : $previousStock - $quantity, 4);
        if ($newStock < 0) {
            throw ValidationException::withMessages(['inventory' => 'La salida no puede dejar el inventario con stock negativo.']);
        }

        DB::table('branch_product')->where('id', $stock->id)->update([
            'stock' => $newStock,
            'minimum_stock' => $minimumStock,
            'maximum_stock' => $maximumStock,
            'updated_at' => now(),
        ]);

        return InventoryMovement::create([
            'company_id' => $branch->company_id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'user_id' => $userId,
            'type' => $movementType,
            'quantity' => round($quantity, 4),
            'previous_stock' => round($previousStock, 4),
            'new_stock' => $newStock,
            'reason' => 'Importación de inventario',
            'reference_type' => 'inventory_import',
            'notes' => 'Movimiento generado por importación Excel.',
        ]);
    }

    public function postSale(Sale $sale, Product $product, float $quantity): InventoryMovement
    {
        if ($quantity <= 0 || $sale->company_id !== $product->company_id) {
            throw ValidationException::withMessages([
                'items' => 'La línea de inventario de la venta no es válida.',
            ]);
        }

        $branchProduct = DB::table('branch_product')
            ->where('branch_id', $sale->branch_id)
            ->where('product_id', $product->id)
            ->lockForUpdate()
            ->first();

        $previousStock = $branchProduct === null ? 0.0 : (float) $branchProduct->stock;

        if ($branchProduct === null || $previousStock < $quantity) {
            throw ValidationException::withMessages([
                'items' => "Stock insuficiente para {$product->name}. Disponible: ".number_format($previousStock, 4, '.', ''),
            ]);
        }

        $newStock = round($previousStock - $quantity, 4);

        DB::table('branch_product')->where('id', $branchProduct->id)->update([
            'stock' => $newStock,
            'updated_at' => now(),
        ]);

        return InventoryMovement::create([
            'company_id' => $sale->company_id,
            'branch_id' => $sale->branch_id,
            'product_id' => $product->id,
            'inventory_lot_id' => null,
            'user_id' => $sale->user_id,
            'type' => 'sale',
            'quantity' => round($quantity, 4),
            'previous_stock' => round($previousStock, 4),
            'new_stock' => $newStock,
            'reason' => 'Salida por venta',
            'reference_type' => Sale::class,
            'reference_id' => $sale->id,
            'notes' => 'Venta '.$sale->sale_number,
        ]);
    }

    public function voidSale(Sale $sale, Product $product, float $quantity, int $userId): InventoryMovement
{
    if ($quantity <= 0 || $sale->company_id !== $product->company_id) {
        throw ValidationException::withMessages([
            'items' => 'La devolución de inventario de la venta no es válida.',
        ]);
    }

    DB::table('branch_product')->insertOrIgnore([
        'branch_id' => $sale->branch_id,
        'product_id' => $product->id,
        'stock' => 0,
        'minimum_stock' => null,
        'maximum_stock' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $branchProduct = DB::table('branch_product')
        ->where('branch_id', $sale->branch_id)
        ->where('product_id', $product->id)
        ->lockForUpdate()
        ->first();

    if ($branchProduct === null) {
        throw ValidationException::withMessages([
            'inventory' => 'No se pudo obtener el inventario de la sucursal.',
        ]);
    }

    $previousStock = (float) $branchProduct->stock;
    $newStock = round($previousStock + $quantity, 4);

    DB::table('branch_product')
        ->where('id', $branchProduct->id)
        ->update([
            'stock' => $newStock,
            'updated_at' => now(),
        ]);

    return InventoryMovement::create([
        'company_id' => $sale->company_id,
        'branch_id' => $sale->branch_id,
        'product_id' => $product->id,
        'inventory_lot_id' => null,
        'user_id' => $userId,
        'type' => 'sale_void',
        'quantity' => round($quantity, 4),
        'previous_stock' => round($previousStock, 4),
        'new_stock' => $newStock,
        'reason' => 'Entrada por anulación de venta',
        'reference_type' => Sale::class,
        'reference_id' => $sale->id,
        'notes' => 'Anulación de venta '.$sale->sale_number,
    ]);
}

public function saleReturn(
        Sale $sale,
        SaleReturn $saleReturn,
        Product $product,
        float $quantity,
        int $userId,
    ): InventoryMovement {
        if ($quantity <= 0 || $sale->company_id !== $product->company_id) {
            throw ValidationException::withMessages([
                'items' => 'La línea de devolución no es válida.',
            ]);
        }

        DB::table('branch_product')->insertOrIgnore([
            'branch_id' => $sale->branch_id,
            'product_id' => $product->id,
            'stock' => 0,
            'minimum_stock' => null,
            'maximum_stock' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $branchProduct = DB::table('branch_product')
            ->where('branch_id', $sale->branch_id)
            ->where('product_id', $product->id)
            ->lockForUpdate()
            ->first();

        if ($branchProduct === null) {
            throw ValidationException::withMessages([
                'inventory' => 'No se pudo obtener el inventario de la sucursal.',
            ]);
        }

        $previousStock = (float) $branchProduct->stock;
        $newStock = round($previousStock + $quantity, 4);

        DB::table('branch_product')
            ->where('id', $branchProduct->id)
            ->update([
                'stock' => $newStock,
                'updated_at' => now(),
            ]);

        return InventoryMovement::create([
            'company_id' => $sale->company_id,
            'branch_id' => $sale->branch_id,
            'product_id' => $product->id,
            'inventory_lot_id' => null,
            'user_id' => $userId,
            'type' => 'sale_return',
            'quantity' => round($quantity, 4),
            'previous_stock' => round($previousStock, 4),
            'new_stock' => $newStock,
            'reason' => 'Entrada por devolución de venta',
            'reference_type' => SaleReturn::class,
            'reference_id' => $saleReturn->id,
            'notes' => 'Devolución '.$saleReturn->return_number.' de la venta '.$sale->sale_number,
        ]);
    }

    /**
     * Descuenta exactamente 1 unidad por canje de premio vinculado a producto.
     * Es el único punto de escritura de inventario autorizado para premios (F21).
     */
    public function postRewardRedemption(LoyaltyRewardRedemption $redemption, int $userId): InventoryMovement
    {
        $product = Product::query()->findOrFail($redemption->product_id);

        if ($redemption->company_id !== $product->company_id) {
            throw ValidationException::withMessages([
                'items' => 'El producto del premio no pertenece a la empresa del canje.',
            ]);
        }

        DB::table('branch_product')->insertOrIgnore([
            'branch_id' => $redemption->branch_id,
            'product_id' => $product->id,
            'stock' => 0,
            'minimum_stock' => null,
            'maximum_stock' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $branchProduct = DB::table('branch_product')
            ->where('branch_id', $redemption->branch_id)
            ->where('product_id', $product->id)
            ->lockForUpdate()
            ->first();

        $previousStock = $branchProduct === null ? 0.0 : (float) $branchProduct->stock;

        if ($previousStock < 1) {
            throw ValidationException::withMessages([
                'items' => "Stock insuficiente para {$product->name}. Disponible: ".number_format($previousStock, 4, '.', ''),
            ]);
        }

        $newStock = round($previousStock - 1, 4);

        DB::table('branch_product')->where('id', $branchProduct->id)->update([
            'stock' => $newStock,
            'updated_at' => now(),
        ]);

        return InventoryMovement::create([
            'company_id' => $redemption->company_id,
            'branch_id' => $redemption->branch_id,
            'product_id' => $product->id,
            'inventory_lot_id' => null,
            'user_id' => $userId,
            'type' => 'reward_redemption',
            'quantity' => 1,
            'previous_stock' => round($previousStock, 4),
            'new_stock' => $newStock,
            'reason' => 'Salida por canje de premio',
            'reference_type' => LoyaltyRewardRedemption::class,
            'reference_id' => $redemption->id,
            'notes' => 'Canje de premio '.$redemption->reward_name,
        ]);
    }

    /**
     * Registra la entrada únicamente en la sucursal receptora de la compra.
     */
    public function postPurchase(
        Purchase $purchase,
        PurchaseItem $purchaseItem,
        Product $product,
        PurchaseLineData $line,
    ): InventoryMovement {
        $quantity = $line->quantity;

        if ($quantity === null || $quantity <= 0) {
            throw ValidationException::withMessages([
                'items' => 'La cantidad debe ser mayor que cero.',
            ]);
        }

        if ($purchase->company_id !== $product->company_id) {
            throw ValidationException::withMessages([
                'items' => 'El producto no pertenece a la empresa de la compra.',
            ]);
        }

        DB::table('branch_product')->insertOrIgnore([
            'branch_id' => $purchase->branch_id,
            'product_id' => $product->id,
            'stock' => 0,
            'minimum_stock' => null,
            'maximum_stock' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $branchProduct = DB::table('branch_product')
            ->where('branch_id', $purchase->branch_id)
            ->where('product_id', $product->id)
            ->lockForUpdate()
            ->first();

        if ($branchProduct === null) {
            throw ValidationException::withMessages([
                'inventory' => 'No se pudo obtener el inventario de la sucursal receptora.',
            ]);
        }

        $previousStock = (float) $branchProduct->stock;
        $newStock = $previousStock + $quantity;

        DB::table('branch_product')
            ->where('id', $branchProduct->id)
            ->update([
                'stock' => $newStock,
                'updated_at' => now(),
            ]);

        $inventoryLot = null;

        if ($this->hasLotInformation($line)) {
            $inventoryLot = InventoryLot::create([
                'company_id' => $purchase->company_id,
                'branch_id' => $purchase->branch_id,
                'product_id' => $product->id,
                'purchase_item_id' => $purchaseItem->id,
                'lot_number' => $this->nullableValue($line->lot_number),
                'expires_at' => $this->nullableValue($line->expires_at),
                'initial_quantity' => $quantity,
                'current_quantity' => $quantity,
            ]);
        }

        return InventoryMovement::create([
            'company_id' => $purchase->company_id,
            'branch_id' => $purchase->branch_id,
            'product_id' => $product->id,
            'inventory_lot_id' => $inventoryLot?->id,
            'user_id' => $purchase->user_id,
            'type' => 'purchase',
            'quantity' => $quantity,
            'previous_stock' => $previousStock,
            'new_stock' => $newStock,
            'reason' => 'Entrada por compra',
            'reference_type' => Purchase::class,
            'reference_id' => $purchase->id,
            'notes' => 'Compra ' . $purchase->number,
        ]);
    }

    private function hasLotInformation(PurchaseLineData $line): bool
    {
        return $this->nullableValue($line->lot_number) !== null
            || $this->nullableValue($line->expires_at) !== null;
    }

    private function nullableValue(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
