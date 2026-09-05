<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Company;
use App\Models\InventoryLot;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferItem;
use App\Models\Product;
use App\Services\Inventory\InventoryPostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TransferController extends Controller
{
    private const QUANTITY_SCALE = 4;
    /**
     * Listado de transferencias.
     */
    public function index(Request $request)
    {
        $companyId = (int) session('active_company_id');
        $company = Company::query()->findOrFail($companyId);
        $assignedBranchIds = $request->user()->branches()
            ->where('branches.company_id', $companyId)
            ->pluck('branches.id');

        $transfers = InventoryTransfer::with([
            'fromBranch',
            'toBranch',
            'user',
            'items.product',
        ])
            ->where('company_id', $companyId)
            ->when(
                ! $request->user()->hasPermission('inventario.ver_otras_sucursales', $company),
                fn ($query) => $query->where(function ($branches) use ($assignedBranchIds) {
                    $branches->whereIn('from_branch_id', $assignedBranchIds)
                        ->orWhereIn('to_branch_id', $assignedBranchIds);
                }),
            )
            ->latest()
            ->paginate(20);

        return view('transferencias.index', compact('transfers'));
    }

    /**
     * Formulario de nueva transferencia.
     */
    public function create(Request $request)
    {
        $companyId = (int) session('active_company_id');
        $fromBranchId = (int) session('active_branch_id');

        $company = Company::query()->findOrFail($companyId);
        $branchId = (int) session('active_branch_id');

        $branches = Branch::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('id', '!=', $fromBranchId)
            ->when(
                ! $request->user()->hasPermission('inventario.ver_otras_sucursales', $company),
                fn ($query) => $query->whereIn('id', $request->user()->branches()->select('branches.id')),
            )
            ->orderBy('name')->get();

        return view('transferencias.create', compact('branches', 'fromBranchId'));
    }

    /**
     * Búsqueda de productos.
     */
    public function searchProducts(Request $request)
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'max:100'],
        ]);
        $companyId = (int) session('active_company_id');
        $branchId = (int) session('active_branch_id');
        $search = trim($data['q']);

        if ($search === '') {
            return response()->json([]);
        }

        $likeOperator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $products = Product::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('track_inventory', true)
            ->with(['unit:id,allows_decimals'])
            ->with(['branches' => fn ($query) => $query->where('branches.id', $branchId)])
            ->where(function ($query) use ($search, $likeOperator) {
                $query->where('name', $likeOperator, "%{$search}%")
                    ->orWhere('internal_code', $likeOperator, "%{$search}%")
                    ->orWhere('barcode', $likeOperator, "%{$search}%")
                    ->orWhereHas('barcodes', fn ($barcodes) => $barcodes
                        ->where('is_active', true)
                        ->where('barcode', $likeOperator, "%{$search}%"));
            })
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'internal_code', 'barcode', 'unit_id']);

        return response()->json($products->map(function (Product $product) {
            $branch = $product->branches->first();

            return [
                'id' => $product->id,
                'name' => $product->name,
                'internal_code' => $product->internal_code,
                'barcode' => $product->barcode,
                'allows_decimals' => (bool) $product->unit?->allows_decimals,
                'branch_stock' => $branch?->pivot?->stock,
            ];
        }));
    }

    /**
     * Guardar nueva transferencia (varios productos).
     */
    public function store(Request $request, InventoryPostingService $inventory)
    {
        $companyId = (int) session('active_company_id');
        $fromBranchId = (int) session('active_branch_id');
        $company = Company::query()->findOrFail($companyId);

        $data = $request->validate([
            'to_branch_id' => ['required', 'integer'],
            'products' => ['required', 'array'],
            'products.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'products.*.quantity' => ['required', 'decimal:0,4', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $fromBranch = Branch::query()->where('company_id', $companyId)->findOrFail($fromBranchId);
        $toBranch = Branch::query()->where('company_id', $companyId)->where('is_active', true)->findOrFail($data['to_branch_id']);

        if ($fromBranch->is($toBranch)) {
            throw ValidationException::withMessages([
                'to_branch_id' => 'La sucursal destino debe ser diferente a la origen.',
            ]);
        }

        // Verificar stock suficiente para todos los productos
        $productIds = array_column($data['products'], 'product_id');
        $products = Product::whereIn('id', $productIds)
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('track_inventory', true)
            ->get();

        // Validar que no haya productos duplicados
        $productCount = count($data['products']);
        $uniqueProductIds = array_unique(array_column($data['products'], 'product_id'));
        if (count($uniqueProductIds) !== $productCount) {
            throw ValidationException::withMessages([
                'products' => 'No se permiten productos duplicados en un mismo traslado.',
            ]);
        }

        // Validar stock suficiente desde branch_product
        foreach ($products as $product) {
            $requestedQuantity = '0';
            foreach ($data['products'] as $pd) {
                if ($pd['product_id'] == $product->id) {
                    $requestedQuantity = $pd['quantity'];
                    break;
                }
            }
            $currentStock = DB::table('branch_product')
                ->where('branch_id', $fromBranchId)
                ->where('product_id', $product->id)
                ->value('stock') ?? '0';
            $currentStock = bcadd((string) $currentStock, '0', self::QUANTITY_SCALE);
            if (bccomp($currentStock, $requestedQuantity, self::QUANTITY_SCALE) < 0) {
                throw ValidationException::withMessages([
                    'products' => "No hay suficiente inventario para {$product->name} en la sucursal origen.",
                ]);
            }
        }

        // Crear la transferencia y sus items dentro de una transacción
        return DB::transaction(function () use ($companyId, $fromBranchId, $fromBranch, $toBranch, $data, $company, $products, $request, $inventory) {
            // Crear cabecera de transferencia
            $transfer = InventoryTransfer::create([
                'company_id' => $companyId,
                'from_branch_id' => $fromBranchId,
                'to_branch_id' => $data['to_branch_id'],
                'user_id' => (int) $request->user()->id,
                'transfer_number' => $inventory->nextTransferNumber(),
                'status' => 'pending',
                'notes' => $data['notes'] ?? null,
                'is_multiproduct' => count($data['products']) > 1,
            ]);

            // Crear items y validar stock por producto
            foreach ($data['products'] as $pd) {
                $product = Product::query()->where('id', $pd['product_id'])->where('company_id', $companyId)->where('is_active', true)->firstOrFail();

                if (! $product->track_inventory) {
                    throw ValidationException::withMessages([
                        'products' => 'El producto '.$product->name.' no controla inventario.',
                    ]);
                }

                if (! $product->unit?->allows_decimals && str_contains((string) $pd['quantity'], '.') && rtrim(substr((string) $pd['quantity'], strpos((string) $pd['quantity'], '.') + 1), '0') !== '') {
                    throw ValidationException::withMessages([
                        'products' => 'El producto '.$product->name.' solo admite cantidades enteras.',
                    ]);
                }

                $hasLot = InventoryLot::query()
                    ->where('company_id', $companyId)
                    ->where('product_id', $product->id)
                    ->exists();
                if ($hasLot) {
                    throw ValidationException::withMessages([
                        'products' => 'El producto '.$product->name.' está gestionado por lote.',
                    ]);
                }

                $currentStock = DB::table('branch_product')
                    ->where('branch_id', $fromBranchId)
                    ->where('product_id', $product->id)
                    ->value('stock') ?? '0';
                $currentStock = bcadd((string) $currentStock, '0', self::QUANTITY_SCALE);

                // Verificar stock por producto en origen
                if (bccomp($currentStock, $pd['quantity'], self::QUANTITY_SCALE) < 0) {
                    throw ValidationException::withMessages([
                        'products' => "No hay suficiente inventario para {$product->name} en la sucursal origen.",
                    ]);
                }

                $fromNewStock = bcsub($currentStock, $pd['quantity'], self::QUANTITY_SCALE);

                InventoryTransferItem::create([
                    'inventory_transfer_id' => $transfer->id,
                    'product_id' => $pd['product_id'],
                    'quantity' => (string) $pd['quantity'],
                    'from_previous_stock' => $currentStock,
                    'from_new_stock' => $fromNewStock,
                    'to_previous_stock' => '0',
                    'to_new_stock' => '0',
                ]);
            }

            return redirect()
                ->route('transferencias.index')
                ->with(
                    'success',
                    'Transferencia multiproducto realizada correctamente.'
                );
        });
    }

    /**
     * Preparar un traslado pending.
     */
    public function prepare(Request $request, InventoryTransfer $transfer, InventoryPostingService $inventory)
    {
        $inventory->prepareTransfer($transfer, (int) $request->user()->id, $request->input('notes'));

        return redirect()
            ->route('transferencias.index')
            ->with('success', 'Transferencia preparada correctamente.');
    }

    /**
     * Despachar un traslado prepared.
     */
    public function dispatch(Request $request, InventoryTransfer $transfer, InventoryPostingService $inventory)
    {
        $inventory->dispatchTransfer($transfer, (int) $request->user()->id, $request->input('notes'));

        return redirect()
            ->route('transferencias.index')
            ->with('success', 'Transferencia despachada correctamente.');
    }

    /**
     * Iniciar revisión de un traslado in_transit.
     */
    public function review(Request $request, InventoryTransfer $transfer, InventoryPostingService $inventory)
    {
        if (! $transfer->isInTransit()) {
            throw ValidationException::withMessages([
                'transfer' => 'Solo se puede iniciar revisión de un traslado con estatus in_transit.',
            ]);
        }

        $transfer->status = InventoryTransfer::STATUS_IN_REVIEW;
        $transfer->save();

        return redirect()
            ->route('transferencias.index')
            ->with('success', 'Revisión de traslación iniciada.');
    }

    /**
     * Recibir un traslado in_review.
     */
    public function receive(Request $request, InventoryTransfer $transfer, InventoryPostingService $inventory)
    {
        $inventory->receiveTransfer($transfer, (int) $request->user()->id, $request->input('notes'), $request->input('received_quantity'));

        return redirect()
            ->route('transferencias.index')
            ->with('success', 'Transferencia recibida correctamente.');
    }

    /**
     * Cancelar un traslado.
     */
    public function cancel(Request $request, InventoryTransfer $transfer, InventoryPostingService $inventory)
    {
        if (! in_array($transfer->status, [InventoryTransfer::STATUS_PENDING, InventoryTransfer::STATUS_PREPARED])) {
            throw ValidationException::withMessages([
                'transfer' => 'No se puede cancelar un traslado en su estado actual.',
            ]);
        }

        $transfer->status = InventoryTransfer::STATUS_CANCELLED;
        $transfer->save();

        return redirect()
            ->route('transferencias.index')
            ->with('success', 'Transferencia cancelada correctamente.');
    }

    /**
     * Ver detalle/trazabilidad de un traslado.
     */
    public function show(Request $request, InventoryTransfer $transfer)
    {
        return view('transferencias.show', compact('transfer'));
    }
}