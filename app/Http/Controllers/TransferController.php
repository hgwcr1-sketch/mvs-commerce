<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Company;
use App\Models\InventoryLot;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Inventory\InventoryPostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TransferController extends Controller
{
    private const QUANTITY_SCALE = 4;

    private const STATUS_LABELS = [
        'pending' => 'Pendiente',
        'prepared' => 'Preparado',
        'in_transit' => 'En tránsito',
        'in_review' => 'En revisión',
        'received' => 'Recibido',
        'received_with_differences' => 'Recibido con diferencias',
        'cancelled' => 'Cancelado',
        'completed' => 'Completado',
    ];

    /**
     * Listado de transferencias.
     */
    public function index(Request $request)
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in(InventoryTransfer::STATUSES)]]);
        $statusLabels = self::STATUS_LABELS;
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
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('transferencias.index', compact('transfers', 'statusLabels'));
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

        // Allow prefill to override the source branch (e.g. from Centro de Control)
        $prefill = null;
        if ($prefillJson = $request->query('prefill')) {
            $prefill = is_string($prefillJson) ? json_decode($prefillJson, true) : $prefillJson;
            if (is_array($prefill) && ! empty($prefill['from_branch_id'])) {
                $fromBranchId = (int) $prefill['from_branch_id'];
            }
        }

        $branches = Branch::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('id', '!=', $fromBranchId)
            ->when(
                ! $request->user()->hasPermission('inventario.ver_otras_sucursales', $company),
                fn ($query) => $query->whereIn('id', $request->user()->branches()->select('branches.id')),
            )
            ->orderBy('name')->get();

        $fromBranch = Branch::query()->where('company_id', $companyId)->find($fromBranchId);
        // Rehydrate only company products; names/stock never come from submitted display fields.
        $oldLines = collect($request->old('products', []))->filter(fn ($line) => is_array($line));
        $oldProducts = Product::query()->where('company_id', $companyId)
            ->whereIn('id', $oldLines->pluck('product_id')->filter(fn ($id) => is_scalar($id)))
            ->with(['unit', 'branches' => fn ($query) => $query->where('branches.id', $fromBranchId)])
            ->get()->keyBy('id');
        $initialProducts = $oldLines->map(function ($line) use ($oldProducts) {
            $product = $oldProducts->get(is_scalar($line['product_id'] ?? null) ? $line['product_id'] : null);
            if (! $product) {
                return null;
            }

            return [
                'id' => $product->id, 'name' => $product->name,
                'internal_code' => $product->internal_code,
                'allows_decimals' => (bool) $product->unit?->allows_decimals,
                'branch_stock' => $product->branches->first()?->pivot?->stock,
                'quantity' => is_scalar($line['quantity'] ?? null) ? (string) $line['quantity'] : '',
            ];
        })->filter()->unique('id')->values();

        // Prefill from Control Center
        if ($prefill && ! empty($prefill['products'])) {
            $prefillProductIds = array_column($prefill['products'], 'product_id');
            $prefillProducts = Product::query()->where('company_id', $companyId)
                ->whereIn('id', $prefillProductIds)
                ->with(['unit', 'branches' => fn ($q) => $q->where('branches.id', $fromBranchId)])
                ->get()->keyBy('id');

            foreach ($prefill['products'] as $pf) {
                $pid = $pf['product_id'] ?? null;
                $product = $prefillProducts->get($pid);
                if (! $product) {
                    continue;
                }
                $initialProducts->push([
                    'id' => $product->id, 'name' => $product->name,
                    'internal_code' => $product->internal_code,
                    'allows_decimals' => (bool) $product->unit?->allows_decimals,
                    'branch_stock' => $product->branches->first()?->pivot?->stock,
                    'quantity' => is_scalar($pf['quantity'] ?? null) ? (string) $pf['quantity'] : '',
                ]);
            }
            $initialProducts = $initialProducts->unique('id')->values();
        }

        $toBranchId = $prefill['to_branch_id'] ?? null;

        return view('transferencias.create', compact('branches', 'fromBranch', 'initialProducts', 'toBranchId'));
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
        $fromBranchId = (int) $request->input('from_branch_id', session('active_branch_id'));
        $company = Company::query()->findOrFail($companyId);

        $data = $request->validate([
            'from_branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'to_branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'products' => ['required', 'array'],
            'products.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'products.*.quantity' => ['required', 'decimal:0,4', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $fromBranchId = (int) $data['from_branch_id'];
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
        return DB::transaction(function () use ($companyId, $fromBranchId, $data, $request, $inventory) {
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
        $receivedQuantity = $request->input('received_quantity');
        if ($request->has('received_products')) {
            // Phase A adapter: never silently discard per-line differences into the legacy scalar API.
            $data = $request->validate([
                'received_products' => ['required', 'array', 'min:1'],
                'received_products.*.product_id' => ['required', 'integer', 'distinct'],
                'received_products.*.quantity' => ['required', 'decimal:0,4', 'gte:0'],
                'notes' => ['nullable', 'string', 'max:1000'],
            ]);
            $items = $transfer->items()->get()->keyBy('product_id');
            $lines = collect($data['received_products'])->keyBy('product_id');
            if ($items->count() !== $lines->count() || $items->keys()->diff($lines->keys())->isNotEmpty()) {
                throw ValidationException::withMessages(['received_products' => 'Revise todos los productos de este traslado.']);
            }
            $hasDifference = false;
            foreach ($lines as $productId => $line) {
                $inputQuantity = (string) $line['quantity'];
                $sentQuantity = (string) ($items[$productId]->sent_quantity ?? $items[$productId]->quantity);

                if (bccomp($inputQuantity, '0', self::QUANTITY_SCALE) === 0) {
                    throw ValidationException::withMessages(['received_products' => 'La recepción en cero todavía no está disponible. No se confirmó la recepción.']);
                }

                if (bccomp($inputQuantity, $sentQuantity, self::QUANTITY_SCALE) !== 0) {
                    $hasDifference = true;
                }
            }
            if ($items->count() > 1 && $hasDifference) {
                throw ValidationException::withMessages(['received_products' => 'La recepción con diferencias por producto todavía no está disponible para traslados de varios productos. No se confirmó la recepción.']);
            }
            $receivedQuantity = $items->count() === 1 ? (string) $lines->first()['quantity'] : null;
        }
        $inventory->receiveTransfer($transfer, (int) $request->user()->id, $request->input('notes'), $receivedQuantity);

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
        $companyId = (int) session('active_company_id');
        $company = Company::query()->findOrFail($companyId);

        abort_unless((int) $transfer->company_id === $companyId, 404);

        $assignedBranchIds = $request->user()->branches()
            ->where('branches.company_id', $companyId)
            ->pluck('branches.id');

        $canSeeOtherBranches = $request->user()->hasPermission('inventario.ver_otras_sucursales', $company);

        abort_unless(
            $canSeeOtherBranches
                || $assignedBranchIds->contains($transfer->from_branch_id)
                || $assignedBranchIds->contains($transfer->to_branch_id),
            403
        );

        $transfer->load(['fromBranch', 'toBranch', 'user', 'preparer', 'receiver', 'confirmer', 'items.product']);
        $dispatcher = User::query()->find($transfer->dispatched_by);
        $statusLabels = self::STATUS_LABELS;

        return view('transferencias.show', compact('transfer', 'dispatcher', 'statusLabels'));
    }
}
