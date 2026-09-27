<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Product;
use App\Services\Inventory\InventoryPostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryAdjustmentController extends Controller
{
    /**
     * Cantidad máxima de filas de un ajuste por lote.
     */
    private const MAX_ROWS = 100;

    /**
     * Mostrar formulario para nuevo ajuste (una o varias filas).
     */
    public function create()
    {
        $rows = old('rows');

        $initialRows = is_array($rows) && $rows !== []
            ? array_values(array_map(
                fn ($row) => array_merge($this->emptyRow(), is_array($row) ? $row : []),
                $rows,
            ))
            : [$this->emptyRow()];

        $branches = $this->availableBranches();

        return view('ajustes-inventario.create', [
            'initialRows' => $initialRows,
            'branches' => $branches,
            'activeBranchId' => (int) session('active_branch_id'),
            'activeBranchName' => $branches->firstWhere('id', (int) session('active_branch_id'))?->name ?? '',
        ]);
    }

    /**
     * Revalida las filas del formulario contra la sucursal activa. Se invoca
     * después de cambiar de sucursal: devuelve las filas intactas junto con
     * los avisos/errores por fila, sin eliminar ninguna.
     */
    public function revalidate(Request $request, InventoryPostingService $posting)
    {
        $companyId = (int) session('active_company_id');
        $activeBranchId = (int) session('active_branch_id');

        $data = $request->validate([
            'rows' => ['required', 'array', 'max:'.self::MAX_ROWS],
            'rows.*.product_id' => ['nullable', 'integer'],
            'rows.*.product_label' => ['nullable', 'string', 'max:255'],
            'rows.*.allows_decimals' => ['nullable'],
            'rows.*.adjustment_type' => ['nullable', 'in:entry,exit'],
            'rows.*.quantity' => ['nullable', 'numeric', 'gte:0'],
            'rows.*.reason' => ['nullable', 'string', 'max:255'],
            'rows.*.notes' => ['nullable', 'string'],
        ]);

        $rows = array_values($data['rows']);

        $branch = $activeBranchId > 0
            ? Branch::query()->where('company_id', $companyId)->find($activeBranchId)
            : null;

        if ($branch === null) {
            return response()->json([
                'branch_id' => null,
                'branch_name' => null,
                'rows' => $rows,
                'errors' => [],
                'warnings' => [],
            ]);
        }

        $ids = array_values(array_unique(array_map(
            fn (array $row) => (int) ($row['product_id'] ?? 0),
            $rows,
        )));

        $products = Product::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        $stocks = DB::table('branch_product')
            ->where('branch_id', $branch->id)
            ->whereIn('product_id', $ids)
            ->pluck('stock', 'product_id')
            ->mapWithKeys(fn ($stock, $id) => [(int) $id => $stock]);

        $errors = [];
        $warnings = [];
        $seen = [];

        foreach ($rows as $index => $row) {
            $productId = (int) ($row['product_id'] ?? 0);

            if ($productId <= 0) {
                continue;
            }

            $product = $products->get($productId);

            if ($product === null) {
                $errors["rows.{$index}.product_id"] = 'El producto seleccionado no está disponible en la empresa activa.';

                continue;
            }

            if (isset($seen[$productId])) {
                $errors["rows.{$index}.product_id"] = 'Este producto ya fue agregado en otra fila. Consolídelo en una sola fila.';

                continue;
            }
            $seen[$productId] = true;

            $type = (string) ($row['adjustment_type'] ?? '');

            if (! $stocks->has($productId)) {
                if ($type === 'exit') {
                    $errors["rows.{$index}.product_id"] = "El producto {$product->name} no está asignado a la sucursal {$branch->name}. No se puede aplicar una salida allí.";
                } else {
                    $warnings["rows.{$index}.product_id"] = "El producto {$product->name} no está asignado a la sucursal {$branch->name}.";
                }

                continue;
            }

            if ($type !== 'exit') {
                continue;
            }

            try {
                $quantity = $posting->transferQuantity((string) ($row['quantity'] ?? '0'));
            } catch (ValidationException) {
                continue;
            }

            $available = bcadd((string) $stocks->get($productId), '0', 4);

            if (bccomp($available, $quantity, 4) < 0) {
                $errors["rows.{$index}.quantity"] = "Stock insuficiente para {$product->name}. Disponible: {$available}. Solicitado: {$quantity}.";
            }
        }

        return response()->json([
            'branch_id' => $branch->id,
            'branch_name' => $branch->name,
            'rows' => $rows,
            'errors' => $errors,
            'warnings' => $warnings,
        ]);
    }

    /**
     * Guardar ajuste de inventario. Acepta el payload histórico de un solo
     * producto o el payload de lote `rows[]`. Todo se aplica en una única
     * transacción: si una fila falla, no se aplica ninguna.
     */
    public function store(Request $request, InventoryPostingService $posting)
    {
        $companyId = (int) session('active_company_id');
        $branchId = (int) session('active_branch_id');

        $isBatch = $request->has('rows');
        $rows = $this->validatedRows($request, $isBatch);

        $branch = $branchId > 0
            ? Branch::query()->where('company_id', $companyId)->find($branchId)
            : null;

        if ($branch === null) {
            throw ValidationException::withMessages([
                'branch' => 'Debe tener una sucursal activa de esta empresa para aplicar ajustes de inventario.',
            ]);
        }

        $prepared = $this->prepareRows($rows, $isBatch, $companyId, $branch->id, $posting);

        $applied = DB::transaction(function () use ($prepared, $branch, $posting) {
            // Orden estable por producto para bloquear filas sin riesgo de deadlock.
            $ordered = collect($prepared)->sortBy('product.id')->values();

            foreach ($ordered as $item) {
                $posting->postAdjustment(
                    $branch,
                    $item['product'],
                    (int) auth()->id(),
                    $item['type'],
                    $item['quantity'],
                    $item['reason'],
                    $item['notes'],
                );
            }

            return $ordered->count();
        });

        return redirect()
            ->route('inventario.index')
            ->with('success', $applied === 1
                ? 'Ajuste de inventario realizado correctamente.'
                : "Ajuste de inventario aplicado a {$applied} productos correctamente.");
    }

    /**
     * Valida el payload y lo normaliza a una lista de filas.
     */
    private function validatedRows(Request $request, bool $isBatch): array
    {
        if ($isBatch) {
            return $request->validate([
                'rows' => ['required', 'array', 'min:1', 'max:'.self::MAX_ROWS],
                'rows.*.product_id' => ['required', 'integer'],
                'rows.*.adjustment_type' => ['required', 'in:entry,exit'],
                'rows.*.quantity' => ['required', 'numeric', 'gt:0'],
                'rows.*.reason' => ['required', 'string', 'max:255'],
                'rows.*.notes' => ['nullable', 'string'],
            ])['rows'];
        }

        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'adjustment_type' => ['required', 'in:entry,exit'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        return [$data];
    }

    /**
     * Resuelve productos, detecta duplicados y normaliza cantidades con
     * precisión decimal. Devuelve todos los errores de fila de una sola vez.
     */
    private function prepareRows(array $rows, bool $isBatch, int $companyId, int $branchId, InventoryPostingService $posting): array
    {
        $ids = array_values(array_unique(array_map(
            fn (array $row) => (int) ($row['product_id'] ?? 0),
            $rows,
        )));

        $products = Product::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        $stocks = DB::table('branch_product')
            ->where('branch_id', $branchId)
            ->whereIn('product_id', $ids)
            ->pluck('stock', 'product_id')
            ->mapWithKeys(fn ($stock, $id) => [(int) $id => $stock]);

        $errors = [];
        $seen = [];
        $prepared = [];

        foreach ($rows as $index => $row) {
            $key = fn (string $field) => $isBatch ? "rows.{$index}.{$field}" : $field;

            $productId = (int) ($row['product_id'] ?? 0);
            $product = $products->get($productId);

            if ($product === null) {
                $errors[$key('product_id')] = 'El producto seleccionado no está disponible en la empresa activa.';

                continue;
            }

            if (isset($seen[$productId])) {
                $errors[$key('product_id')] = 'Este producto ya fue agregado en otra fila. Consolídelo en una sola fila.';

                continue;
            }
            $seen[$productId] = true;

            try {
                $quantity = $posting->transferQuantity((string) $row['quantity']);
                $posting->assertQuantityPrecision($product, $quantity);
            } catch (ValidationException $exception) {
                $errors[$key('quantity')] = $exception->errors()['quantity'][0]
                    ?? 'La cantidad indicada no es válida.';

                continue;
            }

            $reason = trim((string) ($row['reason'] ?? ''));
            $notes = trim((string) ($row['notes'] ?? ''));

            if ($reason === '') {
                $errors[$key('reason')] = 'El motivo del ajuste es obligatorio.';

                continue;
            }

            if ($row['adjustment_type'] === 'exit') {
                $available = bcadd((string) ($stocks->get($productId) ?? '0'), '0', 4);

                if (bccomp($available, $quantity, 4) < 0) {
                    $errors[$key('quantity')] = "Stock insuficiente para {$product->name}. Disponible: {$available}. Solicitado: {$quantity}.";

                    continue;
                }

            }

            $prepared[] = [
                'product' => $product,
                'type' => $row['adjustment_type'],
                'quantity' => $quantity,
                'reason' => $reason,
                'notes' => $notes === '' ? null : $notes,
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $prepared;
    }

    private function emptyRow(): array
    {
        return [
            'product_id' => '',
            'product_label' => '',
            'adjustment_type' => '',
            'quantity' => '',
            'reason' => '',
            'notes' => '',
        ];
    }

    /**
     * Sucursales activas de la empresa actual disponibles para el usuario.
     */
    private function availableBranches()
    {
        return auth()->user()
            ->branches()
            ->where('branches.company_id', (int) session('active_company_id'))
            ->where('branches.is_active', true)
            ->orderBy('branches.name')
            ->get(['branches.id', 'branches.name']);
    }
}
