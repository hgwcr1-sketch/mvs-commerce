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
use Illuminate\Support\Str;
use Mockery;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class LoyaltyMigrationP37Test extends TestCase
{
    use RefreshDatabase;

    public function test_template_and_equivalent_export_have_exactly_the_four_approved_columns(): void
    {
        [$company, $branch, $user, $customer] = $this->context(['fidelidad.configuracion', 'fidelidad.ver', 'reportes.exportar']);
        LoyaltyAccount::create([
            'company_id' => $company->id, 'customer_id' => $customer->id,
            'balance' => '80.2500', 'total_earned' => '100.5000',
            'total_redeemed' => '20.2500', 'total_expired' => '0.0000', 'is_active' => true,
        ]);

        $template = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('importaciones.fidelidad-migracion.template'))->assertOk();
        $book = $this->spreadsheet($template->streamedContent());
        $this->assertSame(LoyaltyMigrationImportService::HEADERS, $book->getSheet(0)->rangeToArray('A1:D1')[0]);
        $this->assertNull($book->getSheet(0)->getCell('E1')->getValue());

        $export = $this->spreadsheet($this->get(route('data-center.exports.download', ['loyalty-migration', 'xlsx']))->assertOk()->streamedContent());
        $this->assertSame(LoyaltyMigrationImportService::HEADERS, $export->getSheet(0)->rangeToArray('A1:D1')[0]);
        $this->assertSame($customer->name, $export->getSheet(0)->getCell('A2')->getValue());
        $this->assertSame('100.5', (string) $export->getSheet(0)->getCell('B2')->getValue());
        $this->assertSame('20.25', (string) $export->getSheet(0)->getCell('C2')->getValue());
        $this->assertSame('80.25', (string) $export->getSheet(0)->getCell('D2')->getValue());
    }

    public function test_preview_accepts_xlsx_xls_csv_normalizes_name_and_preserves_decimal_precision_without_writes(): void
    {
        [$company, $branch, $user, $customer] = $this->context(['fidelidad.configuracion'], 'Cliente Alvarez');

        foreach (['xlsx', 'xls', 'csv'] as $format) {
            $path = $this->file([['  CLIENTE   ÁLVAREZ ', '100.1255', '20.0255', '80.1000']], $format);
            $this->actingAs($user)->withSession($this->activeSession($company, $branch))
                ->post(route('importaciones.fidelidad-migracion.preview'), ['migrar_file' => $this->upload($path, $format)])
                ->assertOk();
            $row = session('loyalty_migration_preview.rows.0');
            $this->assertTrue($row['valid']);
            $this->assertSame($customer->id, $row['customer_id']);
            $this->assertSame('100.1255', $row['awarded_points']);
            $this->assertSame('20.0255', $row['used_points']);
            $this->assertSame('80.1000', $row['balance']);
        }

        $this->assertDatabaseCount('loyalty_accounts', 0);
        $this->assertDatabaseCount('loyalty_movements', 0);
        $this->assertDatabaseCount('loyalty_migration_batches', 0);
    }

    public function test_preview_blocks_missing_ambiguous_duplicate_customer_and_wrong_balance(): void
    {
        [$company, $branch, $user, $customer] = $this->context(['fidelidad.configuracion'], 'Nombre Duplicado');
        Customer::create([
            'company_id' => $company->id, 'customer_type' => 'individual', 'name' => ' nombre   duplicado ',
            'identification_type' => 'national', 'identification' => 'OTRA', 'is_active' => true,
        ]);
        $valid = Customer::create([
            'company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente Único',
            'identification_type' => 'national', 'identification' => 'UNICO', 'is_active' => true,
        ]);
        $rows = [
            ['No Existe', '10.0000', '1.0000', '9.0000'],
            [$customer->name, '10.0000', '1.0000', '9.0000'],
            [$valid->name, '10.0000', '1.0000', '8.0000'],
            [$valid->name, '10.0000', '1.0000', '9.0000'],
        ];

        $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('importaciones.fidelidad-migracion.preview'), ['migrar_file' => $this->upload($this->file($rows, 'xlsx'), 'xlsx')])
            ->assertOk();

        $response->assertSee('no existe')->assertSee('más de un cliente')->assertSee('saldo esperado')
            ->assertSee('cliente está repetido')->assertSee('Corrija todas las filas');
        $this->assertSame(4, collect(session('loyalty_migration_preview.rows'))->where('valid', false)->count());
        $this->assertDatabaseCount('loyalty_accounts', 0);
    }

    public function test_confirmation_uses_loyalty_infrastructure_and_is_idempotent(): void
    {
        [$company, $branch, $user, $customer] = $this->context(['fidelidad.configuracion']);
        $service = app(LoyaltyMigrationImportService::class);
        $preview = $service->preview($this->file([[$customer->name, '100.1255', '20.0255', '80.1000']], 'xlsx'), $company->id);

        $this->assertSame(1, $service->confirm($preview, $company->id, $user->id));
        $this->assertSame(0, $service->confirm($preview, $company->id, $user->id));

        $account = LoyaltyAccount::where('company_id', $company->id)->where('customer_id', $customer->id)->sole();
        $this->assertSame('80.1000', (string) $account->balance);
        $this->assertSame('100.1255', (string) $account->total_earned);
        $this->assertSame('20.0255', (string) $account->total_redeemed);
        $this->assertDatabaseCount('loyalty_migration_batches', 1);
        $this->assertSame(2, LoyaltyMovement::where('company_id', $company->id)->count());
        $types = LoyaltyMovement::where('company_id', $company->id)->pluck('type')->sort()->values()->all();
        $this->assertSame([LoyaltyMovement::TYPE_PROMOTION, LoyaltyMovement::TYPE_REDEMPTION], $types);
        $this->assertSame(2, LoyaltyMovement::where('company_id', $company->id)->where('source_type', 'LoyaltyMigration')->count());
    }

    public function test_confirmation_rolls_back_account_movements_and_batch_on_failure(): void
    {
        [$company, $branch, $user, $customer] = $this->context(['fidelidad.configuracion']);
        $partial = Mockery::mock(LoyaltyAccountService::class)->makePartial();
        $partial->shouldReceive('subtractPoints')->once()->andThrow(new \RuntimeException('fallo controlado'));
        $service = new LoyaltyMigrationImportService($partial);
        $preview = $service->preview($this->file([[$customer->name, '10.0000', '2.0000', '8.0000']], 'xlsx'), $company->id);

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

    public function test_customer_resolution_is_strictly_isolated_by_company(): void
    {
        [$company, $branch, $user, $customer] = $this->context(['fidelidad.configuracion'], 'Cliente Compartido');
        [$otherCompany, $otherBranch, $otherUser, $otherCustomer] = $this->context([], 'Cliente Compartido');
        $service = app(LoyaltyMigrationImportService::class);
        $preview = $service->preview($this->file([['Cliente Compartido', '5.0000', '1.0000', '4.0000']], 'csv'), $company->id);

        $this->assertTrue($preview['rows'][0]['valid']);
        $this->assertSame($customer->id, $preview['rows'][0]['customer_id']);
        $service->confirm($preview, $company->id, $user->id);
        $this->assertDatabaseHas('loyalty_accounts', ['company_id' => $company->id, 'customer_id' => $customer->id]);
        $this->assertDatabaseMissing('loyalty_accounts', ['company_id' => $otherCompany->id, 'customer_id' => $otherCustomer->id]);
    }

    public function test_existing_operational_account_is_not_overwritten(): void
    {
        [$company, $branch, $user, $customer] = $this->context(['fidelidad.configuracion']);
        LoyaltyAccount::create([
            'company_id' => $company->id, 'customer_id' => $customer->id, 'balance' => '3.0000',
            'total_earned' => '3.0000', 'total_redeemed' => '0.0000', 'total_expired' => '0.0000', 'is_active' => true,
        ]);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('importaciones.fidelidad-migracion.preview'), [
                'migrar_file' => $this->upload($this->file([[$customer->name, '10.0000', '1.0000', '9.0000']], 'xlsx'), 'xlsx'),
            ])->assertOk()->assertSee('no los sobrescribe');

        $this->assertFalse(session('loyalty_migration_preview.rows.0.valid'));
        $this->assertSame('3.0000', (string) LoyaltyAccount::where('company_id', $company->id)->value('balance'));
        $this->assertDatabaseCount('loyalty_movements', 0);
    }

    private function context(array $permissions, ?string $customerName = null): array
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
        $customer = Customer::create([
            'company_id' => $company->id, 'customer_type' => 'individual', 'name' => $customerName ?? 'Cliente '.$suffix,
            'identification_type' => 'national', 'identification' => 'ID'.$suffix, 'is_active' => true,
        ]);

        return [$company, $branch, $user, $customer];
    }

    private function file(array $rows, string $format): string
    {
        $path = tempnam(sys_get_temp_dir(), 'p37-').'.'.$format;
        $spreadsheet = new Spreadsheet;
        foreach (array_merge([LoyaltyMigrationImportService::HEADERS], $rows) as $rowIndex => $values) {
            foreach ($values as $columnIndex => $value) {
                $spreadsheet->getActiveSheet()->setCellValueExplicit(
                    Coordinate::stringFromColumnIndex($columnIndex + 1).($rowIndex + 1),
                    (string) $value,
                    DataType::TYPE_STRING,
                );
            }
        }
        match ($format) {
            'xlsx' => (new Xlsx($spreadsheet))->save($path),
            'xls' => (new Xls($spreadsheet))->save($path),
            'csv' => (new Csv($spreadsheet))->save($path),
        };

        return $path;
    }

    private function upload(string $path, string $format): UploadedFile
    {
        return new UploadedFile($path, 'p37.'.$format, match ($format) {
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xls' => 'application/vnd.ms-excel',
            default => 'text/csv',
        }, null, true);
    }

    private function spreadsheet(string $content): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'p37-sheet-');
        file_put_contents($path, $content);

        return IOFactory::load($path);
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
