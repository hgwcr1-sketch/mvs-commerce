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
        'internal_code' => 'codigo_interno', 'name' => 'nombre', 'category_id' => 'categoria',
        'brand_id' => 'marca', 'unit_id' => 'unidad', 'product_type' => 'tipo_producto',
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
            $key = trim(Str::of((string) $header)->ascii()->lower()->replace([' ', '-', '*'], ['_', '_', ''])->toString(), '_');
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
        $categoryName = $this->nullable($data['category'] ?? null);
        $brandName = $this->nullable($data['brand'] ?? null);
        $unitName = $this->nullable($data['unit'] ?? null);
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
            'brand_name' => $brandName,
            'brand_id' => $brand?->id,
            'unit_name' => $unitName,
            'unit_id' => $unit?->id,
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
        foreach ($rows as $index => $row) {
            $row['errors'] = [];
            $validator = Validator::make($row, [
                'internal_code' => ['required', 'string', 'max:50'], 'name' => ['required', 'string', 'max:150'],
                'category_id' => ['required', 'integer'], 'brand_id' => ['nullable', 'integer'], 'unit_id' => ['required', 'integer'],
                'product_type' => ['required', 'in:product,service,combo'], 'barcode' => ['nullable', 'string', 'max:100'],
                'cabys_code' => ['nullable', 'string', 'max:20'], 'short_description' => ['nullable', 'string', 'max:255'],
                'description' => ['nullable', 'string'], 'cost' => ['required', 'regex:/^\d+(?:\.\d{1,2})?$/', 'gte:0'],
                'sale_price' => ['required', 'regex:/^\d+(?:\.\d{1,2})?$/', 'gte:0'], 'wholesale_price' => ['nullable', 'regex:/^\d+(?:\.\d{1,2})?$/', 'gte:0'],
                'special_price' => ['nullable', 'regex:/^\d+(?:\.\d{1,2})?$/', 'gte:0'], 'price_a' => ['nullable', 'regex:/^\d+(?:\.\d{1,2})?$/', 'gte:0'],
                'price_b' => ['nullable', 'regex:/^\d+(?:\.\d{1,2})?$/', 'gte:0'], 'price_c' => ['nullable', 'regex:/^\d+(?:\.\d{1,2})?$/', 'gte:0'],
                'tax_rate' => ['required', 'regex:/^\d+(?:\.\d{1,2})?$/', 'gte:0', 'lte:100'], 'track_inventory' => ['required', 'boolean'],
                'allow_negative_stock' => ['required', 'boolean'], 'prints_label' => ['required', 'boolean'], 'is_active' => ['required', 'boolean'],
            ], [], self::FIELD_LABELS);
            foreach ($validator->errors()->messages() as $field => $messages) {
                foreach ($messages as $message) {
                    $row['errors'][] = ['field' => self::FIELD_LABELS[$field] ?? $field, 'message' => $message];
                }
            }

            if ($row['category_id'] === null) {
                $row['errors'][] = ['field' => 'categoria', 'message' => $row['category_name'] ? 'La categoría no existe o no pertenece a la empresa activa.' : 'La categoría es obligatoria.'];
            } elseif (! ProductCategory::query()->whereKey($row['category_id'])->where('company_id', $companyId)->where('is_active', true)->exists()) {
                $row['errors'][] = ['field' => 'categoria', 'message' => 'La categoría ya no está activa o no pertenece a la empresa activa.'];
            }
            if ($row['unit_id'] === null) {
                $row['errors'][] = ['field' => 'unidad', 'message' => $row['unit_name'] ? 'La unidad no existe o no pertenece a la empresa activa.' : 'La unidad es obligatoria.'];
            } elseif (! Unit::query()->whereKey($row['unit_id'])->where('company_id', $companyId)->where('is_active', true)->exists()) {
                $row['errors'][] = ['field' => 'unidad', 'message' => 'La unidad ya no está activa o no pertenece a la empresa activa.'];
            }
            if ($row['brand_name'] !== null && $row['brand_id'] === null) {
                $row['errors'][] = ['field' => 'marca', 'message' => 'La marca no existe o no pertenece a la empresa activa.'];
            } elseif ($row['brand_id'] !== null && ! Brand::query()->whereKey($row['brand_id'])->where('company_id', $companyId)->where('is_active', true)->exists()) {
                $row['errors'][] = ['field' => 'marca', 'message' => 'La marca ya no está activa o no pertenece a la empresa activa.'];
            }

            $codeKey = Str::lower((string) $row['internal_code']);
            if (isset($seenCodes[$codeKey])) {
                $row['errors'][] = ['field' => 'codigo_interno', 'message' => 'El código se repite en la fila '.$seenCodes[$codeKey].'.'];
            } else {
                $seenCodes[$codeKey] = $row['row_number'];
            }
            if ($row['internal_code'] !== null && Product::withTrashed()->where('internal_code', $row['internal_code'])->exists()) {
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
                if (Product::withTrashed()->where('barcode', $barcode)->exists() || ProductBarcode::query()->where('barcode', $barcode)->exists()) {
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

    private function category(int $companyId, ?string $name): ?ProductCategory
    {
        return $name === null ? null : ProductCategory::query()->where('company_id', $companyId)->where('is_active', true)
            ->whereRaw('LOWER(name) = ?', [Str::lower($name)])->first();
    }

    private function brand(int $companyId, ?string $name): ?Brand
    {
        return $name === null ? null : Brand::query()->where('company_id', $companyId)->where('is_active', true)
            ->whereRaw('LOWER(name) = ?', [Str::lower($name)])->first();
    }

    private function unit(int $companyId, ?string $name): ?Unit
    {
        if ($name === null) {
            return null;
        }
        $normalized = Str::lower($name);

        return Unit::query()->where('company_id', $companyId)->where('is_active', true)
            ->where(fn ($query) => $query->whereRaw('LOWER(name) = ?', [$normalized])->orWhereRaw('LOWER(abbreviation) = ?', [$normalized]))->first();
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
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
        if (is_float($value)) {
            return rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
        }

        return $this->nullable($value);
    }
}
