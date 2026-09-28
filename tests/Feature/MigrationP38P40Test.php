<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Exports\MigrationPackageExportService;
use App\Services\Migration\MigrationReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class MigrationP38P40Test extends TestCase
{
    use RefreshDatabase;

    public function test_p38_package_contains_all_datasets_manifest_hashes_and_is_company_scoped(): void
    {
        [$company, $branch, $user] = $this->context();
        $other = Company::create(['trade_name' => 'Otra', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        DB::table('customers')->insert(['company_id' => $other->id, 'customer_type' => 'individual', 'name' => 'Ajeno', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $path = app(MigrationPackageExportService::class)->build($company->id, $branch->id);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $manifest = json_decode($zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($company->id, $manifest['company_id']);
        $this->assertSame(MigrationPackageExportService::DATASETS, array_column($manifest['files'], 'dataset'));
        foreach ($manifest['files'] as $file) {
            $content = $zip->getFromName($file['file']);
            $this->assertNotFalse($content);
            $this->assertSame($file['sha256'], hash('sha256', $content));
        }
        $customers = $zip->getFromName(collect($manifest['files'])->firstWhere('dataset', 'customers')['file']);
        $this->assertStringNotContainsString('Ajeno', $customers);
        $zip->close();
        unlink($path);

        $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('data-center.exports'))->assertOk()->assertSee('data-migration-package', false);
    }

    public function test_p40_reconciliation_is_deterministic_read_only_and_documented(): void
    {
        [$company] = $this->context();
        $before = [DB::table('customers')->count(), DB::table('products')->count(), DB::table('sales')->count()];
        $summary = app(MigrationReconciliationService::class)->summary($company->id);

        $this->assertSame(['customers', 'products', 'sales', 'sales_total', 'last_sale_at', 'inventory_units', 'inventory_movements', 'loyalty_balance', 'loyalty_movements'], array_keys($summary));
        $this->assertSame('0.0000', $summary['sales_total']);
        $this->assertSame('0.0000', $summary['inventory_units']);
        $this->assertSame('0.0000', $summary['loyalty_balance']);
        $this->assertSame($before, [DB::table('customers')->count(), DB::table('products')->count(), DB::table('sales')->count()]);
        $this->assertFileExists(base_path('docs/produccion/MIGRACION_SQLITE_POSTGRESQL_P40.md'));
        $procedure = file_get_contents(base_path('docs/produccion/MIGRACION_SQLITE_POSTGRESQL_P40.md'));
        $this->assertStringContainsString('No autoriza ni ejecuta producción', $procedure);
        $this->assertStringContainsString('No usar `migrate:fresh`', $procedure);
        $this->assertStringContainsString('rollback', Str::lower($procedure));
    }

    private function context(): array
    {
        $suffix = Str::lower(Str::random(8));
        $company = Company::create(['trade_name' => 'P38 '.$suffix, 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'B'.$suffix, 'is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Migración', 'is_active' => true]);
        foreach (['reportes.exportar', 'clientes.ver', 'productos.ver', 'ventas.ver', 'inventario.ver', 'fidelidad.ver'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Datos', 'is_active' => true]);
            $role->permissions()->syncWithoutDetaching($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return [$company, $branch, $user];
    }
}
