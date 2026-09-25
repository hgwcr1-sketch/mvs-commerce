<?php

namespace App\Services\Imports;

use App\Models\Brand;
use App\Models\Color;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductCategory;
use App\Models\Size;
use App\Models\Style;
use App\Models\Unit;
use App\Services\Fiscal\FiscalTaxService;
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

    private array $styleCache = [];

    private array $sizeCache = [];

    private array $colorCache = [];

    public function __construct(
        private readonly FiscalTaxService $fiscalTaxService,
    ) {}

    public const HEADERS = [
        'codigo_interno*', 'nombre*', 'categoria*', 'subcategoria_subrubro', 'marca', 'unidad*', 'tipo_producto*',
        'estilo', 'talla', 'color',
        'codigo_barras_principal', 'codigos_barras_adicionales', 'cabys', 'descripcion_corta',
        'descripcion', 'costo*', 'precio_venta*', 'precio_mayorista', 'precio_especial',
        'precio_a', 'precio_b', 'precio_c', 'impuesto*', 'controla_inventario',
        'permite_stock_negativo', 'imprime_etiqueta', 'activo',
        'codigo_impuesto', 'codigo_tarifa', 'perfil_fiscal',
    ];

    private const HEADER_MAP = [
        'codigo_interno' => 'internal_code', 'codigo' => 'internal_code', 'nombre' => 'name',
        'categoria' => 'category', 'subcategoria_subrubro' => 'subcategory', 'subcategoria' => 'subcategory',
        'subrubro' => 'subcategory', 'marca' => 'brand', 'unidad' => 'unit', 'tipo_producto' => 'product_type',
        'tipo' => 'product_type', 'estilo' => 'style', 'talla' => 'size', 'color' => 'color',
        'codigo_barras_principal' => 'barcode', 'codigo_de_barras_principal' => 'barcode',
        'codigo_barras' => 'barcode', 'codigos_barras_adicionales' => 'additional_barcodes',
        'codigos_de_barras_adicionales' => 'additional_barcodes', 'cabys' => 'cabys_code',
        'descripcion_corta' => 'short_description', 'descripcion' => 'description', 'costo' => 'cost',
        'precio_venta' => 'sale_price', 'precio_de_venta' => 'sale_price', 'precio_mayorista' => 'wholesale_price',
        'precio_especial' => 'special_price', 'precio_a' => 'price_a', 'precio_b' => 'price_b',
        'precio_c' => 'price_c',         'impuesto' => 'tax_rate', 'impuesto_%' => 'tax_rate',
        'codigo_impuesto' => 'tax_code', 'codigo_de_impuesto' => 'tax_code',
        'codigo_tarifa' => 'tax_rate_code', 'codigo_de_tarifa' => 'tax_rate_code',
        'perfil_fiscal' => 'fiscal_profile_id',
        'controla_inventario' => 'track_inventory', 'permite_stock_negativo' => 'allow_negative_stock',
        'imprime_etiqueta' => 'prints_label', 'activo' => 'is_active',
    ];

    private const FIELD_LABELS = [
        'internal_code' => 'codigo_interno', 'name' => 'nombre', 'category_name' => 'categoria',
        'subcategory_name' => 'subcategoria_subrubro', 'brand_name' => 'marca', 'unit_name' => 'unidad',
        'product_type' => 'tipo_producto', 'style_name' => 'estilo', 'size_name' => 'talla',
        'color_name' => 'color',
        'barcode' => 'codigo_barras_principal', 'additional_barcodes' => 'codigos_barras_adicionales',
        'cabys_code' => 'cabys', 'short_description' => 'descripcion_corta', 'description' => 'descripcion',
        'cost' => 'costo', 'sale_price' => 'precio_venta', 'wholesale_price' => 'precio_mayorista',
        'special_price' => 'precio_especial', 'price_a' => 'precio_a', 'price_b' => 'precio_b',
        'price_c' => 'precio_c', 'tax_rate' => 'impuesto', 'track_inventory' => 'controla_inventario',
        'allow_negative_stock' => 'permite_stock_negativo', 'prints_label' => 'imprime_etiqueta',
        'is_active' => 'activo',
        'tax_code' => 'codigo_impuesto', 'tax_rate_code' => 'codigo_tarifa',
        'fiscal_profile_id' => 'perfil_fiscal',
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
        $subcategoryName = $this->catalogName($data['subcategory'] ?? null);
        $brandName = $this->catalogName($data['brand'] ?? null);
        $unitName = $this->catalogName($data['unit'] ?? null);
        $styleName = $this->catalogName($data['style'] ?? null);
        $sizeName = $this->catalogName($data['size'] ?? null);
        $colorName = $this->catalogName($data['color'] ?? null);
        $category = $this->category($companyId, $categoryName);
        $subcategory = $this->subcategory($companyId, $category, $subcategoryName);
        $brand = $this->brand($companyId, $brandName);
        $unit = $this->unit($companyId, $unitName);
        $style = $this->style($companyId, $styleName);
        $size = $this->size($companyId, $sizeName);
        $color = $this->color($companyId, $colorName);
        $primary = $this->nullable($data['barcode'] ?? null);
        $additional = collect(preg_split('/\s*\|\s*/', $this->nullable($data['additional_barcodes'] ?? null) ?? ''))
            ->map(fn ($barcode) => trim((string) $barcode))->filter()->unique()->values()->all();
        $barcodes = array_values(array_unique(array_filter([$primary, ...$additional])));

        $effectiveCategory = $subcategory ?? $category;

        return [
            'row_number' => $rowNumber,
            'internal_code' => $this->nullable($data['internal_code'] ?? null),
            'name' => $this->nullable($data['name'] ?? null),
            'category_name' => $categoryName,
            'subcategory_name' => $subcategoryName,
            'category_id' => $effectiveCategory?->id,
            'category_will_create' => $categoryName !== null && $category === null,
            'brand_name' => $brandName,
            'brand_id' => $brand?->id,
            'brand_will_create' => $brandName !== null && $brand === null,
            'unit_name' => $unitName,
            'unit_id' => $unit?->id,
            'unit_will_create' => $unitName !== null && $unit === null,
            'style_name' => $styleName,
            'style_id' => $style?->id,
            'style_will_create' => $styleName !== null && $style === null,
            'size_name' => $sizeName,
            'size_id' => $size?->id,
            'size_will_create' => $sizeName !== null && $size === null,
            'color_name' => $colorName,
            'color_id' => $color?->id,
            'color_will_create' => $colorName !== null && $color === null,
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
            'tax_code' => $this->nullable($data['tax_code'] ?? null),
            'tax_rate_code' => $this->nullable($data['tax_rate_code'] ?? null),
            'fiscal_profile_id' => $this->nullable($data['fiscal_profile_id'] ?? null),
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
            $hasExplicitFiscal = ($row['fiscal_profile_id'] ?? null) !== null
                || (($row['tax_code'] ?? null) !== null && ($row['tax_rate_code'] ?? null) !== null);
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
                'tax_rate' => $hasExplicitFiscal ? $optionalMoney : [...$requiredMoney, 'lte:100'], 'track_inventory' => ['required', 'boolean'],
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

            // Normalización fiscal única (FiscalTaxService): perfil explícito >
            // códigos explícitos > tasa inequívoca 1/2/4/13. 0/8/NULL bloquean
            // la fila; CABYS jamás determina el tratamiento.
            try {
                $fiscal = $this->fiscalTaxService->normalizeProductFiscalAttributes([
                    'fiscal_profile_id' => $row['fiscal_profile_id'] ?? null,
                    'tax_code' => $row['tax_code'] ?? null,
                    'tax_rate_code' => $row['tax_rate_code'] ?? null,
                    'tax_rate' => $row['tax_rate'] ?? null,
                ]);
                $row['fiscal_profile_id'] = $fiscal['fiscal_profile_id'];
                $row['tax_rate'] = $fiscal['tax_rate'];
            } catch (\InvalidArgumentException $exception) {
                $row['errors'][] = ['field' => 'impuesto', 'message' => $exception->getMessage()];
            }

            $row['valid'] = $row['errors'] === [];
            $rows[$index] = $row;
        }

        return $rows;
    }

    private function attributes(array $row): array
    {
        return Arr::only($row, [
            'category_id', 'brand_id', 'unit_id', 'style_id', 'size_id', 'color_id',
            'name', 'internal_code', 'barcode', 'product_type',
            'cabys_code', 'short_description', 'description', 'cost', 'sale_price', 'wholesale_price',
            'special_price', 'price_a', 'price_b', 'price_c', 'track_inventory', 'allow_negative_stock',
            'tax_rate', 'fiscal_profile_id', 'is_active', 'prints_label',
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

        $subcategory = null;
        if (! empty($row['subcategory_name'])) {
            $subcategory = $this->subcategory($companyId, $category, $row['subcategory_name'])
                ?? $this->rememberCategory(ProductCategory::create([
                    'company_id' => $companyId,
                    'parent_id' => $category->id,
                    'name' => $row['subcategory_name'],
                    'slug' => $this->uniqueSlug(ProductCategory::class, $row['subcategory_name'], $companyId),
                    'is_active' => true,
                ]));
        }

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

        $style = $row['style_name'] === null
            ? null
            : ($this->style($companyId, $row['style_name']) ?? $this->rememberStyle(Style::create([
                'company_id' => $companyId,
                'name' => $row['style_name'],
                'slug' => $this->uniqueSlug(Style::class, $row['style_name'], $companyId),
                'is_active' => true,
            ])));

        $size = $row['size_name'] === null
            ? null
            : ($this->size($companyId, $row['size_name']) ?? $this->rememberSize(Size::create([
                'company_id' => $companyId,
                'name' => $row['size_name'],
                'slug' => $this->uniqueSlug(Size::class, $row['size_name'], $companyId),
                'is_active' => true,
            ])));

        $color = $row['color_name'] === null
            ? null
            : ($this->color($companyId, $row['color_name']) ?? $this->rememberColor(Color::create([
                'company_id' => $companyId,
                'name' => $row['color_name'],
                'slug' => $this->uniqueSlug(Color::class, $row['color_name'], $companyId),
                'is_active' => true,
            ])));

        $effectiveCategory = $subcategory ?? $category;

        return [...$row,
            'category_id' => $effectiveCategory->id, 'category_will_create' => false,
            'unit_id' => $unit->id, 'unit_will_create' => false,
            'brand_id' => $brand?->id, 'brand_will_create' => false,
            'style_id' => $style?->id, 'style_will_create' => false,
            'size_id' => $size?->id, 'size_will_create' => false,
            'color_id' => $color?->id, 'color_will_create' => false,
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

    private function subcategory(int $companyId, ?ProductCategory $parent, ?string $name): ?ProductCategory
    {
        if ($name === null || $parent === null) {
            return null;
        }

        return ProductCategory::query()
            ->where('company_id', $companyId)
            ->where('parent_id', $parent->id)
            ->where('is_active', true)
            ->whereRaw('LOWER(name) = ?', [Str::lower($name)])
            ->first();
    }

    private function style(int $companyId, ?string $name): ?Style
    {
        if ($name === null) {
            return null;
        }
        $this->styleCache[$companyId] ??= Style::query()->where('company_id', $companyId)
            ->where('is_active', true)->get()->keyBy(fn (Style $s) => $this->catalogKey($s->name))->all();

        return $this->styleCache[$companyId][$this->catalogKey($name)] ?? null;
    }

    private function size(int $companyId, ?string $name): ?Size
    {
        if ($name === null) {
            return null;
        }
        $this->sizeCache[$companyId] ??= Size::query()->where('company_id', $companyId)
            ->where('is_active', true)->get()->keyBy(fn (Size $s) => $this->catalogKey($s->name))->all();

        return $this->sizeCache[$companyId][$this->catalogKey($name)] ?? null;
    }

    private function color(int $companyId, ?string $name): ?Color
    {
        if ($name === null) {
            return null;
        }
        $this->colorCache[$companyId] ??= Color::query()->where('company_id', $companyId)
            ->where('is_active', true)->get()->keyBy(fn (Color $c) => $this->catalogKey($c->name))->all();

        return $this->colorCache[$companyId][$this->catalogKey($name)] ?? null;
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

    private function rememberStyle(Style $style): Style
    {
        $this->styleCache[$style->company_id][$this->catalogKey($style->name)] = $style;

        return $style;
    }

    private function rememberSize(Size $size): Size
    {
        $this->sizeCache[$size->company_id][$this->catalogKey($size->name)] = $size;

        return $size;
    }

    private function rememberColor(Color $color): Color
    {
        $this->colorCache[$color->company_id][$this->catalogKey($color->name)] = $color;

        return $color;
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
