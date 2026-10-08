<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerImportRun;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyLicenseService;
use App\Services\Imports\CustomerImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * 12B - importación de clientes dentro del shell CobrOS.
 * Reutiliza el motor existente (CustomerImportRunService/IdentityMatcher) con
 * plantilla, vista previa, duplicados/conflictos y confirmación, siempre
 * acotado a la empresa activa y a los usuarios autorizados.
 */
class CobrosCustomerImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_shell_renders_import_entry_point_and_template(): void
    {
        [$company, $branch, $owner] = $this->context(['cobros.acceder', 'cobros.portal_dueno', 'clientes.crear']);
        $session = ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];

        $response = $this->actingAs($owner)->withSession($session)
            ->get(route('cobros.owner.customers.import'))->assertOk();
        $response->assertSee('Importar clientes')
            ->assertSee(route('cobros.owner.customers.import.template'), false)
            ->assertSee('Modo seguro')
            ->assertDontSee('MVS Commerce');

        $this->get(route('cobros.owner.customers.import.template'))->assertOk();

        $this->get(route('cobros.owner.customers'))
            ->assertOk()
            ->assertSee(route('cobros.owner.customers.import'), false);
    }

    public function test_preview_and_confirm_create_customers_inside_cobros_with_company_isolation(): void
    {
        [$company, $branch, $owner] = $this->context(['cobros.acceder', 'cobros.portal_dueno', 'clientes.crear']);
        [$other] = $this->context([]);
        $session = ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];

        $file = $this->customerFile([
            ['individual', '01', 'IMP-100', 'Cliente Importado', '', '', '(8888) 7777', '', 'importado@example.com', 'San José', '100.50', '0', 'normal', '1990-05-10', 'Sí'],
        ]);

        $this->actingAs($owner)->withSession($session)
            ->post(route('cobros.owner.customers.import.preview'), ['customer_file' => $this->uploaded($file)])
            ->assertOk()
            ->assertSee('IMP-100')
            ->assertSee('Listo para revisar');
        $this->assertDatabaseCount('customers', 0);

        $run = CustomerImportRun::where('company_id', $company->id)->firstOrFail();
        $this->post(route('cobros.owner.customers.import.confirm'), ['run_id' => $run->id])
            ->assertRedirect(route('cobros.owner.customers.import.status', $run->id));

        $customer = Customer::where('company_id', $company->id)->where('identification', 'IMP-100')->firstOrFail();
        $this->assertSame('Cliente Importado', $customer->name);
        $this->assertSame(0, Customer::where('company_id', $other->id)->count());

        $this->get(route('cobros.owner.customers'))
            ->assertOk()->assertSee('Cliente Importado');

        $foreignOwner = $this->foreignOwner($other);
        $this->actingAs($foreignOwner)->withSession(['active_company_id' => $other->id])
            ->get(route('cobros.owner.customers.import.status', $run->id))
            ->assertNotFound();
        $this->actingAs($owner)->withSession($session)
            ->get(route('cobros.owner.customers.import.status', $run->id))
            ->assertOk()
            ->assertSee('Cliente Importado');
    }

    public function test_existing_and_file_duplicates_are_ignored_without_touching_current_customers(): void
    {
        [$company, $branch, $owner] = $this->context(['cobros.acceder', 'cobros.portal_dueno', 'clientes.crear']);
        $session = ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
        Customer::create([
            'company_id' => $company->id, 'customer_type' => 'individual', 'identification_type' => '01',
            'identification' => 'DUP-900', 'name' => 'Cliente Original', 'credit_limit' => 0,
            'credit_days' => 0, 'price_level' => 'normal', 'is_active' => true,
        ]);
        $row = ['individual', '01', 'DUP-900', 'Cliente Nuevo Ignorado', '', '', '(8888) 0000', '', 'nuevo@example.com', 'San José', '0', '0', 'normal', '1990-05-10', 'No'];
        $file = $this->customerFile([$row, $row]);

        $this->actingAs($owner)->withSession($session)
            ->post(route('cobros.owner.customers.import.preview'), ['customer_file' => $this->uploaded($file)])
            ->assertOk();

        $run = CustomerImportRun::where('company_id', $company->id)->firstOrFail();
        $this->post(route('cobros.owner.customers.import.confirm'), ['run_id' => $run->id])
            ->assertRedirect(route('cobros.owner.customers.import.status', $run->id));

        $run->refresh();
        $this->assertSame(0, $run->created_count);
        $this->assertGreaterThanOrEqual(1, $run->existing_count + $run->duplicate_count + $run->conflict_count);
        $this->assertSame(1, Customer::where('company_id', $company->id)->count());
        $this->assertSame('Cliente Original', Customer::where('identification', 'DUP-900')->first()->name);
    }

    public function test_import_routes_require_owner_permission(): void
    {
        [$c, , $collector] = $this->context(['cobros.acceder', 'cobros.cobrar']);
        $session = ['active_company_id' => $c->id];

        $this->actingAs($collector)->withSession($session)
            ->get(route('cobros.owner.customers.import'))->assertForbidden();
        $this->actingAs($collector)->withSession($session)
            ->get(route('cobros.owner.customers.import.template'))->assertForbidden();
        $this->actingAs($collector)->withSession($session)
            ->get(route('cobros.owner.customers.import.status', 1))->assertForbidden();
        $this->actingAs($collector)->withSession($session)
            ->post(route('cobros.owner.customers.import.preview'))->assertForbidden();
        $this->actingAs($collector)->withSession($session)
            ->post(route('cobros.owner.customers.import.confirm'))->assertForbidden();

        $this->assertContains(
            'permission:cobros.portal_dueno',
            Route::getRoutes()->getByName('cobros.owner.customers.import')->gatherMiddleware()
        );
    }

    private function context(array $permissions): array
    {
        $company = Company::create([
            'trade_name' => 'Import '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica',
            'default_phone_country_code' => '+506', 'is_active' => true,
        ]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P', 'is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Import '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]);
            $role->permissions()->syncWithoutDetaching($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyLicenseService::class)->updateModules($company, $admin, ['cobros', 'customers']);

        return [$company->fresh(), $branch, $user, $role];
    }

    private function foreignOwner(Company $company): User
    {
        $role = Role::create(['company_id' => $company->id, 'name' => 'Dueño ajeno', 'is_active' => true]);
        foreach (['cobros.acceder', 'cobros.portal_dueno', 'clientes.crear'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]);
            $role->permissions()->syncWithoutDetaching($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);

        return $user;
    }

    private function customerFile(array $dataRows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cobros-customers-').'.xlsx';
        $spreadsheet = new Spreadsheet;
        $headers = array_values(array_diff(CustomerImportService::HEADERS, ['codigo_cliente']));
        $spreadsheet->getActiveSheet()->fromArray(array_merge([$headers], $dataRows));
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    private function uploaded(string $path): UploadedFile
    {
        return new UploadedFile($path, 'clientes.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}

