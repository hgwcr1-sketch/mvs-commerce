<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Company;
use App\Models\ProductCategory;
use App\Models\Unit;
use App\Services\Imports\MigrationTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class MigrationTemplateSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_p31_p40_input_templates_are_self_explanatory_and_excel_safe(): void
    {
        $company = Company::create([
            'trade_name' => 'Plantillas seguras', 'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica', 'is_active' => true,
        ]);
        ProductCategory::create(['company_id' => $company->id, 'name' => 'Categoría real', 'slug' => 'categoria-real', 'is_active' => true]);
        Brand::create(['company_id' => $company->id, 'name' => 'Marca real', 'slug' => 'marca-real', 'is_active' => true]);
        Unit::create(['company_id' => $company->id, 'name' => 'Unidad real', 'abbreviation' => 'UR', 'slug' => 'unidad-real', 'allows_decimals' => true, 'is_active' => true]);

        $templates = app(MigrationTemplateService::class);
        foreach (['customers', 'products', 'sales', 'inventory', 'loyalty'] as $type) {
            $book = $this->roundTrip($templates->make($type, $company->id));
            $this->assertNotNull($book->getSheetByName('INSTRUCCIONES'), $type);
            $this->assertSame(
                ['Campo', 'Obligatorio / opcional', 'Formato', 'Valores permitidos', 'Ejemplo'],
                $book->getSheetByName('INSTRUCCIONES')->rangeToArray('A1:E1')[0],
                $type,
            );
            $this->assertSame('hidden', $book->getSheetByName('CATALOGOS')->getSheetState(), $type);
            $book->disconnectWorksheets();
        }
    }

    public function test_customer_and_product_identifiers_are_text_and_real_catalogs_drive_dropdowns(): void
    {
        $company = Company::create([
            'trade_name' => 'Catálogos reales', 'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica', 'is_active' => true,
        ]);
        ProductCategory::create(['company_id' => $company->id, 'name' => 'Alimentos', 'slug' => 'alimentos', 'is_active' => true]);
        Brand::create(['company_id' => $company->id, 'name' => 'MVS', 'slug' => 'mvs', 'is_active' => true]);
        Unit::create(['company_id' => $company->id, 'name' => 'Unidad', 'abbreviation' => 'Unid', 'slug' => 'unidad', 'allows_decimals' => false, 'is_active' => true]);
        $templates = app(MigrationTemplateService::class);

        $customersBook = $templates->make('customers', $company->id);
        $customers = $customersBook->getActiveSheet();
        foreach (['B2', 'C2', 'F2', 'G2', 'H2'] as $cell) {
            $this->assertSame(NumberFormat::FORMAT_TEXT, $customers->getStyle($cell)->getNumberFormat()->getFormatCode(), $cell);
        }
        $this->assertSame('#,##0.00', $customers->getStyle('K2')->getNumberFormat()->getFormatCode());

        $productsBook = $this->roundTrip($templates->make('products', $company->id));
        $products = $productsBook->getActiveSheet();
        foreach (['A2', 'G2', 'H2', 'I2'] as $cell) {
            $this->assertSame(NumberFormat::FORMAT_TEXT, $products->getStyle($cell)->getNumberFormat()->getFormatCode(), $cell);
        }
        $this->assertSame(DataValidation::TYPE_LIST, $products->getDataValidation('F2')->getType());
        $this->assertStringContainsString('CATALOGOS', $products->getDataValidation('C2')->getFormula1());
        $catalogValues = $productsBook->getSheetByName('CATALOGOS')->toArray();
        $this->assertStringContainsString('Alimentos', json_encode($catalogValues, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('Unidad', json_encode($catalogValues, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('MVS', json_encode($catalogValues, JSON_UNESCAPED_UNICODE));

        $instructions = $productsBook->getSheetByName('INSTRUCCIONES')->toArray();
        $this->assertStringContainsString('product, service, combo', json_encode($instructions, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('no es catálogo cerrado', json_encode($instructions, JSON_UNESCAPED_UNICODE));
        $productsBook->disconnectWorksheets();
        $customersBook->disconnectWorksheets();
    }

    private function roundTrip($spreadsheet)
    {
        $path = tempnam(sys_get_temp_dir(), 'mvs-template-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        $loaded = IOFactory::load($path);
        unlink($path);

        return $loaded;
    }
}
