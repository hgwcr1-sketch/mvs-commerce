<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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

            $customerForeignKeys = collect(DB::select("PRAGMA foreign_key_list('customers')"))
                ->mapWithKeys(fn (object $foreignKey) => [$foreignKey->from => $foreignKey->table]);
            $this->assertSame('countries', $customerForeignKeys['country_id']);
            $this->assertSame('provinces', $customerForeignKeys['province_id']);
            $this->assertSame('cantons', $customerForeignKeys['canton_id']);
            $this->assertSame('districts', $customerForeignKeys['district_id']);
        }
    }

    public function test_no_foreign_key_references_a_table_created_by_a_later_migration(): void
    {
        $createdTables = [];
        $violations = [];
        $pattern = "/Schema::create\\('(?<create>[^']+)'|foreignId\\('(?<column>[^']+)_id'\\)(?<chain>.{0,300}?)constrained\\((?:'(?<explicit>[^']+)')?\\)|foreign\\('(?<foreign_column>[^']+)_id'\\)(?<foreign_chain>.{0,300}?)references\\('[^']+'\\)(?<on_chain>.{0,160}?)on\\('(?<on_table>[^']+)'\\)/s";

        $migrations = glob(database_path('migrations/*.php'));
        sort($migrations, SORT_STRING);

        foreach ($migrations as $migration) {
            $source = file_get_contents($migration);
            preg_match_all($pattern, $source, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);

            foreach ($matches as $match) {
                if ($match['create'] !== null) {
                    $createdTables[$match['create']] = basename($migration);

                    continue;
                }

                $column = ($match['column'] ?? $match['foreign_column']).'_id';
                $target = $match['on_table']
                    ?? $match['explicit']
                    ?? Str::plural(Str::beforeLast($column, '_id'));

                if (! isset($createdTables[$target])) {
                    $violations[] = basename($migration).": {$column} → {$target}";
                }
            }
        }

        $this->assertSame([], $violations, "FKs hacia tablas futuras:\n".implode("\n", $violations));
    }
}
