<?php

namespace App\Services\Purchases;

use App\Data\Purchases\PurchaseLineData;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductCategory;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductResolver
{
    public function __construct(
        private readonly CompanyPurchaseSettingsResolver $settingsResolver,
    ) {
    }

    /**
     * Encuentra un producto de la empresa o crea uno cuando no existe.
     * Los datos de una línea nunca sobrescriben datos de un producto existente.
     */
    public function resolve(Company $company, PurchaseLineData $line): Product
    {
        if ($line->product_id !== null) {
            return Product::query()
                ->where('company_id', $company->id)
                ->where('is_active', true)
                ->findOrFail($line->product_id);
        }

        $product = $this->findExistingProduct($company, $line);

        if ($product !== null) {
            return $product;
        }

        return $this->createProduct($company, $line);
    }

    private function findExistingProduct(
        Company $company,
        PurchaseLineData $line,
    ): ?Product {
        if ($this->hasValue($line->code)) {
            $product = $this->findCompanyProduct(
                $company,
                'internal_code',
                $line->code,
            );

            if ($product !== null) {
                return $product;
            }
        }

        if ($this->hasValue($line->barcode)) {
            $product = $this->findCompanyProduct(
                $company,
                'barcode',
                $line->barcode,
            );

            if ($product !== null) {
                return $product;
            }

            $product = ProductBarcode::query()
                ->where('barcode', trim($line->barcode))
                ->where('is_active', true)
                ->whereHas('product', function ($query) use ($company) {
                    $query
                        ->where('company_id', $company->id)
                        ->where('is_active', true);
                })
                ->with('product')
                ->first()
                ?->product;

            if ($product !== null) {
                return $product;
            }
        }

        if ($this->hasValue($line->cabys)) {
            $product = $this->findCompanyProduct(
                $company,
                'cabys_code',
                $line->cabys,
            );

            if ($product !== null) {
                return $product;
            }
        }

        if ($this->hasValue($line->name)) {
            return Product::query()
                ->where('company_id', $company->id)
                ->where('is_active', true)
                ->where('name', trim($line->name))
                ->first();
        }

        return null;
    }

    private function createProduct(
        Company $company,
        PurchaseLineData $line,
    ): Product {
        if (!$this->hasValue($line->name)) {
            throw ValidationException::withMessages([
                'items' => 'No se puede crear un producto sin nombre.',
            ]);
        }

        $internalCode = $this->resolveInternalCode($company, $line->code);
        $barcode = $this->resolveBarcode($company, $line->barcode);
        $category = $this->resolveCategory($company, $line->category);
        $unit = $this->resolveUnit($company, $line->unit);
        $brand = $this->resolveBrand($company, $line->brand);

        $product = Product::create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'brand_id' => $brand?->id,
            'unit_id' => $unit->id,
            'name' => trim($line->name),
            'internal_code' => $internalCode,
            'barcode' => $barcode,
            'cabys_code' => $this->nullableValue($line->cabys),
            'cost' => $line->unit_cost ?? 0,
            'sale_price' => 0,
            'tax_rate' => $line->tax_rate ?? 13,
            'is_active' => true,
        ]);

        if ($barcode !== null) {
            ProductBarcode::create([
                'product_id' => $product->id,
                'barcode' => $barcode,
                'is_primary' => true,
                'is_active' => true,
            ]);
        }

        $this->createAvailabilityInActiveBranches($company, $product);

        return $product;
    }

    private function findCompanyProduct(
        Company $company,
        string $column,
        ?string $value,
    ): ?Product {
        return Product::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->where($column, trim((string) $value))
            ->first();
    }

    private function resolveCategory(
        Company $company,
        ?string $categoryName,
    ): ProductCategory {
        if ($this->hasValue($categoryName)) {
            $category = ProductCategory::query()
                ->where('company_id', $company->id)
                ->where('is_active', true)
                ->where('name', trim($categoryName))
                ->first();

            if ($category !== null) {
                return $category;
            }

            return ProductCategory::create([
                'company_id' => $company->id,
                'name' => trim($categoryName),
                'slug' => $this->uniqueCategorySlug($company, $categoryName),
                'is_active' => true,
            ]);
        }

        $categoryId = $this->settingsResolver
            ->resolveCategoryId($company, null);

        return ProductCategory::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->findOrFail($categoryId);
    }

    private function resolveUnit(
        Company $company,
        ?string $unitName,
    ): Unit {
        if ($this->hasValue($unitName)) {
            $unit = Unit::query()
                ->where('company_id', $company->id)
                ->where('is_active', true)
                ->where(function ($query) use ($unitName) {
                    $query
                        ->where('name', trim($unitName))
                        ->orWhere('abbreviation', trim($unitName));
                })
                ->first();

            if ($unit !== null) {
                return $unit;
            }

            throw ValidationException::withMessages([
                'items' => 'La unidad indicada no existe en la empresa.',
            ]);
        }

        $unitId = $this->settingsResolver->resolveUnitId($company, null);

        return Unit::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->findOrFail($unitId);
    }

    private function resolveBrand(
        Company $company,
        ?string $brandName,
    ): ?Brand {
        if (!$this->hasValue($brandName)) {
            return null;
        }

        $brand = Brand::withTrashed()
            ->where('company_id', $company->id)
            ->where('name', trim($brandName))
            ->first();

        if ($brand !== null) {
            if ($brand->trashed()) {
                $brand->restore();
            }

            return $brand;
        }

        return Brand::create([
            'company_id' => $company->id,
            'name' => trim($brandName),
            'is_active' => true,
        ]);
    }

    private function resolveInternalCode(
        Company $company,
        ?string $sourceCode,
    ): string {
        if ($this->hasValue($sourceCode)) {
            $code = trim($sourceCode);

            $conflictingProduct = Product::withTrashed()
                ->where('internal_code', $code)
                ->where('company_id', '!=', $company->id)
                ->exists();

            if ($conflictingProduct) {
                throw ValidationException::withMessages([
                    'items' => 'El código interno ya pertenece a otra empresa.',
                ]);
            }

            return $code;
        }

        do {
            $code = 'AUTO-' . $company->id . '-' . Str::upper(Str::random(12));
        } while (Product::withTrashed()
            ->where('internal_code', $code)
            ->exists());

        return $code;
    }

    private function resolveBarcode(
        Company $company,
        ?string $sourceBarcode,
    ): ?string {
        if (!$this->hasValue($sourceBarcode)) {
            return null;
        }

        $barcode = trim($sourceBarcode);

        $conflictingProduct = Product::withTrashed()
            ->where('barcode', $barcode)
            ->where('company_id', '!=', $company->id)
            ->exists();

        $conflictingProductBarcode = ProductBarcode::query()
            ->where('barcode', $barcode)
            ->whereHas('product', function ($query) use ($company) {
                $query->where('company_id', '!=', $company->id);
            })
            ->exists();

        if ($conflictingProduct || $conflictingProductBarcode) {
            throw ValidationException::withMessages([
                'items' => 'El código de barras ya pertenece a otra empresa.',
            ]);
        }

        return $barcode;
    }

    private function createAvailabilityInActiveBranches(
        Company $company,
        Product $product,
    ): void {
        $timestamp = now();

        $rows = Branch::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn (int $branchId) => [
                'branch_id' => $branchId,
                'product_id' => $product->id,
                'stock' => 0,
                'minimum_stock' => null,
                'maximum_stock' => null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ])
            ->all();

        if ($rows !== []) {
            DB::table('branch_product')->insertOrIgnore($rows);
        }
    }

    private function uniqueCategorySlug(
        Company $company,
        string $categoryName,
    ): string {
        $base = Str::slug($categoryName) ?: 'categoria';

        do {
            $slug = $base . '-' . $company->id . '-' . Str::lower(Str::random(6));
        } while (ProductCategory::withTrashed()
            ->where('slug', $slug)
            ->exists());

        return $slug;
    }

    private function hasValue(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }

    private function nullableValue(?string $value): ?string
    {
        return $this->hasValue($value) ? trim($value) : null;
    }
}
