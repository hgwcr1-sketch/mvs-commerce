<?php

namespace App\Http\Controllers;

use App\Models\InventoryMovement;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryAdjustmentController extends Controller
{
    /**
     * Mostrar formulario para nuevo ajuste.
     */
    public function create()
{
    $companyId = session('active_company_id');

    $products = Product::where('company_id', $companyId)
        ->where('is_active', true)
        ->orderBy('name')
        ->get();

    return view('ajustes-inventario.create', compact('products'));
}

    /**
     * Guardar ajuste de inventario.
     */
    public function store(Request $request)
    {
        $companyId = session('active_company_id');
        $branchId = session('active_branch_id');
  
  $product = Product::where('company_id', $companyId)
    ->where('id', $request->product_id)
    ->where('is_active', true)
    ->firstOrFail();

        $data = $request->validate([
            'product_id' => [
    'required',
    'integer',
],

            'adjustment_type' => [
                'required',
                'in:entry,exit',
            ],

            'quantity' => [
                'required',
                'numeric',
                'gt:0',
            ],

            'reason' => [
                'required',
                'string',
                'max:255',
            ],

            'notes' => [
                'nullable',
                'string',
            ],
        ]);

        DB::transaction(function () use (
            $data,
            $companyId,
            $branchId
        ) {

            $inventory = DB::table('branch_product')
                ->where('branch_id', $branchId)
                ->where('product_id', $data['product_id'])
                ->first();

            $previousStock = $inventory
                ? (float) $inventory->stock
                : 0;

            $quantity = (float) $data['quantity'];

            if ($data['adjustment_type'] === 'entry') {

                $newStock = $previousStock + $quantity;

            } else {

                $newStock = $previousStock - $quantity;

                if ($newStock < 0) {
    throw \Illuminate\Validation\ValidationException::withMessages([
        'quantity' => "Stock insuficiente. Existencia actual: {$previousStock}. No puede realizar una salida de {$quantity}.",
    ]);
}
            }

            if ($inventory) {

                DB::table('branch_product')
                    ->where('branch_id', $branchId)
                    ->where('product_id', $data['product_id'])
                    ->update([
                        'stock' => $newStock,
                        'updated_at' => now(),
                    ]);

            } else {

                DB::table('branch_product')->insert([
                    'branch_id' => $branchId,
                    'product_id' => $data['product_id'],
                    'stock' => $newStock,
                    'minimum_stock' => 0,
                    'maximum_stock' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            InventoryMovement::create([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'product_id' => $data['product_id'],
                'user_id' => auth()->id(),
                'type' => $data['adjustment_type'],
                'quantity' => $quantity,
                'previous_stock' => $previousStock,
                'new_stock' => $newStock,
                'reason' => $data['reason'],
                'notes' => $data['notes'] ?? null,
            ]);
        });

        return redirect()
            ->route('inventario.index')
            ->with('success', 'Ajuste de inventario realizado correctamente.');
    }
}