<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Color;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Size;
use App\Models\Style;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductStyleSizeColorTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;
    private array $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'trade_name' => 'Test Company ' . uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
        $this->user = User::factory()->create();
        $this->user->companies()->attach($this->company->id);

        $branch = $this->company->branches()->first()
            ?? $this->company->branches()->create(['name' => 'Principal', 'code' => 'P-' . $this->company->id, 'is_active' => true]);

        $this->session = [
            'active_company_id' => $this->company->id,
            'active_branch_id' => $branch->id,
        ];
    }

    // =========================================================
    // A. STYLE / SIZE / COLOR CRUD
    // =========================================================

    public function test_style_can_be_created(): void
    {
        $style = Style::create([
            'company_id' => $this->company->id,
            'name' => 'Clásico',
            'slug' => 'clasico-' . $this->company->id,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('styles', [
            'company_id' => $this->company->id,
            'name' => 'Clásico',
        ]);
        $this->assertTrue($style->is_active);
    }

    public function test_size_can_be_created(): void
    {
        $size = Size::create([
            'company_id' => $this->company->id,
            'name' => 'Mediana',
            'abbreviation' => 'M',
            'slug' => 'mediana-' . $this->company->id,
            'sort_order' => 2,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('sizes', [
            'company_id' => $this->company->id,
            'name' => 'Mediana',
            'abbreviation' => 'M',
        ]);
        $this->assertSame(2, $size->sort_order);
    }

    public function test_color_can_be_created(): void
    {
        $color = Color::create([
            'company_id' => $this->company->id,
            'name' => 'Negro',
            'hex_code' => '#000000',
            'slug' => 'negro-' . $this->company->id,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('colors', [
            'company_id' => $this->company->id,
            'name' => 'Negro',
            'hex_code' => '#000000',
        ]);
    }

    // =========================================================
    // B. MULTIEMPRESA AISLAMIENTO
    // =========================================================

    public function test_style_is_isolated_by_company(): void
    {
        $otherCompany = Company::create([
            'trade_name' => 'Other ' . uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);

        Style::create([
            'company_id' => $this->company->id,
            'name' => 'Deportivo',
            'slug' => 'deportivo-' . $this->company->id,
            'is_active' => true,
        ]);

        $this->assertDatabaseMissing('styles', [
            'company_id' => $otherCompany->id,
            'name' => 'Deportivo',
        ]);
    }

    public function test_size_is_isolated_by_company(): void
    {
        $otherCompany = Company::create([
            'trade_name' => 'Other ' . uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);

        Size::create([
            'company_id' => $this->company->id,
            'name' => 'Grande',
            'slug' => 'grande-' . $this->company->id,
            'is_active' => true,
        ]);

        $this->assertDatabaseMissing('sizes', [
            'company_id' => $otherCompany->id,
            'name' => 'Grande',
        ]);
    }

    public function test_color_is_isolated_by_company(): void
    {
        $otherCompany = Company::create([
            'trade_name' => 'Other ' . uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);

        Color::create([
            'company_id' => $this->company->id,
            'name' => 'Rojo',
            'slug' => 'rojo-' . $this->company->id,
            'is_active' => true,
        ]);

        $this->assertDatabaseMissing('colors', [
            'company_id' => $otherCompany->id,
            'name' => 'Rojo',
        ]);
    }

    // =========================================================
    // C. PRODUCT SAVES STYLE / SIZE / COLOR
    // =========================================================

    public function test_product_saves_style_size_color(): void
    {
        $category = ProductCategory::create([
            'company_id' => $this->company->id,
            'name' => 'General',
            'slug' => 'general-' . $this->company->id,
            'is_active' => true,
        ]);
        $unit = Unit::create([
            'company_id' => $this->company->id,
            'name' => 'Unidad',
            'abbreviation' => 'UN',
            'slug' => 'unidad-' . $this->company->id,
            'allows_decimals' => false,
            'is_active' => true,
        ]);
        $style = Style::create([
            'company_id' => $this->company->id,
            'name' => 'Casual',
            'slug' => 'casual-' . $this->company->id,
            'is_active' => true,
        ]);
        $size = Size::create([
            'company_id' => $this->company->id,
            'name' => 'Pequeña',
            'abbreviation' => 'S',
            'slug' => 'pequena-' . $this->company->id,
            'is_active' => true,
        ]);
        $color = Color::create([
            'company_id' => $this->company->id,
            'name' => 'Azul',
            'hex_code' => '#0000FF',
            'slug' => 'azul-' . $this->company->id,
            'is_active' => true,
        ]);

        $product = Product::create([
            'company_id' => $this->company->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'style_id' => $style->id,
            'size_id' => $size->id,
            'color_id' => $color->id,
            'name' => 'Polo Básico',
            'internal_code' => 'POLO-001',
            'product_type' => 'product',
            'cost' => 5000,
            'sale_price' => 8900,
            'tax_rate' => 13,
            'is_active' => true,
        ]);

        $this->assertSame($style->id, $product->style_id);
        $this->assertSame($size->id, $product->size_id);
        $this->assertSame($color->id, $product->color_id);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'style_id' => $style->id,
            'size_id' => $size->id,
            'color_id' => $color->id,
        ]);
    }

    public function test_product_style_size_color_are_nullable(): void
    {
        $category = ProductCategory::create([
            'company_id' => $this->company->id,
            'name' => 'General',
            'slug' => 'general2-' . $this->company->id,
            'is_active' => true,
        ]);
        $unit = Unit::create([
            'company_id' => $this->company->id,
            'name' => 'Unidad',
            'abbreviation' => 'UN',
            'slug' => 'unidad2-' . $this->company->id,
            'allows_decimals' => false,
            'is_active' => true,
        ]);

        $product = Product::create([
            'company_id' => $this->company->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'name' => 'Servicio General',
            'internal_code' => 'SRV-001',
            'product_type' => 'service',
            'cost' => 0,
            'sale_price' => 10000,
            'tax_rate' => 13,
            'is_active' => true,
        ]);

        $this->assertNull($product->style_id);
        $this->assertNull($product->size_id);
        $this->assertNull($product->color_id);
    }

    // =========================================================
    // D. SUBCATEGORY (ProductCategory.parent_id)
    // =========================================================

    public function test_subcategory_belongs_to_parent_category(): void
    {
        $parent = ProductCategory::create([
            'company_id' => $this->company->id,
            'name' => 'Ropa',
            'slug' => 'ropa-' . $this->company->id,
            'is_active' => true,
        ]);

        $child = ProductCategory::create([
            'company_id' => $this->company->id,
            'parent_id' => $parent->id,
            'name' => 'Camisas',
            'slug' => 'camisas-' . $this->company->id,
            'is_active' => true,
        ]);

        $this->assertSame($parent->id, $child->parent_id);
        $this->assertTrue($parent->children->contains($child));
    }

    public function test_subcategory_under_different_parent_not_confused(): void
    {
        $parent1 = ProductCategory::create([
            'company_id' => $this->company->id,
            'name' => 'Ropa',
            'slug' => 'ropa3-' . $this->company->id,
            'is_active' => true,
        ]);
        $parent2 = ProductCategory::create([
            'company_id' => $this->company->id,
            'name' => 'Calzado',
            'slug' => 'calzado-' . $this->company->id,
            'is_active' => true,
        ]);

        $child1 = ProductCategory::create([
            'company_id' => $this->company->id,
            'parent_id' => $parent1->id,
            'name' => 'Deportivo',
            'slug' => 'deportivo-sub-' . $this->company->id,
            'is_active' => true,
        ]);

        $child2 = ProductCategory::create([
            'company_id' => $this->company->id,
            'parent_id' => $parent2->id,
            'name' => 'Deportivo',
            'slug' => 'deportivo-sub2-' . $this->company->id,
            'is_active' => true,
        ]);

        $this->assertNotSame($child1->parent_id, $child2->parent_id);
        $this->assertSame($parent1->id, $child1->parent_id);
        $this->assertSame($parent2->id, $child2->parent_id);
    }

    // =========================================================
    // E. PRODUCT RELATIONSHIPS
    // =========================================================

    public function test_product_style_relationship(): void
    {
        $category = ProductCategory::create([
            'company_id' => $this->company->id,
            'name' => 'Test',
            'slug' => 'test-rel-' . $this->company->id,
            'is_active' => true,
        ]);
        $unit = Unit::create([
            'company_id' => $this->company->id,
            'name' => 'Un',
            'abbreviation' => 'UN',
            'slug' => 'un-rel-' . $this->company->id,
            'allows_decimals' => false,
            'is_active' => true,
        ]);
        $style = Style::create([
            'company_id' => $this->company->id,
            'name' => 'Vintage',
            'slug' => 'vintage-' . $this->company->id,
            'is_active' => true,
        ]);

        $product = Product::create([
            'company_id' => $this->company->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'style_id' => $style->id,
            'name' => 'Test Product',
            'internal_code' => 'TST-001',
            'product_type' => 'product',
            'cost' => 0,
            'sale_price' => 0,
            'tax_rate' => 13,
            'is_active' => true,
        ]);

        $this->assertInstanceOf(Style::class, $product->style);
        $this->assertSame('Vintage', $product->style->name);
        $this->assertTrue($style->products->contains($product));
    }

    // =========================================================
    // F. UNIQUE SLUG PER COMPANY
    // =========================================================

    public function test_style_unique_slug_per_company(): void
    {
        Style::create([
            'company_id' => $this->company->id,
            'name' => 'Básico',
            'slug' => 'basico-' . $this->company->id,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('styles', [
            'company_id' => $this->company->id,
            'slug' => 'basico-' . $this->company->id,
        ]);

        $otherCompany = Company::create([
            'trade_name' => 'Other Slug ' . uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
        Style::create([
            'company_id' => $otherCompany->id,
            'name' => 'Básico',
            'slug' => 'basico-' . $otherCompany->id,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('styles', [
            'company_id' => $otherCompany->id,
            'slug' => 'basico-' . $otherCompany->id,
        ]);

        $this->assertNotSame(
            Style::where('company_id', $this->company->id)->first()->slug,
            Style::where('company_id', $otherCompany->id)->first()->slug
        );
    }
}
