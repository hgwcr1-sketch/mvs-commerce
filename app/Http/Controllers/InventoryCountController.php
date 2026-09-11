<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\InventoryCount;
use App\Models\InventoryCountItem;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductBarcode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class InventoryCountController extends Controller
{
    public function index(Request $request)
    {
        $companyId = (int) session('active_company_id');
        $branchId = (int) session('active_branch_id');

        $query = InventoryCount::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->with(['startedBy:id,name', 'confirmedBy:id,name', 'items.product:id,name,internal_code,barcode'])
            ->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $counts = $query->paginate(15)->withQueryString();

        return view('inventory-counts.index', compact('counts'));
    }

    public function create()
    {
        $companyId = (int) session('active_company_id');
        $branchId = (int) session('active_branch_id');

        if (! $branchId) {
            return redirect()->route('inventory-counts.index')
                ->with('warning', 'Seleccione una sucursal para iniciar una toma de inventario.');
        }

        $branch = Branch::where('company_id', $companyId)->where('id', $branchId)->first();

        if (! $branch) {
            return redirect()->route('inventory-counts.index')
                ->with('warning', 'Seleccione una sucursal para iniciar una toma de inventario.');
        }

        return view('inventory-counts.create', compact('branch'));
    }

    public function store(Request $request)
    {
        $companyId = (int) session('active_company_id');
        $branchId = (int) session('active_branch_id');

        if (! $branchId || ! Branch::where('company_id', $companyId)->where('id', $branchId)->exists()) {
            return redirect()->route('inventory-counts.index')
                ->with('warning', 'Seleccione una sucursal para iniciar una toma de inventario.');
        }

        $data = $request->validate([
            'reference' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $count = DB::transaction(function () use ($companyId, $branchId, $data) {
            $count = InventoryCount::create([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => InventoryCount::STATUS_DRAFT,
                'started_by' => auth()->id(),
                'started_at' => now(),
            ]);

            return $count;
        });

        return redirect()
            ->route('inventory-counts.edit', $count)
            ->with('success', 'Toma de inventario creada. Estado: Borrador.');
    }

    public function edit(InventoryCount $inventoryCount)
    {
        abort_unless((int) $inventoryCount->company_id === (int) session('active_company_id'), 404);
        abort_unless((int) $inventoryCount->branch_id === (int) session('active_branch_id'), 404);
        abort_unless($inventoryCount->canBeEdited(), 422);

        $companyId = (int) session('active_company_id');
        $branchId = (int) session('active_branch_id');

        $items = $inventoryCount->items()
            ->with(['product:id,name,internal_code,barcode,track_inventory', 'product.unit:id,abbreviation', 'product.barcodes' => fn ($q) => $q->where('is_active', true)->orderByDesc('is_primary')])
            ->orderBy('id')
            ->get();

        return view('inventory-counts.edit', compact('inventoryCount', 'items'));
    }

    public function update(Request $request, InventoryCount $inventoryCount)
    {
        abort_unless((int) $inventoryCount->company_id === (int) session('active_company_id'), 404);
        abort_unless((int) $inventoryCount->branch_id === (int) session('active_branch_id'), 404);
        abort_unless($inventoryCount->canBeEdited(), 422);

        $data = $request->validate([
            'reference' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $inventoryCount->update([
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('success', 'Toma de inventario actualizada.');
    }

    public function addItem(Request $request, InventoryCount $inventoryCount)
    {
        abort_unless((int) $inventoryCount->company_id === (int) session('active_company_id'), 404);
        abort_unless((int) $inventoryCount->branch_id === (int) session('active_branch_id'), 404);
        abort_unless($inventoryCount->canBeEdited(), 422);

        $companyId = (int) session('active_company_id');
        $branchId = (int) session('active_branch_id');

        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'product_id' => ['nullable', 'integer'],
        ]);

        $search = trim($data['search'] ?? '');
        $productId = $data['product_id'] ?? null;

        $product = null;

        // 1) Direct lookup by product ID (from autocomplete selection)
        if ($productId) {
            $product = Product::where('id', $productId)
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->where('track_inventory', true)
                ->whereHas('branches', fn ($q) => $q->where('branches.id', $branchId))
                ->first();
        }

        // 2) Text search (scanner / manual typing)
        if (! $product && $search !== '') {
            $likeOperator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $product = Product::where('company_id', $companyId)
                ->where('is_active', true)
                ->where('track_inventory', true)
                ->whereHas('branches', fn ($q) => $q->where('branches.id', $branchId))
                ->where(function ($q) use ($search, $likeOperator) {
                    $q->where('barcode', $likeOperator, "%{$search}%")
                        ->orWhere('internal_code', $likeOperator, "%{$search}%")
                        ->orWhere('name', $likeOperator, "%{$search}%")
                        ->orWhereHas('barcodes', fn ($q) => $q->where('barcode', $likeOperator, "%{$search}%")->where('is_active', true));
                })
                ->first();
        }

        if (! $product) {
            throw ValidationException::withMessages([
                'search' => 'Producto no encontrado.',
            ]);
        }

        $existing = $inventoryCount->items()->where('product_id', $product->id)->first();
        if ($existing) {
            return response()->json([
                'exists' => true,
                'item_id' => $existing->id,
                'message' => 'Producto ya agregado.',
            ]);
        }

        $theoretical = DB::table('branch_product')
            ->where('branch_id', $branchId)
            ->where('product_id', $product->id)
            ->value('stock') ?? 0;

        $item = $inventoryCount->items()->create([
            'product_id' => $product->id,
            'theoretical_quantity' => $theoretical,
            'counted_quantity' => null,
            'recount_quantity' => null,
            'final_quantity' => null,
            'difference' => 0,
        ]);

        return response()->json([
            'exists' => false,
            'item' => [
                'id' => $item->id,
                'product_id' => $product->id,
                'name' => $product->name,
                'internal_code' => $product->internal_code,
                'barcode' => $product->barcode,
                'unit' => $product->unit?->abbreviation ?? '',
                'theoretical_quantity' => number_format((float) $theoretical, 4, '.', ''),
                'counted_quantity' => null,
            ],
        ]);
    }

    public function updateCountedQuantity(Request $request, InventoryCount $inventoryCount, InventoryCountItem $item)
    {
        abort_unless((int) $inventoryCount->company_id === (int) session('active_company_id'), 404);
        abort_unless((int) $inventoryCount->branch_id === (int) session('active_branch_id'), 404);
        abort_unless($inventoryCount->canBeEdited(), 422);
        abort_unless($item->inventory_count_id === $inventoryCount->id, 404);

        $data = $request->validate([
            'counted_quantity' => ['required', 'integer', 'min:0'],
        ]);

        $qty = (string) $data['counted_quantity'];

        $item->update([
            'counted_quantity' => $qty,
            'recount_quantity' => null,
            'final_quantity' => $qty,
            'difference' => bcsub($qty, (string) $item->theoretical_quantity, 4),
        ]);

        if ($inventoryCount->isDraft()) {
            $inventoryCount->update([
                'status' => InventoryCount::STATUS_COUNTING,
                'counted_by' => auth()->id(),
                'counted_at' => $inventoryCount->counted_at ?? now(),
            ]);
        }

        $notes = $request->input('notes');
        if ($notes !== null) {
            $item->update(['notes' => $notes !== '' ? $notes : null]);
        }

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'item_id' => $item->id,
                'difference' => number_format((float) $item->difference, 4, '.', ''),
                'final_quantity' => number_format((float) $item->final_quantity, 4, '.', ''),
            ]);
        }

        return back()->with('success', 'Cantidad guardada.');
    }

    public function recount(Request $request, InventoryCount $inventoryCount, InventoryCountItem $item)
    {
        abort_unless((int) $inventoryCount->company_id === (int) session('active_company_id'), 404);
        abort_unless((int) $inventoryCount->branch_id === (int) session('active_branch_id'), 404);
        abort_unless($inventoryCount->canBeEdited(), 422);
        abort_unless($item->inventory_count_id === $inventoryCount->id, 404);

        $data = $request->validate([
            'recount_quantity' => ['required', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,4})?$/'],
        ]);

        $item->update([
            'recount_quantity' => $data['recount_quantity'],
            'final_quantity' => $data['recount_quantity'],
            'difference' => bcsub((string) $data['recount_quantity'], (string) $item->theoretical_quantity, 4),
        ]);

        return response()->json([
            'item_id' => $item->id,
            'difference' => number_format((float) $item->difference, 4, '.', ''),
            'final_quantity' => number_format((float) $item->final_quantity, 4, '.', ''),
        ]);
    }

    public function updateNotes(Request $request, InventoryCount $inventoryCount, InventoryCountItem $item)
    {
        abort_unless((int) $inventoryCount->company_id === (int) session('active_company_id'), 404);
        abort_unless((int) $inventoryCount->branch_id === (int) session('active_branch_id'), 404);
        abort_unless($item->inventory_count_id === $inventoryCount->id, 404);

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $item->update(['notes' => $data['notes'] ?? null]);

        return response()->json(['success' => true]);
    }

    public function removeItem(InventoryCount $inventoryCount, InventoryCountItem $item)
    {
        abort_unless((int) $inventoryCount->company_id === (int) session('active_company_id'), 404);
        abort_unless((int) $inventoryCount->branch_id === (int) session('active_branch_id'), 404);
        abort_unless($item->inventory_count_id === $inventoryCount->id, 404);
        abort_unless($inventoryCount->canBeEdited(), 422);

        $item->delete();

        return response()->json(['success' => true]);
    }

    public function startReview(InventoryCount $inventoryCount)
    {
        abort_unless((int) $inventoryCount->company_id === (int) session('active_company_id'), 404);
        abort_unless((int) $inventoryCount->branch_id === (int) session('active_branch_id'), 404);
        abort_unless($inventoryCount->canTransitionTo(InventoryCount::STATUS_REVIEW), 422);

        $inventoryCount->update([
            'status' => InventoryCount::STATUS_REVIEW,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        return back()->with('success', 'Toma enviada a revisión.');
    }

    public function backToCounting(InventoryCount $inventoryCount)
    {
        abort_unless((int) $inventoryCount->company_id === (int) session('active_company_id'), 404);
        abort_unless((int) $inventoryCount->branch_id === (int) session('active_branch_id'), 404);
        abort_unless($inventoryCount->canTransitionTo(InventoryCount::STATUS_COUNTING), 422);

        $inventoryCount->update([
            'status' => InventoryCount::STATUS_COUNTING,
        ]);

        return back()->with('success', 'Toma devuelta a conteo.');
    }

    public function confirm(InventoryCount $inventoryCount)
    {
        abort_unless((int) $inventoryCount->company_id === (int) session('active_company_id'), 404);
        abort_unless((int) $inventoryCount->branch_id === (int) session('active_branch_id'), 404);
        abort_unless($inventoryCount->canTransitionTo(InventoryCount::STATUS_CONFIRMED), 422);

        Gate::authorize('inventario.conteo.confirmar');

        DB::transaction(function () use ($inventoryCount) {
            $inventoryCount = InventoryCount::whereKey($inventoryCount->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($inventoryCount->canTransitionTo(InventoryCount::STATUS_CONFIRMED), 422);

            $items = $inventoryCount->items()
                ->whereNotNull('final_quantity')
                ->with('product')
                ->get();

            foreach ($items as $item) {
                $product = $item->product;
                $branchId = $inventoryCount->branch_id;

                $inventory = DB::table('branch_product')
                    ->where('branch_id', $branchId)
                    ->where('product_id', $product->id)
                    ->lockForUpdate()
                    ->first();

                $previousStock = bcadd($inventory ? (string) $inventory->stock : '0', '0', 4);
                $newStock = bcadd($item->getEffectiveQuantity(), '0', 4);
                $quantity = bcsub($newStock, $previousStock, 4);

                if (bccomp($quantity, '0', 4) === 0) {
                    continue;
                }

                if ($inventory) {
                    DB::table('branch_product')
                        ->where('id', $inventory->id)
                        ->update(['stock' => $newStock, 'updated_at' => now()]);
                } else {
                    DB::table('branch_product')->insert([
                        'branch_id' => $branchId,
                        'product_id' => $product->id,
                        'stock' => $newStock,
                        'minimum_stock' => 0,
                        'maximum_stock' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                InventoryMovement::create([
                    'company_id' => $inventoryCount->company_id,
                    'branch_id' => $branchId,
                    'product_id' => $product->id,
                    'user_id' => auth()->id(),
                    'type' => 'adjustment',
                    'quantity' => $quantity,
                    'previous_stock' => $previousStock,
                    'new_stock' => $newStock,
                    'reason' => 'Ajuste por toma de inventario',
                    'reference_type' => InventoryCount::class,
                    'reference_id' => $inventoryCount->id,
                    'notes' => 'Toma: '.($inventoryCount->reference ?? $inventoryCount->id).". Ajuste aplicado: {$quantity}. Diferencia original: {$item->difference}",
                ]);
            }

            $inventoryCount->update([
                'status' => InventoryCount::STATUS_CONFIRMED,
                'confirmed_by' => auth()->id(),
                'confirmed_at' => now(),
            ]);
        });

        return redirect()
            ->route('inventory-counts.show', $inventoryCount)
            ->with('success', 'Toma de inventario confirmada. Stock actualizado.');
    }

    public function cancel(InventoryCount $inventoryCount)
    {
        abort_unless((int) $inventoryCount->company_id === (int) session('active_company_id'), 404);
        abort_unless((int) $inventoryCount->branch_id === (int) session('active_branch_id'), 404);
        abort_unless($inventoryCount->canTransitionTo(InventoryCount::STATUS_CANCELLED), 422);

        $inventoryCount->update([
            'status' => InventoryCount::STATUS_CANCELLED,
            'cancelled_by' => auth()->id(),
            'cancelled_at' => now(),
        ]);

        return back()->with('success', 'Toma de inventario cancelada.');
    }

    public function show(InventoryCount $inventoryCount)
    {
        abort_unless((int) $inventoryCount->company_id === (int) session('active_company_id'), 404);
        abort_unless((int) $inventoryCount->branch_id === (int) session('active_branch_id'), 404);

        $items = $inventoryCount->items()
            ->with(['product:id,name,internal_code,barcode', 'product.unit:id,abbreviation'])
            ->orderBy('id')
            ->get();

        return view('inventory-counts.show', compact('inventoryCount', 'items'));
    }

    public function destroy(InventoryCount $inventoryCount)
    {
        abort_unless((int) $inventoryCount->company_id === (int) session('active_company_id'), 404);
        abort_unless((int) $inventoryCount->branch_id === (int) session('active_branch_id'), 404);
        abort_unless($inventoryCount->isDraft(), 422);

        $inventoryCount->delete();

        return redirect()
            ->route('inventory-counts.index')
            ->with('success', 'Toma de inventario eliminada.');
    }
}
