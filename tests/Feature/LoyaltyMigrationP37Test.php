<?php

namespace Tests\Feature;

use App\Jobs\ProcessLoyaltyMigration;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyMigrationRun;
use App\Models\LoyaltyMovement;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Imports\LoyaltyMigrationImportService;
use App\Services\Loyalty\LoyaltyAccountService;
use App\Services\PhoneNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
        $duplicate = Customer::create([
            'company_id' => $company->id, 'customer_type' => 'individual', 'name' => ' nombre   duplicado ',
            'identification_type' => 'national', 'identification' => 'OTRA', 'phone' => '2222-3333',
            'email' => 'duplicado@example.test', 'is_active' => true,
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
            ->assertSee('OTRA')->assertSee('2222-3333')
            ->assertSee('duplicado@example.test');
        $this->assertSame(3, collect(session('loyalty_migration_preview.rows'))->where('valid', false)->count());
        $this->assertSame(0, collect(session('loyalty_migration_preview.rows'))->where('valid', true)->count());
        $this->assertStringContainsString(
            'no se importará parcialmente',
            collect(session('loyalty_migration_preview.rows.2.errors'))->pluck('message')->join(' '),
        );
        $this->assertEqualsCanonicalizing([$customer->id, $duplicate->id], collect(session('loyalty_migration_preview.rows.1.customer_candidates'))->pluck('id')->all());
        $this->assertDatabaseCount('loyalty_accounts', 0);
    }

    public function test_manual_customer_selection_allows_import_and_remains_idempotent(): void
    {
        [$company, $branch, $user, $first] = $this->context(['fidelidad.configuracion'], 'Cliente Ambiguo');
        $selected = Customer::create([
            'company_id' => $company->id, 'customer_type' => 'individual', 'name' => ' cliente ambiguo ',
            'identification_type' => 'national', 'identification' => 'ELEGIDO', 'is_active' => true,
        ]);
        $service = app(LoyaltyMigrationImportService::class);
        $preview = $service->preview($this->file([['Cliente Ambiguo', '0', '0', '15.0000']], 'xlsx'), $company->id);

        $this->assertFalse($preview['rows'][0]['valid']);
        $this->actingAs($user)->withSession($this->activeSession($company, $branch) + ['loyalty_migration_preview' => $preview])
            ->post(route('importaciones.fidelidad-migracion.resolve'), ['selections' => ['2' => $selected->id]])
            ->assertOk()->assertSee('ELEGIDO')->assertSee('Importar 1 clientes listos');
        $resolved = session('loyalty_migration_preview');
        $this->assertTrue($resolved['rows'][0]['valid']);
        $this->assertSame($selected->id, $resolved['rows'][0]['customer_id']);
        $this->assertSame(1, $service->confirm($resolved, $company->id, $user->id));
        $this->assertSame(0, $service->confirm($resolved, $company->id, $user->id));
        $this->assertDatabaseHas('loyalty_accounts', ['company_id' => $company->id, 'customer_id' => $selected->id, 'balance' => 15]);
        $this->assertDatabaseMissing('loyalty_accounts', ['company_id' => $company->id, 'customer_id' => $first->id]);
    }

    public function test_manual_selection_persists_when_preview_is_regenerated_for_the_same_file(): void
    {
        [$company, $branch, $user] = $this->context(['fidelidad.configuracion'], 'Cliente Ambiguo');
        $selected = Customer::create([
            'company_id' => $company->id, 'customer_type' => 'individual', 'name' => ' cliente ambiguo ',
            'identification_type' => 'national', 'identification' => 'PERSISTE', 'is_active' => true,
        ]);
        $path = $this->file([['Cliente Ambiguo', '5.0000', '1.0000', '4.0000']], 'xlsx');

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('importaciones.fidelidad-migracion.preview'), ['migrar_file' => $this->upload($path, 'xlsx')])
            ->assertOk();
        $this->post(route('importaciones.fidelidad-migracion.resolve'), ['selections' => ['2' => $selected->id]])
            ->assertOk();
        $this->assertDatabaseHas('loyalty_migration_manual_resolutions', [
            'company_id' => $company->id,
            'row_number' => 2,
            'normalized_name' => 'cliente ambiguo',
            'customer_id' => $selected->id,
        ]);
        $this->get(route('importaciones.fidelidad-migracion'))->assertOk();
        $this->post(route('importaciones.fidelidad-migracion.preview'), ['migrar_file' => $this->upload($path, 'xlsx')])
            ->assertOk()->assertSee('PERSISTE');

        $this->assertTrue(session('loyalty_migration_preview.rows.0.valid'));
        $this->assertSame($selected->id, session('loyalty_migration_preview.rows.0.customer_id'));
    }

    public function test_legacy_source_key_and_manual_resolution_survive_internal_consolidation_changes(): void
    {
        [$company, $branch, $user] = $this->context(['fidelidad.configuracion'], 'Cliente Ambiguo');
        $selected = Customer::create([
            'company_id' => $company->id, 'customer_type' => 'individual', 'name' => ' cliente ambiguo ',
            'identification_type' => 'national', 'identification' => 'RESUELTO', 'is_active' => true,
        ]);
        $repeated = Customer::create([
            'company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente Repetido',
            'identification_type' => 'national', 'identification' => 'REPETIDO', 'is_active' => true,
        ]);
        $sourceRows = [
            ['Cliente Ambiguo', '5.0000', '1.0000', '4.0000'],
            [$repeated->name, '10.0000', '2.0000', '8.0000'],
            [$repeated->name, '5.0000', '1.0000', '4.0000'],
        ];
        $path = $this->file($sourceRows, 'xlsx');
        $legacyPayload = [
            ['cliente ambiguo', '5.0000', '1.0000', '4.0000'],
            ['cliente repetido', '10.0000', '2.0000', '8.0000'],
            ['cliente repetido', '5.0000', '1.0000', '4.0000'],
        ];
        $legacyKey = 'P37-SIMPLE-'.strtoupper(substr(hash('sha256', json_encode($legacyPayload, JSON_THROW_ON_ERROR)), 0, 40));

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('importaciones.fidelidad-migracion.preview'), ['migrar_file' => $this->upload($path, 'xlsx')])
            ->assertOk();
        $this->assertSame($legacyKey, session('loyalty_migration_preview.source_key'));
        $this->assertSame(2, count(session('loyalty_migration_preview.rows')));

        $this->post(route('importaciones.fidelidad-migracion.resolve'), ['selections' => ['2' => $selected->id]])->assertOk();
        $this->get(route('importaciones.fidelidad-migracion'))->assertOk();
        $this->post(route('importaciones.fidelidad-migracion.preview'), ['migrar_file' => $this->upload($path, 'xlsx')])->assertOk();

        $preview = session('loyalty_migration_preview');
        $this->assertSame($legacyKey, $preview['source_key']);
        $this->assertSame($selected->id, collect($preview['rows'])->firstWhere('row_number', 2)['customer_id']);
        $this->assertSame(0, collect($preview['rows'])->filter(fn (array $row) => count($row['customer_candidates'] ?? []) > 1 && ! $row['customer_id'])->count());
        $this->assertSame(1, collect($preview['rows'])->where('consolidation_method', 'historical_totals_sum')->count());
    }

    public function test_manual_selection_does_not_pass_to_a_different_file(): void
    {
        [$company, $branch, $user] = $this->context(['fidelidad.configuracion'], 'Cliente Ambiguo');
        $selected = Customer::create([
            'company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente Ambiguo',
            'identification_type' => 'national', 'identification' => 'ARCHIVO1', 'is_active' => true,
        ]);
        $firstPath = $this->file([['Cliente Ambiguo', '5.0000', '1.0000', '4.0000']], 'csv');

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('importaciones.fidelidad-migracion.preview'), ['migrar_file' => $this->upload($firstPath, 'csv')]);
        $this->post(route('importaciones.fidelidad-migracion.resolve'), ['selections' => ['2' => $selected->id]])->assertOk();
        $this->post(route('importaciones.fidelidad-migracion.preview'), [
            'migrar_file' => $this->upload($this->file([['Cliente Ambiguo', '6.0000', '1.0000', '5.0000']], 'csv'), 'csv'),
        ])->assertOk();

        $this->assertFalse(session('loyalty_migration_preview.rows.0.valid'));
        $this->assertNull(session('loyalty_migration_preview.rows.0.customer_id'));
    }

    public function test_changed_or_deleted_customer_invalidates_persisted_manual_selection(): void
    {
        [$company, $branch, $user] = $this->context(['fidelidad.configuracion'], 'Cliente Ambiguo');
        $selected = Customer::create([
            'company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente Ambiguo',
            'identification_type' => 'national', 'identification' => 'INVALIDA', 'is_active' => true,
        ]);
        $path = $this->file([['Cliente Ambiguo', '5.0000', '1.0000', '4.0000']], 'xlsx');

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('importaciones.fidelidad-migracion.preview'), ['migrar_file' => $this->upload($path, 'xlsx')]);
        $this->post(route('importaciones.fidelidad-migracion.resolve'), ['selections' => ['2' => $selected->id]])->assertOk();
        $selected->update(['name' => 'Cliente Renombrado']);
        $this->post(route('importaciones.fidelidad-migracion.preview'), ['migrar_file' => $this->upload($path, 'xlsx')])->assertOk();

        $this->assertFalse(session('loyalty_migration_preview.rows.0.valid'));
        $this->assertSame($selected->id, session('loyalty_migration_preview.rows.0.customer_id'));

        $selected->update(['name' => 'Cliente Ambiguo']);
        $selected->delete();
        $this->post(route('importaciones.fidelidad-migracion.preview'), ['migrar_file' => $this->upload($path, 'xlsx')])->assertOk();

        $this->assertFalse(session('loyalty_migration_preview.rows.0.valid'));
        $this->assertSame($selected->id, session('loyalty_migration_preview.rows.0.customer_id'));
    }

    public function test_persisted_manual_selection_is_isolated_by_company(): void
    {
        [$company, $branch, $user] = $this->context(['fidelidad.configuracion'], 'Cliente Ambiguo');
        $selected = Customer::create([
            'company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente Ambiguo',
            'identification_type' => 'national', 'identification' => 'EMPRESA1', 'is_active' => true,
        ]);
        $path = $this->file([['Cliente Ambiguo', '5.0000', '1.0000', '4.0000']], 'csv');
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('importaciones.fidelidad-migracion.preview'), ['migrar_file' => $this->upload($path, 'csv')]);
        $this->post(route('importaciones.fidelidad-migracion.resolve'), ['selections' => ['2' => $selected->id]])->assertOk();

        [$otherCompany, $otherBranch, $otherUser] = $this->context(['fidelidad.configuracion'], 'Cliente Ambiguo');
        Customer::create([
            'company_id' => $otherCompany->id, 'customer_type' => 'individual', 'name' => 'Cliente Ambiguo',
            'identification_type' => 'national', 'identification' => 'EMPRESA2', 'is_active' => true,
        ]);
        $this->actingAs($otherUser)->withSession($this->activeSession($otherCompany, $otherBranch))
            ->post(route('importaciones.fidelidad-migracion.preview'), ['migrar_file' => $this->upload($path, 'csv')])
            ->assertOk();

        $this->assertFalse(session('loyalty_migration_preview.rows.0.valid'));
        $this->assertNull(session('loyalty_migration_preview.rows.0.customer_id'));
    }

    public function test_compatible_historical_repeated_rows_are_consolidated(): void
    {
        [$company, $branch] = $this->context([], 'Cliente Repetido');
        $preview = app(LoyaltyMigrationImportService::class)->preview($this->file([
            ['Cliente Repetido', '5.0000', '1.0000', '4.0000'],
            ['Cliente Repetido', '7.0000', '2.0000', '5.0000'],
        ], 'xlsx'), $company->id);

        $this->assertCount(1, $preview['rows']);
        $this->assertTrue($preview['rows'][0]['valid']);
        $this->assertSame('12.0000', $preview['rows'][0]['awarded_points']);
        $this->assertSame('3.0000', $preview['rows'][0]['used_points']);
        $this->assertSame('9.0000', $preview['rows'][0]['balance']);
        $this->assertSame('historical_totals_sum', $preview['rows'][0]['consolidation_method']);
        $this->assertSame([2, 3], $preview['rows'][0]['source_row_numbers']);
    }

    public function test_compatible_legacy_duplicates_keep_one_snapshot_without_adding_balances(): void
    {
        [$company, $branch, $user, $customer] = $this->context([], 'Cliente Snapshot');
        $service = app(LoyaltyMigrationImportService::class);
        $preview = $service->preview($this->file([
            [$customer->name, '0', '0', '25.5000'],
            [$customer->name, '0.0000', '0.0000', '25.5000'],
        ], 'xlsx'), $company->id);

        $this->assertCount(1, $preview['rows']);
        $this->assertSame('25.5000', $preview['rows'][0]['balance']);
        $this->assertSame('legacy_snapshot_identical', $preview['rows'][0]['consolidation_method']);
        $this->assertSame(1, $service->confirm($preview, $company->id, $user->id));
        $this->assertSame('25.5000', (string) LoyaltyAccount::where('customer_id', $customer->id)->value('balance'));
        $this->assertDatabaseCount('loyalty_movements', 1);
    }

    public function test_incompatible_legacy_duplicates_remain_pending_with_reason(): void
    {
        [$company, $branch, $user, $customer] = $this->context([], 'Cliente Snapshot');
        $preview = app(LoyaltyMigrationImportService::class)->preview($this->file([
            [$customer->name, '0', '0', '25.5000'],
            [$customer->name, '0', '0', '30.0000'],
        ], 'csv'), $company->id);

        $this->assertCount(1, $preview['rows']);
        $this->assertFalse($preview['rows'][0]['valid']);
        $this->assertSame('incompatible', $preview['rows'][0]['consolidation_method']);
        $this->assertStringContainsString('saldos finales distintos', $preview['rows'][0]['errors'][0]['message']);
    }

    public function test_ambiguous_customer_is_resolved_by_unique_optional_identification_without_changing_template(): void
    {
        [$company, $branch, $user, $first] = $this->context([], 'Cliente Ambiguo');
        $selected = Customer::create([
            'company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente Ambiguo',
            'identification_type' => 'national', 'identification' => 'ID-EVIDENCIA', 'phone' => '8888-9999',
            'email' => 'evidencia@example.test', 'is_active' => true,
        ]);
        $headers = array_merge(LoyaltyMigrationImportService::HEADERS, ['IDENTIFICACION', 'TELEFONO', 'EMAIL']);
        $preview = app(LoyaltyMigrationImportService::class)->preview($this->fileWithHeaders($headers, [[
            'Cliente Ambiguo', '5.0000', '1.0000', '4.0000', 'ID-EVIDENCIA', '0000-0000', 'otro@example.test',
        ]], 'xlsx'), $company->id);

        $this->assertTrue($preview['rows'][0]['valid']);
        $this->assertSame($selected->id, $preview['rows'][0]['customer_id']);
        $this->assertSame('identification', $preview['rows'][0]['resolution_method']);
        $this->assertNotSame($first->id, $preview['rows'][0]['customer_id']);
    }

    public function test_ambiguous_customer_without_source_evidence_still_requires_manual_selection(): void
    {
        [$company] = $this->context([], 'Cliente Ambiguo');
        Customer::create([
            'company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente Ambiguo',
            'identification_type' => 'national', 'identification' => 'OTRO', 'is_active' => true,
        ]);
        $preview = app(LoyaltyMigrationImportService::class)->preview($this->file([
            ['Cliente Ambiguo', '5.0000', '1.0000', '4.0000'],
        ], 'xlsx'), $company->id);

        $this->assertFalse($preview['rows'][0]['valid']);
        $this->assertNull($preview['rows'][0]['customer_id']);
        $this->assertCount(2, $preview['rows'][0]['customer_candidates']);
    }

    public function test_partial_confirmation_imports_valid_customer_and_traces_missing_customer_as_pending(): void
    {
        Queue::fake();
        [$company, $branch, $user, $customer] = $this->context(['fidelidad.configuracion'], 'Cliente Válido');
        $path = $this->file([
            [$customer->name, '10.0000', '2.0000', '8.0000'],
            ['Cliente Inexistente', '7.0000', '1.0000', '6.0000'],
        ], 'xlsx');

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('importaciones.fidelidad-migracion.preview'), ['migrar_file' => $this->upload($path, 'xlsx')])
            ->assertOk()->assertSee('Importar 1 clientes listos')->assertSee('Pendiente');
        $this->post(route('importaciones.fidelidad-migracion.import'))
            ->assertRedirect(route('importaciones.fidelidad-migracion.status', 1));
        Queue::assertPushed(ProcessLoyaltyMigration::class, 1);
        app(ProcessLoyaltyMigration::class, ['runId' => 1])->handle(app(LoyaltyMigrationImportService::class));

        $this->assertDatabaseHas('loyalty_accounts', ['company_id' => $company->id, 'customer_id' => $customer->id, 'balance' => 8]);
        $this->assertDatabaseCount('loyalty_migration_pending_rows', 1);
        $pending = DB::table('loyalty_migration_pending_rows')->sole();
        $this->assertStringContainsString('Cliente Inexistente', $pending->source_data);
        $this->assertStringContainsString('no existe', $pending->reasons);
        $this->assertDatabaseCount('customers', 1);
    }

    public function test_consolidated_partial_confirmation_is_idempotent_without_duplicate_movements_or_pending_rows(): void
    {
        [$company, $branch, $user, $customer] = $this->context([], 'Cliente Histórico');
        $service = app(LoyaltyMigrationImportService::class);
        $preview = $service->preview($this->file([
            [$customer->name, '10.0000', '2.0000', '8.0000'],
            [$customer->name, '5.0000', '1.0000', '4.0000'],
            ['No Existe', '3.0000', '1.0000', '2.0000'],
        ], 'csv'), $company->id);

        $this->assertSame(1, $service->confirm($preview, $company->id, $user->id));
        $this->assertSame(0, $service->confirm($preview, $company->id, $user->id));
        $this->assertSame('12.0000', (string) LoyaltyAccount::where('customer_id', $customer->id)->value('balance'));
        $this->assertDatabaseCount('loyalty_movements', 2);
        $this->assertDatabaseCount('loyalty_migration_pending_rows', 1);
        $this->assertDatabaseCount('loyalty_migration_batches', 1);
    }

    public function test_manual_selection_rejects_customer_from_another_company(): void
    {
        [$company, $branch, $user] = $this->context([], 'Cliente Ambiguo');
        Customer::create([
            'company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente Ambiguo',
            'identification_type' => 'national', 'identification' => 'LOCAL2', 'is_active' => true,
        ]);
        [$otherCompany, $otherBranch, $otherUser, $foreign] = $this->context([], 'Cliente Ambiguo');
        $service = app(LoyaltyMigrationImportService::class);
        $preview = $service->preview($this->file([['Cliente Ambiguo', '5.0000', '1.0000', '4.0000']], 'csv'), $company->id);
        $resolved = $service->resolveCustomers($preview, $company->id, ['2' => $foreign->id]);

        $this->assertFalse($resolved['rows'][0]['valid']);
        $this->assertSame($foreign->id, $resolved['rows'][0]['customer_id']);
        $this->assertStringContainsString('cambió', $resolved['rows'][0]['errors'][0]['message']);
        $this->assertNotContains($foreign->id, collect($resolved['rows'][0]['customer_candidates'])->pluck('id')->all());
    }

    public function test_confirmation_blocks_when_manually_selected_customer_changes_after_preview(): void
    {
        [$company, $branch, $user] = $this->context([], 'Cliente Ambiguo');
        $selected = Customer::create([
            'company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente Ambiguo',
            'identification_type' => 'national', 'identification' => 'CAMBIA', 'is_active' => true,
        ]);
        $service = app(LoyaltyMigrationImportService::class);
        $preview = $service->preview($this->file([['Cliente Ambiguo', '5.0000', '1.0000', '4.0000']], 'xlsx'), $company->id);
        $resolved = $service->resolveCustomers($preview, $company->id, ['2' => $selected->id]);
        $selected->update(['name' => 'Cliente Renombrado']);

        try {
            $service->confirm($resolved, $company->id, $user->id);
            $this->fail('La confirmación debía bloquear al cliente seleccionado que cambió.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('cambió', $exception->errors()['migrar_file'][0]);
        }

        $this->assertDatabaseCount('loyalty_accounts', 0);
        $this->assertDatabaseCount('loyalty_migration_batches', 0);
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

    public function test_legacy_initial_balance_creates_one_traceable_migration_movement_without_inventing_history(): void
    {
        [$company, $branch, $user, $customer] = $this->context(['fidelidad.configuracion']);
        $service = app(LoyaltyMigrationImportService::class);
        $preview = $service->preview($this->file([[$customer->name, '0', '0.0000', '87.4321']], 'xlsx'), $company->id);

        $this->assertTrue($preview['rows'][0]['valid']);
        $this->assertSame(1, $service->confirm($preview, $company->id, $user->id));
        $this->assertSame(0, $service->confirm($preview, $company->id, $user->id));

        $account = LoyaltyAccount::where('company_id', $company->id)->where('customer_id', $customer->id)->sole();
        $this->assertSame('87.4321', (string) $account->balance);
        $this->assertSame('0.0000', (string) $account->total_earned);
        $this->assertSame('0.0000', (string) $account->total_redeemed);

        $movement = LoyaltyMovement::where('company_id', $company->id)->sole();
        $this->assertSame(LoyaltyMovement::TYPE_ADJUSTMENT, $movement->type);
        $this->assertSame('87.4321', (string) $movement->points);
        $this->assertSame('LoyaltyMigration', $movement->source_type);
        $this->assertSame('legacy_initial_balance', $movement->metadata['kind']);
        $this->assertDatabaseCount('loyalty_migration_batches', 1);
    }

    public function test_preview_safely_repairs_spanish_enye_mojibake_for_customer_matching(): void
    {
        [$company, $branch, $user, $bolanos] = $this->context([], 'BOLAÑOS');
        $zuniga = Customer::create([
            'company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'ZUÑIGA',
            'identification_type' => 'national', 'identification' => 'MOJI1', 'is_active' => true,
        ]);
        $nunez = Customer::create([
            'company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'NUÑEZ',
            'identification_type' => 'national', 'identification' => 'MOJI2', 'is_active' => true,
        ]);

        $preview = app(LoyaltyMigrationImportService::class)->preview($this->file([
            ["BOLA\u{00C3}\u{2018}OS", '0', '0', '5.0000'],
            ["ZU\u{00C3}\u{2018}IGA", '10.0000', '2.0000', '8.0000'],
            ["NU\u{00C3}\u{2018}EZ", '3.0000', '1.0000', '2.0000'],
        ], 'xlsx'), $company->id);

        $this->assertCount(3, collect($preview['rows'])->where('valid', true));
        $this->assertSame([$bolanos->id, $zuniga->id, $nunez->id], collect($preview['rows'])->pluck('customer_id')->all());
    }

    public function test_preview_normalizes_reversible_legacy_accent_and_apostrophe_artifacts_without_fuzzy_matching(): void
    {
        [$company, $branch, $user, $customer] = $this->context([], 'BREIDIY PINTO CAÑAS');
        $service = app(LoyaltyMigrationImportService::class);
        $variants = [
            "BREIDIY \u{00C2}\u{00B4}PINTO CA\u{00C3}\u{2018}AS",
            'BREIDIY ´PINTO CAÑAS',
            'BREIDIY ’PINTO CAÑAS',
            "BREIDIY 'PINTO CAÑAS",
            'BREIDIY PINTO CAÑAS',
        ];

        foreach ($variants as $name) {
            $preview = $service->preview($this->file([[$name, '0', '0', '5.0000']], 'xlsx'), $company->id);

            $this->assertTrue($preview['rows'][0]['valid'], "La variante {$name} debe resolver por equivalencia tipográfica exacta.");
            $this->assertSame($customer->id, $preview['rows'][0]['customer_id']);
            $this->assertSame($name, $preview['rows'][0]['name']);
            $this->assertSame('breidiy pinto canas', $preview['rows'][0]['normalized_name']);
        }
    }

    public function test_confirmation_rolls_back_account_movements_and_batch_on_failure(): void
    {
        [$company, $branch, $user, $customer] = $this->context(['fidelidad.configuracion']);
        $partial = Mockery::mock(LoyaltyAccountService::class)->makePartial();
        $partial->shouldReceive('subtractPoints')->once()->andThrow(new \RuntimeException('fallo controlado'));
        $service = new LoyaltyMigrationImportService($partial, app(PhoneNumberService::class));
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

    public function test_preview_indexes_a_large_company_customer_list_with_one_query(): void
    {
        [$company] = $this->context([]);
        $now = now();
        $customers = [];
        for ($index = 0; $index < 1999; $index++) {
            $customers[] = [
                'company_id' => $company->id,
                'customer_type' => 'individual',
                'name' => 'Cliente volumen '.$index,
                'identification_type' => 'national',
                'identification' => 'VOL'.$index,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($customers, 500) as $chunk) {
            DB::table('customers')->insert($chunk);
        }

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains(Str::lower($query->sql), 'from "customers"')) {
                $queries[] = $query->sql;
            }
        });

        $rows = [];
        for ($index = 0; $index < 100; $index++) {
            $rows[] = ['Cliente inexistente '.$index, '10.0000', '1.0000', '9.0000'];
        }

        $preview = app(LoyaltyMigrationImportService::class)
            ->preview($this->file($rows, 'csv'), $company->id);

        $this->assertCount(100, $preview['rows']);
        $this->assertCount(100, collect($preview['rows'])->where('valid', false));
        $this->assertCount(1, $queries, 'P37 debe cargar e indexar los clientes una sola vez por vista previa.');
    }

    public function test_mass_confirmation_queues_thousands_and_double_submit_dispatches_only_once(): void
    {
        Queue::fake();
        [$company, $branch, $user] = $this->context(['fidelidad.configuracion']);
        $sourceKey = 'P37-SIMPLE-'.str_repeat('A', 40);
        $rows = [];
        for ($index = 1; $index <= 2000; $index++) {
            $rows[] = [
                'row_number' => $index + 1,
                'source_key' => $sourceKey,
                'valid' => true,
                'consolidated_count' => 1,
            ];
        }
        $preview = ['company_id' => $company->id, 'source_key' => $sourceKey, 'rows' => $rows];

        $this->actingAs($user)->withSession($this->activeSession($company, $branch) + ['loyalty_migration_preview' => $preview])
            ->post(route('importaciones.fidelidad-migracion.import'))
            ->assertRedirect(route('importaciones.fidelidad-migracion.status', 1));
        $this->withSession(['loyalty_migration_preview' => $preview])
            ->post(route('importaciones.fidelidad-migracion.import'))
            ->assertRedirect(route('importaciones.fidelidad-migracion.status', 1));

        Queue::assertPushed(ProcessLoyaltyMigration::class, 1);
        $this->assertDatabaseHas('loyalty_migration_runs', [
            'company_id' => $company->id,
            'source_key' => $sourceKey,
            'status' => LoyaltyMigrationRun::STATUS_PENDING,
            'valid_count' => 2000,
        ]);
        $this->assertDatabaseCount('loyalty_migration_batches', 0);
    }

    public function test_failed_async_run_can_be_retried_but_completed_run_is_not_dispatched_again(): void
    {
        Queue::fake();
        [$company, $branch, $user, $customer] = $this->context(['fidelidad.configuracion']);
        $service = app(LoyaltyMigrationImportService::class);
        $preview = $service->preview($this->file([[$customer->name, '5.0000', '1.0000', '4.0000']], 'csv'), $company->id);
        $run = $service->enqueue($preview, $company->id, $user->id);
        Queue::assertPushed(ProcessLoyaltyMigration::class, 1);
        $run->update(['status' => LoyaltyMigrationRun::STATUS_FAILED, 'last_error' => 'fallo previo', 'failed_at' => now()]);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('importaciones.fidelidad-migracion.retry', $run))
            ->assertRedirect(route('importaciones.fidelidad-migracion.status', $run));
        Queue::assertPushed(ProcessLoyaltyMigration::class, 2);
        $this->assertDatabaseHas('loyalty_migration_runs', ['id' => $run->id, 'status' => LoyaltyMigrationRun::STATUS_PENDING, 'last_error' => null]);

        $run->update(['status' => LoyaltyMigrationRun::STATUS_COMPLETED]);
        $this->post(route('importaciones.fidelidad-migracion.retry', $run));
        Queue::assertPushed(ProcessLoyaltyMigration::class, 2);
    }

    public function test_async_failure_rolls_back_import_and_records_the_real_error(): void
    {
        Queue::fake();
        [$company, $branch, $user, $customer] = $this->context(['fidelidad.configuracion']);
        $preview = app(LoyaltyMigrationImportService::class)
            ->preview($this->file([[$customer->name, '10.0000', '2.0000', '8.0000']], 'xlsx'), $company->id);
        $run = app(LoyaltyMigrationImportService::class)->enqueue($preview, $company->id, $user->id);
        $accounts = Mockery::mock(LoyaltyAccountService::class)->makePartial();
        $accounts->shouldReceive('subtractPoints')->once()->andThrow(new \RuntimeException('fallo real del worker'));
        $faultyImport = new LoyaltyMigrationImportService($accounts, app(PhoneNumberService::class));

        try {
            (new ProcessLoyaltyMigration($run->id))->handle($faultyImport);
            $this->fail('El Job debía propagar el error al worker.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('fallo real del worker', $exception->getMessage());
        }

        $run->refresh();
        $this->assertSame(LoyaltyMigrationRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('fallo real del worker', $run->last_error);
        $this->assertSame(1, $run->attempts);
        $this->assertDatabaseCount('loyalty_accounts', 0);
        $this->assertDatabaseCount('loyalty_movements', 0);
        $this->assertDatabaseCount('loyalty_migration_batches', 0);
        $this->assertDatabaseCount('loyalty_migration_pending_rows', 0);
    }

    public function test_async_completion_imports_valid_rows_preserves_pending_and_is_idempotent(): void
    {
        Queue::fake();
        [$company, $branch, $user, $customer] = $this->context(['fidelidad.configuracion'], 'Cliente Asíncrono');
        $service = app(LoyaltyMigrationImportService::class);
        $preview = $service->preview($this->file([
            [$customer->name, '10.0000', '2.0000', '8.0000'],
            ['Cliente inexistente', '7.0000', '1.0000', '6.0000'],
        ], 'xlsx'), $company->id);
        $run = $service->enqueue($preview, $company->id, $user->id);
        $job = new ProcessLoyaltyMigration($run->id);

        $job->handle($service);
        $job->handle($service);

        $run->refresh();
        $this->assertSame(LoyaltyMigrationRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(1, $run->imported_count);
        $this->assertSame(1, $run->pending_count);
        $this->assertSame(1, $run->attempts);
        $this->assertDatabaseHas('loyalty_accounts', ['company_id' => $company->id, 'customer_id' => $customer->id, 'balance' => 8]);
        $this->assertDatabaseCount('loyalty_movements', 2);
        $this->assertDatabaseCount('loyalty_migration_batches', 1);
        $this->assertDatabaseCount('loyalty_migration_pending_rows', 1);
    }

    public function test_async_run_status_is_isolated_by_active_company(): void
    {
        Queue::fake();
        [$company, $branch, $user, $customer] = $this->context(['fidelidad.configuracion']);
        [$otherCompany, $otherBranch, $otherUser] = $this->context(['fidelidad.configuracion']);
        $service = app(LoyaltyMigrationImportService::class);
        $preview = $service->preview($this->file([[$customer->name, '5', '1', '4']], 'csv'), $company->id);
        $run = $service->enqueue($preview, $company->id, $user->id);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('importaciones.fidelidad-migracion.status', $run))
            ->assertOk()->assertSee('Pendiente')->assertSee($run->source_key);
        $this->actingAs($otherUser)->withSession($this->activeSession($otherCompany, $otherBranch))
            ->get(route('importaciones.fidelidad-migracion.status', $run))
            ->assertNotFound();
        $this->post(route('importaciones.fidelidad-migracion.retry', $run))->assertNotFound();
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
        return $this->fileWithHeaders(LoyaltyMigrationImportService::HEADERS, $rows, $format);
    }

    private function fileWithHeaders(array $headers, array $rows, string $format): string
    {
        $path = tempnam(sys_get_temp_dir(), 'p37-').'.'.$format;
        $spreadsheet = new Spreadsheet;
        foreach (array_merge([$headers], $rows) as $rowIndex => $values) {
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
