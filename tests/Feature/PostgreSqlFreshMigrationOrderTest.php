<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PostgreSqlFreshMigrationOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_brand_foreign_key_is_declared_only_after_brands_table_is_created(): void
    {
        $productsMigration = file_get_contents(database_path('migrations/2026_07_28_190132_create_products_table.php'));
        $brandsMigration = file_get_contents(database_path('migrations/2026_07_30_000006_create_brands_table.php'));

        $this->assertStringContainsString("foreignId('brand_id')", $productsMigration);
        $this->assertStringNotContainsString("constrained('brands')", $productsMigration);

        $createBrands = strpos($brandsMigration, "Schema::create('brands'");
        $addForeign = strpos($brandsMigration, "Schema::table('products'");
        $this->assertNotFalse($createBrands);
        $this->assertNotFalse($addForeign);
        $this->assertGreaterThan($createBrands, $addForeign);
        $this->assertStringContainsString("->on('brands')", $brandsMigration);

        $this->assertTrue(Schema::hasTable('products'));
        $this->assertTrue(Schema::hasTable('brands'));
        $this->assertTrue(Schema::hasColumn('products', 'brand_id'));

        if (DB::getDriverName() === 'sqlite') {
            $foreignKeys = collect(DB::select("PRAGMA foreign_key_list('products')"));
            $this->assertTrue($foreignKeys->contains(
                fn (object $foreignKey) => $foreignKey->from === 'brand_id' && $foreignKey->table === 'brands',
            ));
        }
    }
}
