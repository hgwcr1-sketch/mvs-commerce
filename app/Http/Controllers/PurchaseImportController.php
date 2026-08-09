<?php

namespace App\Http\Controllers;

use App\Data\Purchases\PurchaseData;
use App\Data\Purchases\PurchaseLineData;
use App\Models\Brand;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductCategory;
use App\Models\Supplier;
use App\Models\Unit;
use App\Services\Imports\Managers\PurchaseImportManager;
use App\Services\Imports\PurchaseExcelImport;
use App\Services\Purchases\CompanyPurchaseSettingsResolver;
use App\Services\Purchases\PurchaseProcessor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class PurchaseImportController extends Controller
{
    public function downloadTemplate()
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Compras');
        $sheet->fromArray([
            'Código', 'Código Barra', 'Producto', 'Descripción', 'Categoría',
            'Marca', 'Proveedor', 'Unidad de medida', 'Tipo Artículo',
            'Cantidad', 'Costo', 'Precio Venta', 'Impuesto %', 'Descuento %',
            'CABYS', 'Mínimo Stock', 'Máximo Stock', 'Lote', 'Fecha Vencimiento',
        ], null, 'A1');

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(
            fn () => $writer->save('php://output'),
            'plantilla_importacion_compras.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    public function store(
        Request $request,
        PurchaseExcelImport $import,
        PurchaseImportManager $manager,
    ) {
        $companyId = (int) session('active_company_id');
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls']]);

        $rows = $import->read($request->file('file')->getRealPath());
        if ($rows === []) {
            return back()->withErrors(['file' => 'El archivo no contiene filas de compra.']);
        }

        $validation = $manager->validateProducts($rows, $companyId);
        $validation['supplier_summary'] = $manager->supplierSummary($rows);
        $validation['supplier'] = $manager->validateSupplier(
            $validation['supplier_summary']['name'],
            $companyId,
        );

        session(['purchase_import_validation' => $validation]);

        return redirect()->route('compras.import.review');
    }

    public function review()
    {
        $validation = session('purchase_import_validation');
        if (!$validation) {
            return redirect()->route('compras.index')
                ->with('error', 'No existe una importación pendiente.');
        }

        return view('compras.import-review', compact('validation'));
    }

    public function supplierCreated(Request $request)
    {
        $validation = session('purchase_import_validation');
        $request->validate(['id' => ['required', 'integer']]);

        $supplier = Supplier::query()
            ->where('company_id', session('active_company_id'))
            ->where('is_active', true)
            ->findOrFail($request->integer('id'));

        $validation['supplier'] = [
            'found' => true,
            'id' => $supplier->id,
            'name' => $supplier->name,
        ];
        session(['purchase_import_validation' => $validation]);

        return response()->json(['success' => true]);
    }

    public function confirm(PurchaseProcessor $processor)
    {
        $validation = session('purchase_import_validation');
        if (!$validation) {
            return redirect()->route('compras.index')
                ->with('error', 'No existe importación pendiente.');
        }

        if (!empty($validation['missing'])) {
            return redirect()->route('compras.import.review')
                ->with('error', 'Debe resolver todos los productos pendientes antes de confirmar.');
        }

        if ($validation['supplier_summary']['multiple'] ?? false) {
            return redirect()->route('compras.import.review')->with(
                'error',
                'El archivo contiene proveedores diferentes. Debe separarlo en archivos distintos.',
            );
        }

        if (empty($validation['supplier']['found'])) {
            return back()->with('error', 'Debe crear el proveedor antes de confirmar.');
        }

        $lines = array_map(fn (array $item) => new PurchaseLineData(
            product_id: (int) $item['product_id'],
            code: $item['code'] ?? null,
            name: $item['name'] ?? null,
            barcode: $item['barcode'] ?? null,
            cabys: $item['cabys'] ?? null,
            brand: $item['brand'] ?? null,
            category: $item['category'] ?? null,
            unit: $item['unit'] ?? null,
            quantity: $this->nullableFloat($item['quantity'] ?? null),
            unit_cost: $this->nullableFloat($item['cost'] ?? null),
            new_sale_price: $this->nullableFloat($item['new_sale_price'] ?? null),
            tax_rate: $this->nullableFloat($item['tax_rate'] ?? null),
            discount_percent: $this->nullableFloat($item['discount_percent'] ?? null),
            lot_number: $item['lot_number'] ?? null,
            expires_at: $item['expires_at'] ?? null,
        ), $validation['found']);

        $purchase = $processor->process(new PurchaseData(
            company_id: (int) session('active_company_id'),
            branch_id: (int) session('active_branch_id'),
            supplier_id: (int) $validation['supplier']['id'],
            user_id: Auth::id(),
            purchase_date: now()->toDateString(),
            payment_type: 'cash',
            notes: 'Compra importada desde Excel',
            lines: $lines,
        ));

        session()->forget('purchase_import_validation');

        return redirect()->route('compras.show', $purchase->id)
            ->with('success', 'Compra importada correctamente.');
    }

    public function createProduct(Request $request)
    {
        $validation = session('purchase_import_validation');
        $sourceItem = collect($validation['missing'] ?? [])->first(
            fn (array $item) => ($item['_row_key'] ?? null) === $request->query('row_key'),
        );

        if ($sourceItem === null) {
            return redirect()->route('compras.import.review')
                ->with('error', 'La fila pendiente ya no existe.');
        }

        return view('compras.product-create-import', [
            'rowKey' => $sourceItem['_row_key'],
            'sourceItem' => $sourceItem,
            'code' => $sourceItem['code'] ?? null,
            'name' => $sourceItem['name'] ?? null,
            'cost' => $sourceItem['cost'] ?? null,
        ]);
    }

    public function storeProduct(Request $request)
    {
        $companyId = (int) session('active_company_id');
        $branchId = Branch::query()
            ->whereKey(session('active_branch_id'))
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->value('id');
        abort_unless($branchId, 403, 'No tiene una sucursal activa válida.');

        $request->validate(['row_key' => ['required', 'string']]);
        $validation = session('purchase_import_validation');
        $missingKey = collect($validation['missing'] ?? [])->search(
            fn (array $item) => ($item['_row_key'] ?? null) === $request->row_key,
        );
        abort_if($missingKey === false, 404);
        $sourceItem = $validation['missing'][$missingKey];

        if ($request->filled('existing_product_id')) {
            $product = Product::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->findOrFail($request->integer('existing_product_id'));
            $this->moveImportRowToFound($validation, $missingKey, $sourceItem, $product);

            return redirect()->route('compras.import.review')
                ->with('success', 'Producto asignado correctamente.');
        }

        $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:50'],
            'cost' => ['required', 'numeric', 'min:0'],
        ]);

        if (($sourceItem['category_resolution']['status'] ?? null) === 'invalid') {
            throw ValidationException::withMessages([
                'category' => 'Use el formato “Categoría” o “Categoría > Subcategoría”.',
            ]);
        }

        DB::transaction(function () use (
            $request, $sourceItem, $validation, $missingKey, $companyId, $branchId,
        ) {
            $categoryId = ($sourceItem['category_resolution']['status'] ?? null) === 'found'
                ? (int) $sourceItem['category_resolution']['category_id']
                : $this->resolveImportCategoryId($sourceItem['category'] ?? null, $companyId);
            $brandId = $this->resolveImportBrandId($sourceItem['brand'] ?? null, $companyId);
            $unitId = $this->resolveImportUnitId($sourceItem['unit'] ?? null, $companyId);
            $productType = $this->resolveProductType($sourceItem['product_type'] ?? null);

            $attributes = [
                'company_id' => $companyId,
                'category_id' => $categoryId,
                'brand_id' => $brandId,
                'unit_id' => $unitId,
                'name' => trim($request->name),
                'internal_code' => trim($request->code),
                'cost' => (float) $request->cost,
                'is_active' => true,
            ];

            foreach ([
                'barcode' => 'barcode', 'cabys' => 'cabys_code',
                'description' => 'description', 'tax_rate' => 'tax_rate',
                'new_sale_price' => 'sale_price',
            ] as $source => $target) {
                if (($sourceItem[$source] ?? null) !== null) {
                    $attributes[$target] = $sourceItem[$source];
                }
            }
            if ($productType !== null) {
                $attributes['product_type'] = $productType;
            }

            $product = Product::create($attributes);
            DB::table('branch_product')->insert([
                'branch_id' => $branchId,
                'product_id' => $product->id,
                'stock' => 0,
                'minimum_stock' => $sourceItem['minimum_stock'] ?? null,
                'maximum_stock' => $sourceItem['maximum_stock'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (($sourceItem['barcode'] ?? null) !== null) {
                ProductBarcode::create([
                    'product_id' => $product->id,
                    'barcode' => trim($sourceItem['barcode']),
                    'barcode_type' => 'supplier',
                    'is_primary' => true,
                    'is_active' => true,
                ]);
            }

            $this->moveImportRowToFound($validation, $missingKey, $sourceItem, $product);
        });

        return redirect()->route('compras.import.review')
            ->with('success', 'Producto creado correctamente.');
    }

    private function resolveImportCategoryId(?string $path, int $companyId): int
    {
        if (!$this->hasValue($path)) {
            return $this->defaultCategoryId($companyId);
        }

        $segments = array_map('trim', explode('>', $path));
        $parentId = null;
        $category = null;
        foreach ($segments as $segment) {
            $category = ProductCategory::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->where('parent_id', $parentId)
                ->where('name', $segment)
                ->first();

            if ($category === null) {
                $category = ProductCategory::create([
                    'company_id' => $companyId,
                    'parent_id' => $parentId,
                    'name' => $segment,
                    'slug' => $this->uniqueCategorySlug($segment, $companyId),
                    'is_active' => true,
                ]);
            }
            $parentId = $category->id;
        }

        return $category->id;
    }

    private function resolveImportBrandId(?string $name, int $companyId): ?int
    {
        if (!$this->hasValue($name)) {
            return null;
        }

        $brand = Brand::withTrashed()
            ->where('company_id', $companyId)
            ->where('name', trim($name))
            ->first();
        if ($brand === null) {
            return Brand::create([
                'company_id' => $companyId,
                'name' => trim($name),
                'is_active' => true,
            ])->id;
        }
        if ($brand->trashed()) {
            $brand->restore();
        }
        if (!$brand->is_active) {
            $brand->update(['is_active' => true]);
        }

        return $brand->id;
    }

    private function resolveImportUnitId(?string $name, int $companyId): int
    {
        if (!$this->hasValue($name)) {
            $company = Company::query()->where('is_active', true)->findOrFail($companyId);
            $unitId = app(CompanyPurchaseSettingsResolver::class)->resolveUnitId($company, null);
        } else {
            $value = trim($name);
            $unitId = Unit::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->where(fn ($query) => $query->where('name', $value)->orWhere('abbreviation', $value))
                ->value('id');
        }

        $unitId = Unit::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereKey($unitId)
            ->value('id');
        if ($unitId === null) {
            throw ValidationException::withMessages([
                'unit' => $this->hasValue($name)
                    ? 'La unidad indicada en el Excel no existe en la empresa.'
                    : 'La empresa no tiene una unidad predeterminada válida.',
            ]);
        }

        return $unitId;
    }

    private function defaultCategoryId(int $companyId): int
    {
        $company = Company::query()->where('is_active', true)->findOrFail($companyId);
        $categoryId = app(CompanyPurchaseSettingsResolver::class)->resolveCategoryId($company, null);
        $categoryId = ProductCategory::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereKey($categoryId)
            ->value('id');
        if ($categoryId === null) {
            throw ValidationException::withMessages([
                'category' => 'La empresa no tiene una categoría predeterminada válida.',
            ]);
        }

        return $categoryId;
    }

    private function resolveProductType(?string $type): ?string
    {
        if (!$this->hasValue($type)) {
            return null;
        }
        $normalized = Str::of($type)->ascii()->lower()->trim()->toString();
        $resolved = ['producto' => 'product', 'product' => 'product', 'servicio' => 'service',
            'service' => 'service', 'combo' => 'combo'][$normalized] ?? null;
        if ($resolved === null) {
            throw ValidationException::withMessages([
                'product_type' => 'Tipo Artículo debe ser Producto, Servicio o Combo.',
            ]);
        }

        return $resolved;
    }

    private function uniqueCategorySlug(string $name, int $companyId): string
    {
        do {
            $slug = (Str::slug($name) ?: 'categoria') . '-' . $companyId . '-' . Str::lower(Str::random(6));
        } while (ProductCategory::withTrashed()->where('slug', $slug)->exists());

        return $slug;
    }

    private function moveImportRowToFound(
        array $validation,
        int $missingKey,
        array $sourceItem,
        Product $product,
    ): void {
        $validation['found'][] = array_merge($sourceItem, [
            'product_id' => $product->id,
            'product' => $product->name,
        ]);
        unset($validation['missing'][$missingKey]);
        $validation['missing'] = array_values($validation['missing']);
        session(['purchase_import_validation' => $validation]);
    }

    private function nullableFloat(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }

    private function hasValue(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }
}
