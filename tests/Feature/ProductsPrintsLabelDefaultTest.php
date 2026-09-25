<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductsPrintsLabelDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_column_default_makes_new_products_print_label_true(): void
    {
        [$categoryId, $unitId] = $this->catalog();

        $id = DB::table('products')->insertGetId([
            'category_id' => $categoryId,
            'unit_id' => $unitId,
            'name' => 'Producto por defecto',
            'internal_code' => 'DEF-'.uniqid(),
            'is_active' => true,
        ]);

        $this->assertTrue((bool) DB::table('products')->where('id', $id)->value('prints_label'));
    }

    public function test_product_created_without_prints_label_attribute_is_born_enabled(): void
    {
        $product = $this->product();

        $this->assertTrue((bool) $product->fresh()->prints_label);
    }

    public function test_existing_products_are_backfilled_and_migration_is_idempotent(): void
    {
        $product = $this->product(['prints_label' => false]);
        $this->assertFalse((bool) $product->fresh()->prints_label);

        $migration = require database_path('migrations/2026_09_25_000001_set_prints_label_default_true.php');
        $migration->up();
        $migration->up();

        $this->assertTrue((bool) $product->fresh()->prints_label);
        $this->assertSame(0, DB::table('products')
            ->where('prints_label', false)
            ->orWhereNull('prints_label')
            ->count());
    }

    public function test_manual_option_to_keep_print_label_disabled_still_works(): void
    {
        $product = $this->product();

        $product->update(['prints_label' => false]);

        $this->assertFalse((bool) $product->fresh()->prints_label);
    }

    private function catalog(): array
    {
        $id = uniqid();
        $category = ProductCategory::create(['name' => 'Categoria '.$id, 'slug' => 'cat-'.$id, 'is_active' => true]);
        $unit = Unit::create(['name' => 'Unidad '.$id, 'abbreviation' => 'U', 'slug' => 'u-'.$id, 'is_active' => true]);

        return [$category->id, $unit->id];
    }

    private function product(array $attributes = []): Product
    {
        [$categoryId, $unitId] = $this->catalog();

        return Product::create(array_merge([
            'category_id' => $categoryId,
            'unit_id' => $unitId,
            'name' => 'Producto '.uniqid(),
            'internal_code' => 'P-'.uniqid(),
            'cost' => 100,
            'sale_price' => 200,
            'tax_rate' => 13,
            'is_active' => true,
        ], $attributes));
    }
}
