<?php

namespace App\Services\Imports;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductCategory;
use App\Models\Unit;
use App\Services\Inventory\InventoryPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

class InventoryImportService
{
    private const REQUIRED_HEADERS = ['codigo', 'cantidad', 'minimo', 'maximo'];

    public function __construct(
        private readonly InventoryPostingService $inventory,
    ) {}

    public function preview(
        string $file,
        int $companyId,
        Branch $branch,
        string $movementType,
    ): array {
        $sourceRows = IOFactory::load($file)->getActiveSheet()->toArray(null, true, true, false);
        $sourceRows = array_values(array_filter($sourceRows, fn (array $row) => collect($row)
            ->contains(fn ($value) => $value !== null && trim((string) $value) !== '')));

        if ($sourceRows === []) {
            throw ValidationException::withMessages(['inventory_file' => 'El archivo no contiene filas de inventario.']);
        }

        $headers = array_map(fn ($value) => Str::of((string) $value)
            ->ascii()->lower()->replace('*', '')->trim()->toString(), array_shift($sourceRows));

        foreach (self::REQUIRED_HEADERS as $required) {
            if (! in_array($required, $headers, true)) {
                throw ValidationException::withMessages([
                    'inventory_file' => "Falta la columna obligatoria: {$required}",
                ]);
            }
        }

        $columns = array_flip($headers);
        $rows = [];
        $identities = [];

        foreach ($sourceRows as $index => $sourceRow) {
            $value = fn (string $column) => isset($columns[$column])
                ? $this->nullableValue($sourceRow[$columns[$column]] ?? null)
                : null;
            $code = trim((string) $value('codigo'));
            $name = trim((string) $value('nombre'));

            if ($code === '' && $name === '') {
                continue;
            }

            $barcode = $this->nullableString($value('codigo_barras'));
            $product = $this->findProduct($companyId, $code, $barcode);
            $category = $product === null ? $this->findCategory($companyId, $value('categoria')) : null;
            $unit = $product === null ? $this->findUnit($companyId, $value('unidad')) : null;
            $brand = $product === null ? $this->findBrand($companyId, $value('marca')) : null;
            $currentStock = $product === null ? 0.0 : (float) (DB::table('branch_product')
                ->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock') ?? 0);

            $row = [
                '_row' => $index + 2,
                'code' => $code,
                'product_id' => $product?->id,
                'product_name' => $product?->name ?? ($name !== '' ? $name : 'Producto nuevo'),
                'category_id' => $category?->id,
                'brand_id' => $brand?->id,
                'unit_id' => $unit?->id,
                'unit_allows_decimals' => $product?->unit?->allows_decimals ?? $unit?->allows_decimals,
                'barcode' => $barcode,
                'cabys' => $this->nullableString($value('cabys')),
                'cost' => $value('costo'),
                'sale_price' => $value('precio_venta'),
                'wholesale_price' => $value('precio_mayoreo'),
                'special_price' => $value('precio_especial'),
                'tax_rate' => $value('impuesto'),
                'description' => $this->nullableString($value('descripcion')),
                'quantity' => $value('cantidad'),
                'minimum' => $value('minimo'),
                'maximum' => $value('maximo'),
                'current_stock' => $currentStock,
                'is_new' => $product === null,
                'errors' => [],
            ];

            $row['errors'] = $this->validateRow($row, $companyId, $movementType, $value('categoria'), $value('unidad'), $value('marca'));
            $identity = $product ? 'product:'.$product->id : 'new:'.Str::lower($code !== '' ? $code : $barcode ?? $name);
            if (isset($identities[$identity])) {
                $row['errors'][] = 'El producto está repetido dentro del archivo.';
            }
            $identities[$identity] = true;
            $row['valid'] = $row['errors'] === [];
            $rows[] = $row;
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['inventory_file' => 'El archivo no contiene filas de inventario.']);
        }

        return $rows;
    }

    public function confirm(array $preview, int $companyId, int $userId): void
    {
        if ((int) ($preview['company_id'] ?? 0) !== $companyId || collect($preview['rows'] ?? [])->contains(fn ($row) => ! ($row['valid'] ?? false))) {
            throw ValidationException::withMessages(['inventory_file' => 'La vista previa no es válida para la empresa activa.']);
        }

        $branch = Branch::query()->where('company_id', $companyId)->where('is_active', true)
            ->findOrFail((int) ($preview['branch_id'] ?? 0));
        $movementType = (string) ($preview['movement_type'] ?? '');

        DB::transaction(function () use ($preview, $companyId, $userId, $branch, $movementType): void {
            foreach ($preview['rows'] as $row) {
                $product = empty($row['product_id'])
                    ? $this->createProduct($row, $companyId)
                    : Product::query()->where('company_id', $companyId)->where('is_active', true)->findOrFail($row['product_id']);

                $this->inventory->postImportMovement(
                    $branch,
                    $product,
                    $userId,
                    $movementType,
                    (float) $row['quantity'],
                    (float) $row['minimum'],
                    (float) $row['maximum'],
                );
            }
        });
    }

    private function validateRow(array $row, int $companyId, string $movementType, mixed $category, mixed $unit, mixed $brand): array
    {
        $errors = [];
        if ($row['code'] === '') {
            $errors[] = 'El código es obligatorio.';
        }
        if ($row['is_new'] && trim($row['product_name']) === '') {
            $errors[] = 'El nombre es obligatorio para productos nuevos.';
        }
        foreach (['quantity' => 'cantidad', 'minimum' => 'mínimo', 'maximum' => 'máximo'] as $field => $label) {
            if (! is_numeric($row[$field])) {
                $errors[] = "El {$label} debe ser numérico.";
            }
        }
        if (is_numeric($row['quantity']) && (float) $row['quantity'] <= 0) {
            $errors[] = 'La cantidad debe ser mayor que cero.';
        }
        if (is_numeric($row['quantity']) && $row['unit_allows_decimals'] === false
            && floor((float) $row['quantity']) !== (float) $row['quantity']) {
            $errors[] = 'La unidad del producto solo admite cantidades enteras.';
        }
        if (is_numeric($row['minimum']) && (float) $row['minimum'] < 0) {
            $errors[] = 'El mínimo no puede ser negativo.';
        }
        if (is_numeric($row['maximum']) && (float) $row['maximum'] < 0) {
            $errors[] = 'El máximo no puede ser negativo.';
        }
        if (is_numeric($row['minimum']) && is_numeric($row['maximum']) && (float) $row['maximum'] < (float) $row['minimum']) {
            $errors[] = 'El máximo no puede ser menor que el mínimo.';
        }
        if ($row['is_new']) {
            foreach (['cost' => 'costo', 'sale_price' => 'precio de venta', 'wholesale_price' => 'precio mayorista', 'special_price' => 'precio especial'] as $field => $label) {
                if ($row[$field] !== null && (! is_numeric($row[$field]) || (float) $row[$field] < 0)) {
                    $errors[] = "El {$label} debe ser un número mayor o igual a cero.";
                }
            }
        }
        if ($row['tax_rate'] !== null && (! is_numeric($row['tax_rate']) || (float) $row['tax_rate'] < 0 || (float) $row['tax_rate'] > 100)) {
            $errors[] = 'El impuesto debe estar entre 0 y 100.';
        }
        if ($movementType === 'exit' && is_numeric($row['quantity']) && $row['current_stock'] < (float) $row['quantity']) {
            $errors[] = 'La salida dejaría el inventario con stock negativo.';
        }
        if ($row['is_new'] && $row['category_id'] === null) {
            $errors[] = $this->hasValue($category) ? 'La categoría no existe o no pertenece a la empresa.' : 'La categoría es obligatoria para productos nuevos.';
        }
        if ($row['is_new'] && $row['unit_id'] === null) {
            $errors[] = $this->hasValue($unit) ? 'La unidad no existe o no pertenece a la empresa.' : 'La unidad es obligatoria para productos nuevos.';
        }
        if ($row['is_new'] && $this->hasValue($brand) && $row['brand_id'] === null) {
            $errors[] = 'La marca no existe o no pertenece a la empresa.';
        }

        $barcode = $row['barcode'];
        if ($barcode !== null) {
            $conflict = Product::query()->where('barcode', $barcode)->where('id', '!=', $row['product_id'] ?? 0)->exists()
                || ProductBarcode::query()->where('barcode', $barcode)->where('product_id', '!=', $row['product_id'] ?? 0)->exists();
            if ($conflict) {
                $errors[] = 'El código de barras ya está asignado a otro producto.';
            }
        }
        if ($row['is_new'] && Product::withTrashed()->where('internal_code', $row['code'])->exists()) {
            $errors[] = 'El código interno ya está asignado a otro producto.';
        }

        return $errors;
    }

    private function createProduct(array $row, int $companyId): Product
    {
        $product = Product::create([
            'company_id' => $companyId,
            'category_id' => $row['category_id'],
            'brand_id' => $row['brand_id'],
            'unit_id' => $row['unit_id'],
            'name' => $row['product_name'],
            'internal_code' => $row['code'],
            'barcode' => $row['barcode'],
            'cabys_code' => $row['cabys'],
            'cost' => (float) ($row['cost'] ?? 0),
            'sale_price' => (float) ($row['sale_price'] ?? 0),
            'wholesale_price' => $row['wholesale_price'],
            'special_price' => $row['special_price'],
            'tax_rate' => (float) ($row['tax_rate'] ?? 0),
            'description' => $row['description'],
            'product_type' => 'product',
            'track_inventory' => true,
            'minimum_stock' => (float) $row['minimum'],
            'maximum_stock' => (float) $row['maximum'],
            'is_active' => true,
        ]);

        if ($row['barcode'] !== null) {
            ProductBarcode::create([
                'product_id' => $product->id,
                'barcode' => $row['barcode'],
                'barcode_type' => 'supplier',
                'is_primary' => true,
                'is_active' => true,
            ]);
        }

        return $product;
    }

    private function findProduct(int $companyId, string $code, ?string $barcode): ?Product
    {
        $values = array_values(array_unique(array_filter([$code, $barcode], fn ($value) => $value !== null && $value !== '')));
        if ($values === []) {
            return null;
        }

        $product = Product::query()->where('company_id', $companyId)->where('is_active', true)
            ->where(fn ($query) => $query->whereIn('internal_code', $values)->orWhereIn('barcode', $values))->first();
        if ($product !== null) {
            return $product;
        }

        return ProductBarcode::query()->whereIn('barcode', $values)->where('is_active', true)
            ->whereHas('product', fn ($query) => $query->where('company_id', $companyId)->where('is_active', true))
            ->with('product')->first()?->product;
    }

    private function findCategory(int $companyId, mixed $name): ?ProductCategory
    {
        return ! $this->hasValue($name) ? null : ProductCategory::query()->where('company_id', $companyId)
            ->where('is_active', true)->whereRaw('LOWER(name) = ?', [Str::lower(trim((string) $name))])->first();
    }

    private function findUnit(int $companyId, mixed $name): ?Unit
    {
        if (! $this->hasValue($name)) {
            return null;
        }
        $normalized = Str::lower(trim((string) $name));

        return Unit::query()->where('company_id', $companyId)->where('is_active', true)
            ->where(fn ($query) => $query->whereRaw('LOWER(name) = ?', [$normalized])->orWhereRaw('LOWER(abbreviation) = ?', [$normalized]))->first();
    }

    private function findBrand(int $companyId, mixed $name): ?Brand
    {
        return ! $this->hasValue($name) ? null : Brand::query()->where('company_id', $companyId)
            ->where('is_active', true)->whereRaw('LOWER(name) = ?', [Str::lower(trim((string) $name))])->first();
    }

    private function nullableValue(mixed $value): mixed
    {
        return is_string($value) ? (($value = trim($value)) === '' ? null : $value) : $value;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = $this->nullableValue($value);

        return $value === null ? null : trim((string) $value);
    }

    private function hasValue(mixed $value): bool
    {
        return $value !== null && trim((string) $value) !== '';
    }
}
