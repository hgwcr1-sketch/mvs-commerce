<?php

namespace App\Services\Imports;

use App\Models\Brand;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductCategory;
use App\Models\Unit;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ProductImportService
{
    private array $categoryCache = [];

    private array $brandCache = [];

    private array $unitCache = [];

    public const HEADERS = [
        'codigo_interno*', 'nombre*', 'categoria*', 'marca', 'unidad*', 'tipo_producto*',
        'codigo_barras_principal', 'codigos_barras_adicionales', 'cabys', 'descripcion_corta',
        'descripcion', 'costo*', 'precio_venta*', 'precio_mayorista', 'precio_especial',
        'precio_a', 'precio_b', 'precio_c', 'impuesto*', 'controla_inventario',
        'permite_stock_negativo', 'imprime_etiqueta', 'activo',
    ];

    private const HEADER_MAP = [
        'codigo_interno' => 'internal_code', 'codigo' => 'internal_code', 'nombre' => 'name',
        'categoria' => 'category', 'marca' => 'brand', 'unidad' => 'unit', 'tipo_producto' => 'product_type',
        'tipo' => 'product_type', 'codigo_barras_principal' => 'barcode', 'codigo_de_barras_principal' => 'barcode',
        'codigo_barras' => 'barcode', 'codigos_barras_adicionales' => 'additional_barcodes',
        'codigos_de_barras_adicionales' => 'additional_barcodes', 'cabys' => 'cabys_code',
        'descripcion_corta' => 'short_description', 'descripcion' => 'description', 'costo' => 'cost',
        'precio_venta' => 'sale_price', 'precio_de_venta' => 'sale_price', 'precio_mayorista' => 'wholesale_price',
        'precio_especial' => 'special_price', 'precio_a' => 'price_a', 'precio_b' => 'price_b',
        'precio_c' => 'price_c', 'impuesto' => 'tax_rate', 'impuesto_%' => 'tax_rate',
        'controla_inventario' => 'track_inventory', 'permite_stock_negativo' => 'allow_negative_stock',
        'imprime_etiqueta' => 'prints_label', 'activo' => 'is_active',
    ];

    private const FIELD_LABELS = [
        'internal_code' => 'codigo_interno', 'name' => 'nombre', 'category_name' => 'categoria',
        'brand_name' => 'marca', 'unit_name' => 'unidad', 'product_type' => 'tipo_producto',
        'barcode' => 'codigo_barras_principal', 'additional_barcodes' => 'codigos_barras_adicionales',
        'cabys_code' => 'cabys', 'short_description' => 'descripcion_corta', 'description' => 'descripcion',
        'cost' => 'costo', 'sale_price' => 'precio_venta', 'wholesale_price' => 'precio_mayorista',
        'special_price' => 'precio_especial', 'price_a' => 'precio_a', 'price_b' => 'precio_b',
        'price_c' => 'precio_c', 'tax_rate' => 'impuesto', 'track_inventory' => 'controla_inventario',
        'allow_negative_stock' => 'permite_stock_negativo', 'prints_label' => 'imprime_etiqueta',
        'is_active' => 'activo',
    ];

    public function preview(string $path, int $companyId): array
    {
        Company::query()->findOrFail($companyId);
        $sourceRows = IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false);

        if (count($sourceRows) < 2) {
            throw ValidationException::withMessages([
                'product_file' => 'El archivo debe incluir encabezados y al menos una fila de productos.',
            ]);
        }

        $headers = $this->resolveHeaders(array_shift($sourceRows));
        $rows = [];
        foreach ($sourceRows as $offset => $values) {
            if (collect($values)->every(fn ($value) => trim((string) $value) === '')) {
                continue;
            }

            $data = [];
            foreach ($headers as $column => $field) {
                if ($field !== null) {
                    $data[$field] = $values[$column] ?? null;
                }
            }
            $rows[] = $this->normalizeRow($data, $offset + 2, $companyId);
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['product_file' => 'El archivo no contiene filas de productos para revisar.']);
        }

        return $this->validateRows($rows, $companyId);
    }

    public function confirm(array $preview, int $companyId): int
    {
        if ((int) ($preview['company_id'] ?? 0) !== $companyId) {
            throw ValidationException::withMessages(['product_file' => 'La vista previa no pertenece a la empresa activa.']);
        }

        $rows = $this->validateRows($preview['rows'] ?? [], $companyId);
        $invalid = collect($rows)->firstWhere('valid', false);
        if ($invalid !== null) {
            $firstError = $invalid['errors'][0] ?? null;
            $detail = is_array($firstError)
                ? ' '.($firstError['field'] ?? 'campo').': '.($firstError['message'] ?? 'dato inválido')
                : '';
            throw ValidationException::withMessages([
                'product_file' => 'La importación cambió o contiene errores. Revise la fila '.($invalid['row_number'] ?? '?').'.'.$detail,
            ]);
        }

        return DB::transaction(function () use ($rows, $companyId): int {
            $rows = array_map(fn (array $row) => $this->resolveCatalogsForConfirmation($row, $companyId), $rows);
            $rows = $this->validateRows($rows, $companyId);
            $invalid = collect($rows)->firstWhere('valid', false);
            if ($invalid !== null) {
                throw ValidationException::withMessages([
                    'product_file' => 'La importación cambió mientras se confirmaba. Revise la fila '.($invalid['row_number'] ?? '?').'.',
                ]);
            }

            foreach ($rows as $row) {
                $product = Product::create(['company_id' => $companyId, ...$this->attributes($row)]);
                foreach ($row['barcodes'] as $index => $barcode) {
                    ProductBarcode::create([
                        'product_id' => $product->id,
                        'barcode' => $barcode,
                        'barcode_type' => 'supplier',
                        'is_primary' => $index === 0 && $barcode === $row['barcode'],
                        'is_active' => true,
                    ]);
                }
            }

            return count($rows);
        });
    }

    private function resolveHeaders(array $headers): array
    {
        $resolved = [];
        foreach ($headers as $column => $header) {
            $key = Str::of((string) $header)->replace("\xEF\xBB\xBF", '')->ascii()->lower()
                ->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();
            $resolved[$column] = self::HEADER_MAP[$key] ?? null;
        }
        foreach (['internal_code', 'name', 'category', 'unit', 'product_type', 'cost', 'sale_price', 'tax_rate'] as $required) {
            if (! in_array($required, $resolved, true)) {
                throw ValidationException::withMessages([
                    'product_file' => 'Falta una columna obligatoria de Productos. Descargue la plantilla vigente.',
                ]);
            }
        }

        return $resolved;
    }

    private function normalizeRow(array $data, int $rowNumber, int $companyId): array
    {
        $categoryName = $this->catalogName($data['category'] ?? null);
        $brandName = $this->catalogName($data['brand'] ?? null);
        $unitName = $this->catalogName($data['unit'] ?? null);
        $category = $this->category($companyId, $categoryName);
        $brand = $this->brand($companyId, $brandName);
        $unit = $this->unit($companyId, $unitName);
        $primary = $this->nullable($data['barcode'] ?? null);
        $additional = collect(preg_split('/\s*\|\s*/', $this->nullable($data['additional_barcodes'] ?? null) ?? ''))
            ->map(fn ($barcode) => trim((string) $barcode))->filter()->unique()->values()->all();
        $barcodes = array_values(array_unique(array_filter([$primary, ...$additional])));

        return [
            'row_number' => $rowNumber,
            'internal_code' => $this->nullable($data['internal_code'] ?? null),
            'name' => $this->nullable($data['name'] ?? null),
            'category_name' => $categoryName,
            'category_id' => $category?->id,
            'category_will_create' => $categoryName !== null && $category === null,
            'brand_name' => $brandName,
            'brand_id' => $brand?->id,
            'brand_will_create' => $brandName !== null && $brand === null,
            'unit_name' => $unitName,
            'unit_id' => $unit?->id,
            'unit_will_create' => $unitName !== null && $unit === null,
            'product_type' => Str::lower($this->nullable($data['product_type'] ?? null) ?? ''),
            'barcode' => $primary,
            'additional_barcodes' => $additional,
            'barcodes' => $barcodes,
            'cabys_code' => $this->nullable($data['cabys_code'] ?? null),
            'short_description' => $this->nullable($data['short_description'] ?? null),
            'description' => $this->nullable($data['description'] ?? null),
            'cost' => $this->decimalValue($data['cost'] ?? null),
            'sale_price' => $this->decimalValue($data['sale_price'] ?? null),
            'wholesale_price' => $this->decimalValue($data['wholesale_price'] ?? null),
            'special_price' => $this->decimalValue($data['special_price'] ?? null),
            'price_a' => $this->decimalValue($data['price_a'] ?? null),
            'price_b' => $this->decimalValue($data['price_b'] ?? null),
            'price_c' => $this->decimalValue($data['price_c'] ?? null),
            'tax_rate' => $this->decimalValue($data['tax_rate'] ?? null),
            'track_inventory' => $this->booleanValue($data['track_inventory'] ?? null, true),
            'allow_negative_stock' => $this->booleanValue($data['allow_negative_stock'] ?? null, false),
            'prints_label' => $this->booleanValue($data['prints_label'] ?? null, false),
            'is_active' => $this->booleanValue($data['is_active'] ?? null, true),
            'valid' => true,
            'errors' => [],
        ];
    }

    private function validateRows(array $rows, int $companyId): array
    {
        $seenCodes = [];
        $seenBarcodes = [];
        $categoryIds = ProductCategory::query()->where('company_id', $companyId)->where('is_active', true)
            ->pluck('id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();
        $brandIds = Brand::query()->where('company_id', $companyId)->where('is_active', true)
            ->pluck('id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();
        $unitIds = Unit::query()->where('company_id', $companyId)->where('is_active', true)
            ->pluck('id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();
        $sourceCodes = collect($rows)->pluck('internal_code')->filter()->unique()->values()->all();
        $existingCodes = $this->existingValues(Product::class, 'internal_code', $sourceCodes, true);
        $sourceBarcodes = collect($rows)->flatMap(fn (array $row) => $row['barcodes'])->filter()->unique()->values()->all();
        $existingBarcodes = $this->existingValues(Product::class, 'barcode', $sourceBarcodes, true)
            + $this->existingValues(ProductBarcode::class, 'barcode', $sourceBarcodes);
        foreach ($rows as $index => $row) {
            $row['errors'] = [];
            $requiredCost = ['required', 'decimal:0,4', 'gte:0'];
            $requiredMoney = ['required', 'decimal:0,2', 'gte:0'];
            $optionalMoney = ['nullable', 'decimal:0,2', 'gte:0'];
            $validator = Validator::make($row, [
                'internal_code' => ['required', 'string', 'max:50'], 'name' => ['required', 'string', 'max:150'],
                'category_name' => ['required', 'string', 'max:100'], 'brand_name' => ['nullable', 'string', 'max:150'], 'unit_name' => ['required', 'string', 'max:50'],
                'category_id' => ['nullable', 'integer'], 'brand_id' => ['nullable', 'integer'], 'unit_id' => ['nullable', 'integer'],
                'product_type' => ['required', 'in:product,service,combo'], 'barcode' => ['nullable', 'string', 'max:100'],
                'cabys_code' => ['nullable', 'string', 'max:20'], 'short_description' => ['nullable', 'string', 'max:255'],
                'description' => ['nullable', 'string'], 'cost' => $requiredCost,
                'sale_price' => $requiredMoney, 'wholesale_price' => $optionalMoney,
                'special_price' => $optionalMoney, 'price_a' => $optionalMoney,
                'price_b' => $optionalMoney, 'price_c' => $optionalMoney,
                'tax_rate' => [...$requiredMoney, 'lte:100'], 'track_inventory' => ['required', 'boolean'],
                'allow_negative_stock' => ['required', 'boolean'], 'prints_label' => ['required', 'boolean'], 'is_active' => ['required', 'boolean'],
            ], [], self::FIELD_LABELS);
            foreach ($validator->errors()->messages() as $field => $messages) {
                foreach ($messages as $message) {
                    $row['errors'][] = ['field' => self::FIELD_LABELS[$field] ?? $field, 'message' => $message];
                }
            }

            if ($row['category_id'] !== null && ! isset($categoryIds[(int) $row['category_id']])) {
                $row['errors'][] = ['field' => 'categoria', 'message' => 'La categoría ya no está activa o no pertenece a la empresa activa.'];
            }
            if ($row['unit_id'] !== null && ! isset($unitIds[(int) $row['unit_id']])) {
                $row['errors'][] = ['field' => 'unidad', 'message' => 'La unidad ya no está activa o no pertenece a la empresa activa.'];
            }
            if ($row['brand_id'] !== null && ! isset($brandIds[(int) $row['brand_id']])) {
                $row['errors'][] = ['field' => 'marca', 'message' => 'La marca ya no está activa o no pertenece a la empresa activa.'];
            }

            $codeKey = Str::lower((string) $row['internal_code']);
            if (isset($seenCodes[$codeKey])) {
                $row['errors'][] = ['field' => 'codigo_interno', 'message' => 'El código se repite en la fila '.$seenCodes[$codeKey].'.'];
            } else {
                $seenCodes[$codeKey] = $row['row_number'];
            }
            if ($row['internal_code'] !== null && isset($existingCodes[$row['internal_code']])) {
                $row['errors'][] = ['field' => 'codigo_interno', 'message' => 'El código interno ya está asignado a un producto.'];
            }

            foreach ($row['barcodes'] as $barcode) {
                if (mb_strlen($barcode) > 100) {
                    $row['errors'][] = ['field' => 'codigos_barras', 'message' => 'Cada código de barras admite máximo 100 caracteres.'];
                }
                if (isset($seenBarcodes[$barcode])) {
                    $row['errors'][] = ['field' => 'codigos_barras', 'message' => 'El código de barras se repite en la fila '.$seenBarcodes[$barcode].'.'];
                } else {
                    $seenBarcodes[$barcode] = $row['row_number'];
                }
                if (isset($existingBarcodes[$barcode])) {
                    $row['errors'][] = ['field' => 'codigos_barras', 'message' => 'El código de barras ya está asignado a otro producto.'];
                }
            }

            $row['valid'] = $row['errors'] === [];
            $rows[$index] = $row;
        }

        return $rows;
    }

    private function attributes(array $row): array
    {
        return Arr::only($row, [
            'category_id', 'brand_id', 'unit_id', 'name', 'internal_code', 'barcode', 'product_type',
            'cabys_code', 'short_description', 'description', 'cost', 'sale_price', 'wholesale_price',
            'special_price', 'price_a', 'price_b', 'price_c', 'track_inventory', 'allow_negative_stock',
            'tax_rate', 'is_active', 'prints_label',
        ]);
    }

    private function resolveCatalogsForConfirmation(array $row, int $companyId): array
    {
        $category = $this->category($companyId, $row['category_name'])
            ?? $this->rememberCategory(ProductCategory::create([
                'company_id' => $companyId,
                'name' => $row['category_name'],
                'slug' => $this->uniqueSlug(ProductCategory::class, $row['category_name'], $companyId),
                'is_active' => true,
            ]));
        $unit = $this->unit($companyId, $row['unit_name'])
            ?? $this->rememberUnit(Unit::create([
                'company_id' => $companyId,
                'name' => $row['unit_name'],
                'abbreviation' => $this->uniqueUnitAbbreviation($row['unit_name'], $companyId),
                'slug' => $this->uniqueSlug(Unit::class, $row['unit_name'], $companyId),
                'allows_decimals' => false,
                'is_active' => true,
            ]));
        $brand = $row['brand_name'] === null
            ? null
            : ($this->brand($companyId, $row['brand_name']) ?? $this->rememberBrand(Brand::create([
                'company_id' => $companyId,
                'name' => $row['brand_name'],
                'is_active' => true,
            ])));

        return [...$row,
            'category_id' => $category->id, 'category_will_create' => false,
            'unit_id' => $unit->id, 'unit_will_create' => false,
            'brand_id' => $brand?->id, 'brand_will_create' => false,
        ];
    }

    private function category(int $companyId, ?string $name): ?ProductCategory
    {
        if ($name === null) {
            return null;
        }
        $this->categoryCache[$companyId] ??= ProductCategory::query()->where('company_id', $companyId)
            ->where('is_active', true)->get()->keyBy(fn (ProductCategory $category) => $this->catalogKey($category->name))->all();

        return $this->categoryCache[$companyId][$this->catalogKey($name)] ?? null;
    }

    private function brand(int $companyId, ?string $name): ?Brand
    {
        if ($name === null) {
            return null;
        }
        $this->brandCache[$companyId] ??= Brand::query()->where('company_id', $companyId)
            ->where('is_active', true)->get()->keyBy(fn (Brand $brand) => $this->catalogKey($brand->name))->all();

        return $this->brandCache[$companyId][$this->catalogKey($name)] ?? null;
    }

    private function unit(int $companyId, ?string $name): ?Unit
    {
        if ($name === null) {
            return null;
        }
        if (! isset($this->unitCache[$companyId])) {
            $this->unitCache[$companyId] = [];
            foreach (Unit::query()->where('company_id', $companyId)->where('is_active', true)->get() as $unit) {
                $this->unitCache[$companyId][$this->catalogKey($unit->name)] = $unit;
                $this->unitCache[$companyId][$this->catalogKey($unit->abbreviation)] = $unit;
            }
        }

        return $this->unitCache[$companyId][$this->catalogKey($name)] ?? null;
    }

    private function rememberCategory(ProductCategory $category): ProductCategory
    {
        $this->categoryCache[$category->company_id][$this->catalogKey($category->name)] = $category;

        return $category;
    }

    private function rememberBrand(Brand $brand): Brand
    {
        $this->brandCache[$brand->company_id][$this->catalogKey($brand->name)] = $brand;

        return $brand;
    }

    private function rememberUnit(Unit $unit): Unit
    {
        $this->unitCache[$unit->company_id][$this->catalogKey($unit->name)] = $unit;
        $this->unitCache[$unit->company_id][$this->catalogKey($unit->abbreviation)] = $unit;

        return $unit;
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function catalogName(mixed $value): ?string
    {
        $value = $this->nullable($value);

        return $value === null ? null : preg_replace('/\s+/u', ' ', $value);
    }

    private function catalogKey(string $value): string
    {
        return Str::lower(preg_replace('/\s+/u', ' ', trim($value)));
    }

    private function booleanValue(mixed $value, bool $default): bool
    {
        $value = $this->nullable($value);
        if ($value === null) {
            return $default;
        }

        return ! in_array(Str::lower($value), ['0', 'no', 'n', 'false', 'inactivo'], true);
    }

    private function decimalValue(mixed $value): ?string
    {
        $value = $this->nullable($value);
        if ($value === null) {
            return $value;
        }
        $value = preg_replace('/[\p{Z}\s]+/u', '', $value);
        if (str_starts_with($value, "'") && preg_match('/^\'\d+(?:\.\d+)?$/', $value)) {
            $value = substr($value, 1);
        }
        if (! preg_match('/^\d+(?:\.\d+)?$/', $value)) {
            return $value;
        }

        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, null);
        $integer = ltrim($integer, '0') ?: '0';
        $fraction = $fraction === null ? null : rtrim($fraction, '0');

        return $fraction === null || $fraction === '' ? $integer : $integer.'.'.$fraction;
    }

    private function uniqueSlug(string $model, string $name, int $companyId): string
    {
        $base = Str::slug($name) ?: 'catalogo';
        $candidate = $base.'-'.$companyId;
        $suffix = 2;
        while ($model::withTrashed()->where('slug', $candidate)->exists()) {
            $candidate = $base.'-'.$companyId.'-'.$suffix++;
        }

        return $candidate;
    }

    private function uniqueUnitAbbreviation(string $name, int $companyId): string
    {
        $base = mb_strtoupper(mb_substr(preg_replace('/\s+/u', '', $name), 0, 10));
        $base = $base !== '' ? $base : 'UNIDAD';
        $candidate = $base;
        $suffix = 2;
        while (Unit::withTrashed()->where('company_id', $companyId)
            ->whereRaw('LOWER(abbreviation) = ?', [Str::lower($candidate)])->exists()) {
            $suffixText = (string) $suffix++;
            $candidate = mb_substr($base, 0, 10 - mb_strlen($suffixText)).$suffixText;
        }

        return $candidate;
    }

    private function existingValues(string $model, string $column, array $values, bool $withTrashed = false): array
    {
        $existing = [];
        foreach (array_chunk($values, 500) as $chunk) {
            $query = $withTrashed ? $model::withTrashed() : $model::query();
            foreach ($query->whereIn($column, $chunk)->pluck($column) as $value) {
                if ($value !== null) {
                    $existing[(string) $value] = true;
                }
            }
        }

        return $existing;
    }
}
