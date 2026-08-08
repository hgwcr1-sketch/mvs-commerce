<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransferController extends Controller
{
    /**
     * Listado de transferencias.
     */
    public function index()
    {
        $companyId = session('active_company_id');

        $transfers = InventoryTransfer::with([
                'fromBranch',
                'toBranch',
                'user',
                'items.product',
            ])
            ->where('company_id', $companyId)
            ->latest()
            ->paginate(20);

        return view('transferencias.index', compact('transfers'));
    }

    /**
     * Formulario de nueva transferencia.
     */
    public function create()
    {
        $companyId = session('active_company_id');
        $branchId = session('active_branch_id');

        $branches = Branch::where('company_id', $companyId)
            ->where('is_active', true)
            ->where('id', '!=', $branchId)
            ->orderBy('name')
            ->get();

        return view('transferencias.create', compact('branches'));
    }

    /**
     * Guardar transferencia.
     */
    public function store(Request $request)
    {
        $companyId = session('active_company_id');
        $fromBranchId = session('active_branch_id');

        $data = $request->validate([
            'to_branch_id' => [
                'required',
                'integer',
                'exists:branches,id',
            ],

            'product_id' => [
                'required',
                'integer',
                'exists:products,id',
            ],

            'quantity' => [
                'required',
                'numeric',
                'gt:0',
            ],

            'notes' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ]);

        /*
         * Validar que la sucursal destino pertenezca
         * a la empresa activa.
         */
        $toBranch = Branch::where('company_id', $companyId)
            ->where('is_active', true)
            ->findOrFail($data['to_branch_id']);

        if ((int) $toBranch->id === (int) $fromBranchId) {
            return back()
                ->withInput()
                ->withErrors([
                    'to_branch_id' =>
                        'La sucursal destino debe ser diferente a la sucursal origen.',
                ]);
        }

        $product = Product::where('company_id', $companyId)
    ->where('is_active', true)
    ->findOrFail($data['product_id']);
    
        $quantity = (float) $data['quantity'];

        DB::transaction(function () use (
            $companyId,
            $fromBranchId,
            $toBranch,
            $product,
            $quantity,
            $data
        ) {

            /*
             * STOCK ORIGEN
             */
            $fromInventory = DB::table('branch_product')
                ->where('branch_id', $fromBranchId)
                ->where('product_id', $product->id)
                ->first();

            $fromPreviousStock = $fromInventory
                ? (float) $fromInventory->stock
                : 0;

            if ($fromPreviousStock < $quantity) {
    throw \Illuminate\Validation\ValidationException::withMessages([
        'quantity' => 'No hay suficiente inventario en la sucursal origen. Disponible: '
            . number_format($fromPreviousStock, 2),
    ]);
}

            $fromNewStock = $fromPreviousStock - $quantity;

            /*
             * STOCK DESTINO
             */
            $toInventory = DB::table('branch_product')
                ->where('branch_id', $toBranch->id)
                ->where('product_id', $product->id)
                ->first();

            $toPreviousStock = $toInventory
                ? (float) $toInventory->stock
                : 0;

            $toNewStock = $toPreviousStock + $quantity;

            /*
             * Crear transferencia.
             */
            $transfer = InventoryTransfer::create([
                'company_id' => $companyId,
                'from_branch_id' => $fromBranchId,
                'to_branch_id' => $toBranch->id,
                'user_id' => auth()->id(),

                'transfer_number' =>
                    'TR-' . now()->format('YmdHis') . '-' . random_int(100, 999),

                'status' => 'completed',
                'notes' => $data['notes'] ?? null,
                'transferred_at' => now(),
            ]);

            /*
             * Descontar origen.
             */
            DB::table('branch_product')
                ->where('branch_id', $fromBranchId)
                ->where('product_id', $product->id)
                ->update([
                    'stock' => $fromNewStock,
                    'updated_at' => now(),
                ]);

            /*
             * Aumentar destino.
             */
            if ($toInventory) {

                DB::table('branch_product')
                    ->where('branch_id', $toBranch->id)
                    ->where('product_id', $product->id)
                    ->update([
                        'stock' => $toNewStock,
                        'updated_at' => now(),
                    ]);

            } else {

                DB::table('branch_product')->insert([
                    'branch_id' => $toBranch->id,
                    'product_id' => $product->id,
                    'stock' => $toNewStock,
                    'minimum_stock' => 0,
                    'maximum_stock' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            /*
             * Detalle de transferencia.
             */
            InventoryTransferItem::create([
                'inventory_transfer_id' => $transfer->id,
                'product_id' => $product->id,
                'quantity' => $quantity,

                'from_previous_stock' => $fromPreviousStock,
                'from_new_stock' => $fromNewStock,

                'to_previous_stock' => $toPreviousStock,
                'to_new_stock' => $toNewStock,
            ]);

            /*
             * KARDEX - SALIDA ORIGEN
             */
            InventoryMovement::create([
                'company_id' => $companyId,
                'branch_id' => $fromBranchId,
                'product_id' => $product->id,
                'user_id' => auth()->id(),

                'type' => 'transfer_out',
                'quantity' => $quantity,

                'previous_stock' => $fromPreviousStock,
                'new_stock' => $fromNewStock,

                'reason' => 'Transferencia a ' . $toBranch->name,

                'reference_type' => 'inventory_transfer',
                'reference_id' => $transfer->id,

                'notes' => $data['notes'] ?? null,
            ]);

            /*
             * KARDEX - ENTRADA DESTINO
             */
            InventoryMovement::create([
                'company_id' => $companyId,
                'branch_id' => $toBranch->id,
                'product_id' => $product->id,
                'user_id' => auth()->id(),

                'type' => 'transfer_in',
                'quantity' => $quantity,

                'previous_stock' => $toPreviousStock,
                'new_stock' => $toNewStock,

                'reason' => 'Transferencia recibida',

                'reference_type' => 'inventory_transfer',
                'reference_id' => $transfer->id,

                'notes' => $data['notes'] ?? null,
            ]);
        });

        return redirect()
            ->route('transferencias.index')
            ->with(
                'success',
                'Transferencia realizada correctamente.'
            );
    }
}