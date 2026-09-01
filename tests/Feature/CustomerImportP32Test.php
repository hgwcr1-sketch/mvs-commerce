<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Imports\CustomerImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class CustomerImportP32Test extends TestCase
{
    use RefreshDatabase;

    public function test_template_and_customer_export_reuse_the_existing_excel_infrastructure(): void
    {
        [$company, $branch, $user] = $this->context(['clientes.crear', 'clientes.ver', 'reportes.exportar']);
        Customer::create($this->customerData($company, ['name' => 'Cliente exportable', 'identification' => '101110111']));

        $template = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('importaciones.clientes.template'))->assertOk();
        $this->assertSame(CustomerImportService::HEADERS, $this->spreadsheetRows($template->streamedContent())[0]);

        $export = $this->get(route('data-center.exports.download', ['customers', 'xlsx']))->assertOk();
        $rows = $this->spreadsheetRows($export->streamedContent());
        $this->assertSame('Cliente exportable', $rows[1][2]);
        $this->assertContains('permission:clientes.crear', Route::getRoutes()->getByName('importaciones.clientes.import')->gatherMiddleware());
    }

    public function test_preview_normalizes_data_is_company_scoped_and_does_not_write(): void
    {
        [$company, $branch, $user] = $this->context(['clientes.crear']);
        [$otherCompany] = $this->context([]);
        Customer::create($this->customerData($otherCompany, [
            'identification' => 'DUP-OTRA', 'phone' => '88887777', 'email' => 'duplicado@example.com',
        ]));
        $file = $this->customerFile([[
            'individual', '01', 'DUP-OTRA', 'Cliente nuevo', '', '', '(8888) 7777', '',
            'DUPLICADO@EXAMPLE.COM', 'San José', '100.50', '15', 'normal', '1990-05-10', 'Sí',
        ]]);

        $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))->post(
            route('importaciones.clientes.preview'),
            ['customer_file' => $this->uploaded($file)],
        );

        $response->assertOk()->assertSee('Cliente nuevo')->assertSee('88887777')->assertSee('duplicado@example.com');
        $preview = session('customer_import_preview');
        $this->assertTrue($preview['rows'][0]['valid'], json_encode($preview['rows'][0]['errors']));
        $this->assertSame('+506', $preview['rows'][0]['phone_country_code']);
        $this->assertSame($company->id, $preview['company_id']);
        $this->assertDatabaseCount('customers', 1);
    }

    public function test_preview_reports_clear_row_and_field_errors_for_existing_and_file_duplicates(): void
    {
        [$company, $branch, $user] = $this->context(['clientes.crear']);
        Customer::create($this->customerData($company, [
            'identification' => 'EXISTE-1', 'phone' => '88887777', 'email' => 'existe@example.com',
        ]));
        $file = $this->customerFile([
            ['individual', '01', 'EXISTE-1', 'Duplicado existente', '', '+506', '8888-7777', '', 'EXISTE@example.com', '', 0, 0, 'normal', '', 'Sí'],
            ['persona', '99', 'ARCHIVO-1', '', '', '+0', '12', '', 'correo-invalido', '', -1, -2, 'otro', '10/05/1990', 'Sí'],
            ['individual', '01', 'ARCHIVO-1', 'Repetido archivo', '', '+506', '', '', '', '', 0, 0, 'normal', '', 'Sí'],
        ]);

        $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))->post(
            route('importaciones.clientes.preview'),
            ['customer_file' => $this->uploaded($file)],
        );

        $response->assertOk()->assertSee('identificacion')->assertSee('telefono')->assertSee('correo')
            ->assertSee('Omitida')->assertSee('Corrija todas las filas antes de confirmar');
        $rows = session('customer_import_preview.rows');
        $this->assertFalse($rows[0]['valid']);
        $this->assertContains('correo', array_column($rows[0]['errors'], 'field'));
        $this->assertFalse($rows[1]['valid']);
        $this->assertTrue($rows[2]['valid']);
        $this->assertTrue($rows[2]['skipped']);
        $this->assertDatabaseCount('customers', 1);
    }

    public function test_valid_confirmation_imports_all_rows_for_the_active_company_without_branch_assignment(): void
    {
        [$company, $branch, $user] = $this->context(['clientes.crear']);
        $file = $this->customerFile([
            ['individual', '01', '101110111', 'Ana Cliente', '', '+506', '8888 1111', '', 'ANA@EXAMPLE.COM', 'Centro', 25.75, 8, 'a', '1992-01-20', 'Sí'],
            ['company', '02', '3101123456', 'Empresa Cliente', 'Comercial EC', '+506', '', '8777-2222', '', '', 0, 0, 'wholesale', '', 'No'],
        ]);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->post(
            route('importaciones.clientes.preview'), ['customer_file' => $this->uploaded($file)],
        )->assertOk();
        $this->post(route('importaciones.clientes.import'))->assertRedirect(route('clientes.index'));

        $this->assertDatabaseHas('customers', [
            'company_id' => $company->id, 'identification' => '101110111', 'phone' => '88881111',
            'email' => 'ana@example.com', 'price_level' => 'a', 'is_active' => true,
        ]);
        $this->assertDatabaseHas('customers', [
            'company_id' => $company->id, 'identification' => '3101123456', 'mobile' => '87772222',
            'customer_type' => 'company', 'is_active' => false,
        ]);
        $this->assertSame(2, Customer::where('company_id', $company->id)->count());
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('customers', 'branch_id'));
        $this->assertNull(session('customer_import_preview'));
    }

    public function test_repeated_file_identification_keeps_first_row_and_omits_later_rows(): void
    {
        [$company, $branch, $user] = $this->context(['clientes.crear']);
        $file = $this->customerFile([
            ['individual', '01', 'DUP-ARCHIVO', 'Primera aparición', '', '+506', '81111111', '', 'primera@example.com', '', 0, 0, 'normal', '1990-01-01', 'Sí'],
            ['individual', '01', 'DUP-ARCHIVO', 'Datos diferentes no fusionados', '', '+506', '82222222', '', 'segunda@example.com', '', 0, 0, 'normal', '1991-02-02', 'Sí'],
            ['individual', '01', 'UNICO-ARCHIVO', 'Cliente único', '', '+506', '83333333', '', 'unico@example.com', '', 0, 0, 'normal', '1992-03-03', 'Sí'],
        ]);

        $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))->post(
            route('importaciones.clientes.preview'), ['customer_file' => $this->uploaded($file)],
        );

        $response->assertOk()->assertSee('Omitidas')->assertSee('Omitida')->assertSee('esta fila se omitirá')->assertSee('Confirmar importación de 2');
        $rows = session('customer_import_preview.rows');
        $this->assertFalse($rows[0]['skipped']);
        $this->assertTrue($rows[1]['valid']);
        $this->assertTrue($rows[1]['skipped']);
        $this->assertFalse($rows[2]['skipped']);
        $this->assertNotEmpty($rows[1]['warnings']);

        $this->post(route('importaciones.clientes.import'))->assertRedirect(route('clientes.index'));
        $this->assertSame(2, Customer::where('company_id', $company->id)->count());
        $this->assertDatabaseHas('customers', [
            'company_id' => $company->id, 'identification' => 'DUP-ARCHIVO',
            'name' => 'Primera aparición', 'phone' => '81111111', 'email' => 'primera@example.com',
        ]);
        $this->assertDatabaseMissing('customers', ['company_id' => $company->id, 'phone' => '82222222']);
        $this->assertDatabaseHas('customers', ['company_id' => $company->id, 'identification' => 'UNICO-ARCHIVO']);
    }

    public function test_legacy_phones_dates_and_safe_mojibake_are_imported_with_warnings_without_inventing_data(): void
    {
        [$company, $branch, $user] = $this->context(['clientes.crear']);
        $file = $this->customerFile([
            ['individual', '01', 'REAL-1', 'BRENDA ZUÃ‘IGA', '', '+506', '50,650,672,617,837', '', '', '', 0, 0, 'normal', '31/12/1980', 'Sí'],
            ['individual', '01', 'REAL-2', 'Teléfono ambiguo', '', '+506', '12,34', 'sin número', '', '', 0, 0, 'normal', 'fecha desconocida', 'Sí'],
            ['individual', '01', 'REAL-3', 'Sin fecha', '', '+506', '', '', '', '', 0, 0, 'normal', '', 'Sí'],
        ]);

        $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))->post(
            route('importaciones.clientes.preview'), ['customer_file' => $this->uploaded($file)],
        );

        $response->assertOk()->assertSee('Advertencia')->assertSee('Con advertencias')->assertDontSee('Corrija todas las filas antes de confirmar');
        $rows = session('customer_import_preview.rows');
        $this->assertTrue(collect($rows)->every(fn (array $row) => $row['valid']));
        $this->assertSame('72617837', $rows[0]['phone']);
        $this->assertSame('BRENDA ZUÑIGA', $rows[0]['name']);
        $this->assertNull($rows[0]['birth_date']);
        $this->assertNull($rows[1]['phone']);
        $this->assertNull($rows[1]['mobile']);
        $this->assertNull($rows[1]['birth_date']);
        $this->assertNull($rows[2]['birth_date']);
        $this->assertNotEmpty($rows[0]['warnings']);
        $this->assertNotEmpty($rows[1]['warnings']);
        $this->assertNotEmpty($rows[2]['warnings']);

        $this->post(route('importaciones.clientes.import'))->assertRedirect(route('clientes.index'));
        $this->assertDatabaseHas('customers', [
            'company_id' => $company->id, 'identification' => 'REAL-1', 'name' => 'BRENDA ZUÑIGA',
            'phone' => '72617837', 'birth_date' => null,
        ]);
        $this->assertDatabaseHas('customers', [
            'company_id' => $company->id, 'identification' => 'REAL-2',
            'phone' => null, 'mobile' => null, 'birth_date' => null,
        ]);
    }

    public function test_invalid_and_repeated_file_emails_become_null_with_warnings_without_blocking_rows(): void
    {
        [$company, $branch, $user] = $this->context(['clientes.crear']);
        $file = $this->customerFile([
            ['individual', '01', 'MAIL-1', 'Correo original', '', '+506', '', '', 'CLIENTE@EXAMPLE.COM', '', 0, 0, 'normal', '1990-01-01', 'Sí'],
            ['individual', '01', 'MAIL-2', 'Correo repetido', '', '+506', '', '', 'cliente@example.com', '', 0, 0, 'normal', '1990-01-02', 'Sí'],
            ['individual', '01', 'MAIL-3', 'Correo inválido', '', '+506', '', '', 'correo-invalido', '', 0, 0, 'normal', '1990-01-03', 'Sí'],
        ]);

        $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))->post(
            route('importaciones.clientes.preview'), ['customer_file' => $this->uploaded($file)],
        );

        $response->assertOk()->assertSee('Advertencia')->assertSee('El correo se repite desde la fila 2')->assertSee('El correo heredado es inválido');
        $rows = session('customer_import_preview.rows');
        $this->assertTrue(collect($rows)->every(fn (array $row) => $row['valid']));
        $this->assertSame('cliente@example.com', $rows[0]['email']);
        $this->assertNull($rows[1]['email']);
        $this->assertNull($rows[2]['email']);
        $this->assertEmpty($rows[0]['warnings']);
        $this->assertNotEmpty($rows[1]['warnings']);
        $this->assertNotEmpty($rows[2]['warnings']);

        $this->post(route('importaciones.clientes.import'))->assertRedirect(route('clientes.index'));
        $this->assertDatabaseHas('customers', ['company_id' => $company->id, 'identification' => 'MAIL-1', 'email' => 'cliente@example.com']);
        $this->assertDatabaseHas('customers', ['company_id' => $company->id, 'identification' => 'MAIL-2', 'email' => null]);
        $this->assertDatabaseHas('customers', ['company_id' => $company->id, 'identification' => 'MAIL-3', 'email' => null]);
    }

    public function test_large_customer_consolidation_uses_linear_indexes_without_timing_out(): void
    {
        $rows = [];

        $rowCount = 6109;

        for ($index = 0; $index < $rowCount; $index++) {
            $rowNumber = $index + 2;
            $rows[] = [
                'row_number' => $rowNumber,
                'customer_type' => 'individual',
                'identification_type' => '01',
                'identification' => 'VOLUMEN-'.$index,
                'name' => 'Cliente '.$index,
                'commercial_name' => null,
                'phone_country_code' => '+506',
                'phone' => (string) (10000000 + $index),
                'mobile' => null,
                'email' => 'cliente'.$index.'@example.com',
                'address' => null,
                'credit_limit' => '0',
                'credit_days' => '0',
                'price_level' => 'normal',
                'birth_date' => null,
                'is_active' => true,
                'valid' => true,
                'skipped' => false,
                'merged_into_row' => null,
                'source_rows' => [$rowNumber],
                'merge_errors' => [],
                'errors' => [],
                'warnings' => [],
            ];
        }

        $method = new \ReflectionMethod(CustomerImportService::class, 'consolidateRows');
        $startedAt = hrtime(true);
        $result = $method->invoke(app(CustomerImportService::class), $rows);
        $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;

        $this->assertCount($rowCount, $result);
        $this->assertFalse($result[0]['skipped']);
        $this->assertFalse($result[$rowCount - 1]['skipped']);
        $this->assertLessThan(5.0, $elapsedSeconds, 'La consolidación de miles de filas debe mantenerse lineal.');
    }

    public function test_confirmation_revalidates_and_rolls_back_all_rows_when_a_duplicate_appears(): void
    {
        [$company, $branch, $user] = $this->context(['clientes.crear']);
        $file = $this->customerFile([
            ['individual', '01', 'NUEVO-1', 'Primero', '', '+506', '80001111', '', '', '', 0, 0, 'normal', '', 'Sí'],
            ['individual', '01', 'NUEVO-2', 'Segundo', '', '+506', '80002222', '', '', '', 0, 0, 'normal', '', 'Sí'],
        ]);
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->post(
            route('importaciones.clientes.preview'), ['customer_file' => $this->uploaded($file)],
        )->assertOk();
        Customer::create($this->customerData($company, ['identification' => 'NUEVO-2', 'name' => 'Concurrente']));

        $this->from(route('importaciones.clientes'))->post(route('importaciones.clientes.import'))
            ->assertRedirect(route('importaciones.clientes'))->assertSessionHasErrors('customer_file');

        $this->assertDatabaseMissing('customers', ['company_id' => $company->id, 'identification' => 'NUEVO-1']);
        $this->assertSame(1, Customer::where('company_id', $company->id)->count());
    }

    public function test_permissions_protect_import_and_data_center_entry_supports_customer_only_users(): void
    {
        [$company, $branch, $allowed] = $this->context(['clientes.crear']);
        [$deniedCompany, $deniedBranch, $denied] = $this->context([]);

        $this->actingAs($allowed)->withSession($this->activeSession($company, $branch))
            ->get(route('data-center.index'))->assertOk();
        $this->get(route('data-center.imports'))->assertOk()->assertSee('data-existing-import="customers"', false);

        $this->actingAs($denied)->withSession($this->activeSession($deniedCompany, $deniedBranch))
            ->get(route('importaciones.clientes'))->assertForbidden();
    }

    private function context(array $permissions): array
    {
        $company = Company::create([
            'trade_name' => 'Clientes '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica',
            'default_phone_country_code' => '+506', 'is_active' => true,
        ]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.uniqid(), 'is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Clientes '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Clientes', 'is_active' => true]);
            $role->permissions()->syncWithoutDetaching($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return [$company, $branch, $user];
    }

    private function customerData(Company $company, array $overrides = []): array
    {
        return array_merge([
            'company_id' => $company->id, 'customer_type' => 'individual', 'identification_type' => '01',
            'identification' => 'ID-'.uniqid(), 'name' => 'Cliente', 'credit_limit' => 0,
            'credit_days' => 0, 'price_level' => 'normal', 'is_active' => true,
        ], $overrides);
    }

    private function customerFile(array $dataRows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'customers-').'.xlsx';
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray(array_merge([CustomerImportService::HEADERS], $dataRows));
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    private function uploaded(string $path): UploadedFile
    {
        return new UploadedFile($path, 'clientes.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function spreadsheetRows(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'spreadsheet-');
        file_put_contents($path, $content);

        return IOFactory::load($path)->getActiveSheet()->toArray();
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
