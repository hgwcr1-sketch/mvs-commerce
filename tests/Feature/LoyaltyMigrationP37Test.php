<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyMovement;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Imports\LoyaltyMigrationImportService;
use App\Services\Loyalty\LoyaltyAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class LoyaltyMigrationP37Test extends TestCase
{
    use RefreshDatabase;

    public function test_template_and_equivalent_export_share_the_same_headers(): void
    {
        [$company, $branch, $user, $customer] = $this->context(['fidelidad.configuracion', 'fidelidad.ver', 'reportes.exportar']);
        $this->createAccount($company, $customer, '100.0000');

        $template = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('importaciones.fidelidad-migracion.template'))->assertOk();
        $this->assertSame(LoyaltyMigrationImportService::HEADERS, $this->spreadsheet($template->streamedContent())[0]);

        $rows = $this->spreadsheet($this->get(route('data-center.exports.download', ['loyalty-migration', 'xlsx']))->assertOk()->streamedContent());
        $this->assertSame(LoyaltyMigrationImportService::HEADERS, $rows[0]);
        $this->assertSame('saldo_inicial', $rows[1][2]);
        $this->assertSame('100', (string) $rows[1][9]);
    }

    public function test_preview_accepts_xlsx_xls_csv_without_mutation(): void
    {
        [$company, $branch, $user, $customer] = $this->context(['fidelidad.configuracion']);
        foreach (['xlsx', 'xls', 'csv'] as $format) {
            $row = $this->initialRow($customer, 'SRC-'.$format, '50.0000');
            $this->actingAs($user)->withSession($this->activeSession($company, $branch))
                ->post(route('importaciones.fidelidad-migracion.preview'), ['migrar_file' => $this->upload($this->file([$row], $format), $format)])
                ->assertOk();
            $this->assertTrue(session('loyalty_migration_preview.rows.0.valid'));
        }
        $this->assertDatabaseCount('loyalty_accounts', 0);
        $this->assertDatabaseCount('loyalty_movements', 0);
        $this->assertDatabaseCount('loyalty_migration_batches', 0);
    }

    public function test_preview_reports_duplicate_and_company_isolation(): void
    {
        [$company, $branch, $user, $customer] = $this->context(['fidelidad.configuracion']);
        [$otherCompany, $otherBranch, $otherUser, $otherCustomer] = $this->context([]);
        DB::table('loyalty_migration_batches')->insert(['company_id' => $company->id, 'user_id' => $user->id, 'source_key' => 'REPETIDO', 'row_count' => 1, 'imported_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $rows = [
            $this->movementRow($customer, 'REPETIDO', 'DUP-M', 'purchase', '10.0000'),
            $this->movementRow($otherCustomer, 'REPETIDO', 'DUP-F', 'redemption', '5.0000'),
        ];

        $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('importaciones.fidelidad-migracion.preview'), ['migrar_file' => $this->upload($this->file($rows, 'xlsx'), 'xlsx')])
            ->assertOk();

        $response->assertSee('origen_migracion')->assertSee('identificacion_cliente')->assertSee('tipo_saldo')
            ->assertSee('tipo_movimiento')->assertSee('Corrija todas las filas')->assertSee('Descargar errores CSV');
        $this->assertFalse(session('loyalty_migration_preview.rows.0.valid'));
        $this->assertFalse(session('loyalty_migration_preview.rows.1.valid'));
        $this->get(route('importaciones.fidelidad-migracion.errors'))->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_confirmation_sets_initial_balance_and_adds_movement_without_operational_effects(): void
    {
        [$company, $branch, $user, $customer] = $this->context(['fidelidad.configuracion']);
        $rows = [
            $this->initialRow($customer, 'MIG-2024', '15.0000'),
            $this->movementRow($customer, 'MIG-2024', 'HIST-1', 'purchase', '15.0000'),
        ];

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('importaciones.fidelidad-migracion.preview'), ['migrar_file' => $this->upload($this->file($rows, 'xlsx'), 'xlsx')])
            ->assertOk();
        $this->post(route('importaciones.fidelidad-migracion.import'))->assertRedirect(route('loyalty.dashboard'));

        $account = LoyaltyAccount::where('company_id', $company->id)->where('customer_id', $customer->id)->sole();
        $this->assertSame('15.0000', (string) $account->balance);
        $this->assertSame('15.0000', (string) $account->total_earned);
        $this->assertSame(1, LoyaltyMovement::where('company_id', $company->id)->where('loyalty_account_id', $account->id)->count());
        $this->assertSame(LoyaltyMovement::TYPE_PURCHASE, LoyaltyMovement::where('company_id', $company->id)->value('type'));
        $this->assertSame('MIG-2024', DB::table('loyalty_migration_batches')->where('company_id', $company->id)->value('source_key'));
        foreach (['inventory_movements', 'sales', 'sale_items', 'sale_payments', 'cash_sessions', 'accounts_receivable'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        $this->assertNull(session('loyalty_migration_preview'));
    }

    public function test_retry_and_concurrent_change_are_idempotent_and_atomic(): void
    {
        [$company, $branch, $user, $customer] = $this->context(['fidelidad.configuracion']);
        $row = $this->initialRow($customer, 'ATOMIC', '9.0000');
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('importaciones.fidelidad-migracion.preview'), ['migrar_file' => $this->upload($this->file([$row], 'xlsx'), 'xlsx')])
            ->assertOk();
        $this->post(route('importaciones.fidelidad-migracion.import'))->assertRedirect(route('loyalty.dashboard'));
        $this->assertSame('9.0000', (string) LoyaltyAccount::where('company_id', $company->id)->where('customer_id', $customer->id)->value('balance'));

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('importaciones.fidelidad-migracion.preview'), ['migrar_file' => $this->upload($this->file([$row], 'xlsx'), 'xlsx')])
            ->assertOk();
        $this->assertFalse(session('loyalty_migration_preview.rows.0.valid'));
        $this->from(route('importaciones.fidelidad-migracion'))
            ->post(route('importaciones.fidelidad-migracion.import'))
            ->assertRedirect(route('importaciones.fidelidad-migracion'))
            ->assertSessionHasErrors('migrar_file');
        $this->assertSame('9.0000', (string) LoyaltyAccount::where('company_id', $company->id)->where('customer_id', $customer->id)->value('balance'));
        $this->assertDatabaseCount('loyalty_movements', 0);
        $this->assertDatabaseCount('loyalty_migration_batches', 1);
    }

    public function test_failure_after_balance_rolls_back_the_whole_batch(): void
    {
        [$company, $branch, $user, $customer] = $this->context(['fidelidad.configuracion']);
        $rows = [
            $this->initialRow($customer, 'ROLLBACK', '9.0000'),
            $this->movementRow($customer, 'ROLLBACK', 'FAIL-1', 'purchase', '9.0000'),
        ];
        $mock = Mockery::mock(LoyaltyAccountService::class);
        $mock->shouldReceive('recordHistoricalMigrationMovement')->once()->andThrow(new \RuntimeException('fallo controlado'));
        $service = new LoyaltyMigrationImportService($mock);
        $preview = $service->preview($this->file($rows, 'xlsx'), $company->id);

        try {
            $service->confirm($preview, $company->id, $user->id);
            $this->fail('La confirmación debía fallar.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('fallo controlado', $exception->getMessage());
        }

        $this->assertDatabaseCount('loyalty_accounts', 0);
        $this->assertDatabaseCount('loyalty_movements', 0);
        $this->assertDatabaseCount('loyalty_migration_batches', 0);
    }

    public function test_permissions_and_cross_company_are_blocked(): void
    {
        [$company, $branch, $user, $customer] = $this->context(['fidelidad.configuracion']);
        [$otherCompany, $otherBranch, $denied] = $this->context([]);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('data-center.imports'))->assertOk()->assertSee('data-existing-import="loyalty-migration"', false);

        $row = $this->initialRow($customer, 'FOREIGN', '3.0000');
        $this->post(route('importaciones.fidelidad-migracion.preview'), ['migrar_file' => $this->upload($this->file([$row], 'xlsx'), 'xlsx')])
            ->assertOk();
        $this->assertTrue(session('loyalty_migration_preview.rows.0.valid'));

        $this->actingAs($denied)->withSession($this->activeSession($otherCompany, $otherBranch))
            ->get(route('importaciones.fidelidad-migracion'))->assertForbidden();
    }

    private function context(array $permissions): array
    {
        $suffix = Str::lower(Str::random(8));
        $company = Company::create(['trade_name' => 'Fid37 '.$suffix, 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'B'.$suffix, 'is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Fid37 '.$suffix, 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Fidelidad', 'is_active' => true]);
            $role->permissions()->syncWithoutDetaching($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        $customer = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente '.$suffix, 'identification_type' => 'national', 'identification' => 'ID'.$suffix, 'is_active' => true]);

        return [$company, $branch, $user, $customer];
    }

    private function createAccount(Company $company, Customer $customer, string $balance): LoyaltyAccount
    {
        return LoyaltyAccount::create([
            'company_id' => $company->id, 'customer_id' => $customer->id,
            'balance' => $balance, 'total_earned' => '0.0000',
            'total_redeemed' => '0.0000', 'total_expired' => '0.0000',
            'is_active' => true,
        ]);
    }

    private function initialRow(Customer $customer, string $source, string $balance): array
    {
        return [
            $source, $customer->identification, 'saldo_inicial', '2022-01-01 08:00:00',
            '', '', '', '', '', $balance, $balance, '0.0000', '0.0000',
            '2022-01-01 08:00:00', '2022-01-01 08:00:00', 'Sí', '', '', 'Snapshot exportado', '',
        ];
    }

    private function movementRow(Customer $customer, string $source, string $key, string $type, string $points): array
    {
        return [
            $source, $customer->identification, 'movimiento_historico', '2022-01-01 08:00:00',
            '', '', '', $type, $points, '', '', '', '', '', '', '', '0.0000', $points, 'Movimiento histórico', '',
        ];
    }

    private function file(array $rows, string $format): string
    {
        $path = tempnam(sys_get_temp_dir(), 'p37-').'.'.$format;
        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->fromArray(array_merge([LoyaltyMigrationImportService::HEADERS], $rows));
        match ($format) {
            'xlsx' => (new Xlsx($sheet))->save($path), 'xls' => (new Xls($sheet))->save($path), 'csv' => (new Csv($sheet))->save($path)
        };

        return $path;
    }

    private function upload(string $path, string $format): UploadedFile
    {
        return new UploadedFile($path, 'p37.'.$format, match ($format) {
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xls' => 'application/vnd.ms-excel', default => 'text/csv'
        }, null, true);
    }

    private function spreadsheet(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'p37-sheet-');
        file_put_contents($path, $content);

        return IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false);
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
