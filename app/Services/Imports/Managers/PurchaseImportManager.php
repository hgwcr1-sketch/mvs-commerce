<?php

namespace App\Services\Imports\Managers;

use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductCategory;
use App\Models\Supplier;

class PurchaseImportManager
{
    public function validateProducts(array $items, int $companyId): array
    {
        $result = [
            'found' => [],
            'missing' => [],
            'catalog_pending' => ['categories' => []],
        ];

        foreach ($items as $item) {
            $item['category_resolution'] = $this->resolveCategoryPath(
                $item['category'] ?? null,
                $companyId,
            );

            $product = $this->findProduct($item, $companyId);

            if ($product !== null) {
                $result['found'][] = array_merge($item, [
                    'product_id' => $product->id,
                    'product' => $product->name,
                ]);
                continue;
            }

            $possible = collect();
            if (($item['name'] ?? null) !== null) {
                $possible = Product::query()
                    ->where('company_id', $companyId)
                    ->where('is_active', true)
                    ->where('name', 'like', '%' . trim($item['name']) . '%')
                    ->limit(5)
                    ->get(['id', 'name', 'internal_code', 'barcode', 'sale_price']);
            }

            $result['missing'][] = array_merge($item, [
                'possible_matches' => $possible,
            ]);

            if (in_array($item['category_resolution']['status'], ['missing', 'invalid'], true)) {
                $result['catalog_pending']['categories'][] = [
                    '_row_key' => $item['_row_key'],
                    'product' => $item['name'] ?? null,
                    'path' => $item['category'] ?? null,
                    'status' => $item['category_resolution']['status'],
                    'missing_segments' => $item['category_resolution']['missing_segments'],
                ];
            }
        }

        return $result;
    }

    private function findProduct(array $item, int $companyId): ?Product
    {
        foreach ([
            'code' => 'internal_code',
            'barcode' => 'barcode',
        ] as $field => $column) {
            if ($this->hasValue($item[$field] ?? null)) {
                $product = $this->findCompanyProduct($companyId, $column, $item[$field]);
                if ($product !== null) {
                    return $product;
                }
            }
        }

        if ($this->hasValue($item['barcode'] ?? null)) {
            $product = ProductBarcode::query()
                ->where('barcode', trim($item['barcode']))
                ->where('is_active', true)
                ->whereHas('product', fn ($query) => $query
                    ->where('company_id', $companyId)
                    ->where('is_active', true))
                ->with('product')
                ->first()?->product;

            if ($product !== null) {
                return $product;
            }
        }

        foreach ([
            'cabys' => 'cabys_code',
            'name' => 'name',
        ] as $field => $column) {
            if ($this->hasValue($item[$field] ?? null)) {
                $product = $this->findCompanyProduct($companyId, $column, $item[$field]);
                if ($product !== null) {
                    return $product;
                }
            }
        }

        return null;
    }

    private function findCompanyProduct(int $companyId, string $column, string $value): ?Product
    {
        return Product::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where($column, trim($value))
            ->first();
    }

    private function resolveCategoryPath(?string $categoryPath, int $companyId): array
    {
        if (!$this->hasValue($categoryPath)) {
            return $this->categoryResolution('empty');
        }

        $segments = array_map('trim', explode('>', $categoryPath));
        if (count($segments) > 2 || in_array('', $segments, true)) {
            return $this->categoryResolution('invalid', null, null, $segments, $segments);
        }

        $parent = ProductCategory::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereNull('parent_id')
            ->where('name', $segments[0])
            ->first();

        if ($parent === null) {
            return $this->categoryResolution('missing', null, null, $segments, $segments);
        }

        if (count($segments) === 1) {
            return $this->categoryResolution('found', $parent->id, null, $segments);
        }

        $child = ProductCategory::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('parent_id', $parent->id)
            ->where('name', $segments[1])
            ->first();

        return $child === null
            ? $this->categoryResolution('missing', null, $parent->id, $segments, [$segments[1]])
            : $this->categoryResolution('found', $child->id, $parent->id, $segments);
    }

    private function categoryResolution(
        string $status,
        ?int $categoryId = null,
        ?int $parentId = null,
        array $segments = [],
        array $missingSegments = [],
    ): array {
        return [
            'status' => $status,
            'category_id' => $categoryId,
            'parent_id' => $parentId,
            'segments' => $segments,
            'missing_segments' => $missingSegments,
        ];
    }

    public function supplierSummary(array $items): array
    {
        $names = collect($items)
            ->map(fn (array $item) => $this->hasValue($item['supplier'] ?? null)
                ? trim($item['supplier'])
                : null)
            ->uniqueStrict()
            ->values();

        return [
            'multiple' => $names->count() > 1,
            'names' => $names->all(),
            'name' => $names->count() === 1 ? $names->first() : null,
        ];
    }

    public function findSupplier(string $identification, int $companyId): ?Supplier
    {
        return Supplier::query()
            ->where('company_id', $companyId)
            ->where('identification', $identification)
            ->first();
    }

    public function validateSupplier(?string $supplierName, int $companyId): array
    {
        $supplier = null;
        if ($this->hasValue($supplierName)) {
            $supplier = Supplier::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->where(fn ($query) => $query
                    ->where('name', trim($supplierName))
                    ->orWhere('commercial_name', trim($supplierName)))
                ->first();
        }

        return $supplier !== null
            ? ['found' => true, 'id' => $supplier->id, 'name' => $supplier->name]
            : ['found' => false, 'name' => $supplierName];
    }

    private function hasValue(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }
}
