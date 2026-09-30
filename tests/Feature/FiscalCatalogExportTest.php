<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Cabys;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Imports\FiscalGuideSheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FiscalCatalogExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_cabys_catalog_downloads_csv_and_xlsx_from_the_official_table(): void
    {
        [$company, $branch, $user] = $this->context();
        Cabys::create([
            'code' => '0111100000100',
            'description' => 'Trigo duro, para siembra (semillas)',
            'category1_code' => '0',
            'category1_description' => 'Productos de la agricultura, silvicultura y pesca',
            'category8_description' => 'Trigo duro, para siembra (semillas)',
            'tax_rate' => 1,
            'is_active' => true,
        ]);

        $csv = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('data-center.exports.download', ['cabys', 'csv']));

        $csv->assertOk();
        $content = $csv->streamedContent();
        unset($csv);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('Código,Descripción,Jerarquía,Impuesto', $content);
        $this->assertStringContainsString('0111100000100', $content);
        $this->assertStringContainsString('Productos de la agricultura, silvicultura y pesca', $content);

        $this->get(route('data-center.exports.download', ['cabys', 'xlsx']))->assertOk();
    }

    public function test_fiscal_catalog_downloads_active_profiles_with_version_metadata(): void
    {
        [$company, $branch, $user] = $this->context();

        $csv = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('data-center.exports.download', ['fiscal-catalog', 'csv']));

        $csv->assertOk();
        $content = $csv->streamedContent();
        $this->assertStringContainsString('"Código impuesto","Código tarifa",Nombre,Tratamiento', $content);
        $this->assertStringContainsString('IVA 13%', $content);
        $this->assertStringContainsString('IVA exento', $content);
        $this->assertStringContainsString('Selectivo de Consumo', $content);
        $this->assertStringContainsString('taxable', $content);
        $this->assertStringContainsString('additional_tax', $content);
        $this->assertStringContainsString('Hacienda v4.4 / Facturaencr OpenAPI', $content);
        $this->assertStringContainsString('v4.4', $content);

        $this->get(route('data-center.exports.download', ['fiscal-catalog', 'xlsx']))->assertOk();
    }

    public function test_catalog_downloads_and_cards_require_the_export_permission(): void
    {
        [$company, $branch, $user] = $this->context(false);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('data-center.exports.download', ['cabys', 'csv']))->assertForbidden();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('data-center.exports.download', ['fiscal-catalog', 'csv']))->assertForbidden();
    }

    public function test_exports_center_shows_both_catalog_cards(): void
    {
        [$company, $branch, $user] = $this->context();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('data-center.exports'))->assertOk()
            ->assertSee('data-export-dataset="cabys"', false)
            ->assertSee('data-export-dataset="fiscal-catalog"', false);
    }

    public function test_catalogs_are_global_and_identical_across_companies(): void
    {
        [$companyA, $branchA, $userA] = $this->context();
        [$companyB, $branchB, $userB] = $this->context();

        $contentA = $this->actingAs($userA)->withSession($this->activeSession($companyA, $branchA))
            ->get(route('data-center.exports.download', ['fiscal-catalog', 'csv']))->assertOk()->streamedContent();
        $contentB = $this->actingAs($userB)->withSession($this->activeSession($companyB, $branchB))
            ->get(route('data-center.exports.download', ['fiscal-catalog', 'csv']))->assertOk()->streamedContent();

        $this->assertSame($contentA, $contentB);

        Cabys::create(['code' => '0000000000001', 'description' => 'Global', 'tax_rate' => 13, 'is_active' => true]);
        $cabysA = $this->actingAs($userA)->withSession($this->activeSession($companyA, $branchA))
            ->get(route('data-center.exports.download', ['cabys', 'csv']))->assertOk()->streamedContent();
        $cabysB = $this->actingAs($userB)->withSession($this->activeSession($companyB, $branchB))
            ->get(route('data-center.exports.download', ['cabys', 'csv']))->assertOk()->streamedContent();

        $this->assertSame($cabysA, $cabysB);
        $this->assertStringContainsString('0000000000001', $cabysA);
    }

    public function test_product_inventory_and_purchase_templates_carry_fiscal_columns_and_guia(): void
    {
        [$company, $branch, $user] = $this->context(true, ['productos.crear', 'inventario.ver', 'compras.crear']);

        $cases = [
            ['importaciones.productos.template', ['codigo_impuesto', 'codigo_tarifa', 'perfil_fiscal']],
            ['importaciones.inventario.template', ['codigo_impuesto', 'codigo_tarifa', 'perfil_fiscal']],
            ['compras.import.template', ['Código Impuesto', 'Código Tarifa', 'Perfil Fiscal']],
        ];

        foreach ($cases as [$routeName, $expectedHeaders]) {
            $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
                ->get(route($routeName));
            $response->assertOk();

            $path = tempnam(sys_get_temp_dir(), 'plantilla-fiscal-').'.xlsx';
            file_put_contents($path, $response->streamedContent());
            unset($response);
            $zip = new \ZipArchive();
            $this->assertTrue($zip->open($path));
            $workbook = (string) $zip->getFromName('xl/workbook.xml');
            $strings = (string) $zip->getFromName('xl/sharedStrings.xml');
            $zip->close();
            unlink($path);

            $this->assertStringContainsString('name="'.FiscalGuideSheet::TITLE.'"', $workbook, $routeName);
            $this->assertStringContainsString(FiscalGuideSheet::WARNING, $strings, $routeName);
            $this->assertStringContainsString('codigo_impuesto', $strings, $routeName);
            $this->assertStringContainsString('codigo_tarifa', $strings, $routeName);
            $this->assertStringContainsString('perfil_fiscal', $strings, $routeName);
            $this->assertStringContainsString('IVA 13%', $strings, $routeName);
            $this->assertStringContainsString('zero_rate', $strings, $routeName);
            foreach ($expectedHeaders as $header) {
                $this->assertStringContainsString($header, $strings, $routeName);
            }
        }
    }

    private function context(bool $reportesExportar = true, array $extra = []): array
    {
        $company = Company::create(['trade_name' => 'FiscalCat '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.Str::lower(Str::random(6)), 'is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);

        $permissions = array_merge($reportesExportar ? ['reportes.exportar'] : [], $extra);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Centro de Datos', 'is_active' => true]);
            $role->permissions()->syncWithoutDetaching($permission);
        }

        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return [$company, $branch, $user];
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
