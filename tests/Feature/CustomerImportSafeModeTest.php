<?php

namespace Tests\Feature;

use App\Jobs\ProcessCustomerImport;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerImportRow;
use App\Models\CustomerImportRun;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyMovement;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Imports\CustomerImportRunService;
use App\Services\Imports\CustomerImportService;
use App\Services\Loyalty\LoyaltyAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CustomerImportSafeModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
    }

    public static function identifiers(): array
    {
        return [
            'identification' => [['identification' => '00-123.456'], ['identificacion' => '00123456']],
            'phone' => [['phone' => '8888-8888'], ['telefono' => '+506 8888-8888']],
            'mobile' => [['mobile' => '+506 (8888) 8888'], ['movil' => '88888888']],
            'email' => [['email' => ' CLIENTE@EXAMPLE.COM '], ['correo' => 'cliente@example.com']],
            'same customer across all fields' => [['identification' => '001', 'phone' => '88888888', 'email' => 'cliente@example.com'], ['identificacion' => '001', 'movil' => '+50688888888', 'correo' => 'CLIENTE@example.com']],
        ];
    }

    #[DataProvider('identifiers')]
    public function test_existing_is_ignored_and_every_business_field_and_point_is_unchanged(array $stored, array $incoming): void
    {
        [$company, $user] = $this->context();
        $customer = $this->customer($company, $stored);
        $accounts = app(LoyaltyAccountService::class);
        $account = $accounts->getOrCreateAccount($customer, $company);
        $accounts->adjustPoints($account, '17.5000', ['user_id' => $user->id, 'description' => 'Saldo previo']);
        $before = $this->businessSnapshot();
        $run = $this->upload($company, $user, [$incoming + ['nombre*' => 'Nombre distinto', 'puntos_iniciales' => '5000']]);
        $this->drain($run);
        $row = $run->rows()->firstOrFail();
        $this->assertSame('existing', $row->kind);
        $this->assertTrue($row->previewData()['skipped']);
        app(CustomerImportRunService::class)->confirm($run->id, $company->id, $user->id);
        $this->drain($run);
        $this->assertSame($before, $this->businessSnapshot());
        $this->assertSame(1, (int) $run->refresh()->existing_count);
        $this->assertSame(0, (int) $run->created_count);
    }

    public static function conflictIdentifiers(): array
    {
        return ['phone' => [['phone' => '88888888'], ['telefono' => '+50688888888']], 'email' => [['email' => 'otro@example.com'], ['correo' => 'OTRO@example.com']]];
    }

    #[DataProvider('conflictIdentifiers')]
    public function test_conflicts_between_existing_customers_are_rejected_without_contaminating_valid_rows(array $other, array $incoming): void
    {
        [$company, $user] = $this->context();
        $this->customer($company, ['identification' => 'A']);
        $this->customer($company, ['identification' => 'B', ...$other]);
        $run = $this->upload($company, $user, [
            ['identificacion' => 'A', ...$incoming],
            ['identificacion' => 'NUEVO', 'puntos_iniciales' => '1.2345'],
            ['identificacion' => 'ERROR', 'puntos_iniciales' => '-1'],
        ]);
        $this->drain($run);
        $this->assertSame(['conflict', 'new', 'error'], $run->rows()->orderBy('source_row')->pluck('kind')->all());
        app(CustomerImportRunService::class)->confirm($run->id, $company->id, $user->id);
        $this->drain($run);
        $run->refresh();
        $this->assertSame('completed_with_issues', $run->status);
        $this->assertSame(1, (int) $run->created_count);
        $this->assertSame(2, (int) $run->rejected_count);
        $this->assertDatabaseHas('customers', ['company_id' => $company->id, 'identification' => 'NUEVO']);
        $this->assertDatabaseMissing('customers', ['identification' => 'ERROR']);
    }

    public static function fileDuplicates(): array
    {
        return ['identification' => [['identificacion' => '00-01'], ['identificacion' => '0001']], 'phone' => [['telefono' => '88888888'], ['movil' => '+50688888888']], 'email' => [['correo' => 'FIRST@example.com'], ['correo' => 'first@example.com']]];
    }

    #[DataProvider('fileDuplicates')]
    public function test_file_duplicates_keep_first_valid_candidate_and_never_merge(array $first, array $second): void
    {
        [$company, $user] = $this->context();
        $run = $this->upload($company, $user, [
            $first + ['nombre*' => 'Primero', 'direccion' => '', 'puntos_iniciales' => '10'],
            $second + ['nombre*' => 'Segundo', 'direccion' => 'No fusionar', 'puntos_iniciales' => '5000'],
        ]);
        $this->drain($run);
        $this->assertSame(['new', 'duplicate_file'], $run->rows()->orderBy('source_row')->pluck('kind')->all());
        $this->assertSame(2, (int) $run->rows()->where('source_row', 3)->first()->duplicate_of_row);
        app(CustomerImportRunService::class)->confirm($run->id, $company->id, $user->id);
        $this->drain($run);
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseHas('customers', ['name' => 'Primero', 'address' => null]);
        $this->assertSame('10.0000', LoyaltyAccount::first()->balance);
    }

    public function test_cross_row_conflict_and_invalid_first_row_do_not_merge_or_reserve_identity(): void
    {
        [$company, $user] = $this->context();
        $run = $this->upload($company, $user, [
            ['identificacion' => 'A'], ['correo' => 'b@example.com'],
            ['identificacion' => 'A', 'correo' => 'b@example.com'],
            ['identificacion' => 'C', 'puntos_iniciales' => '0.00001'], ['identificacion' => 'C'],
        ]);
        $this->drain($run);
        $this->assertSame(['new', 'new', 'conflict', 'error', 'new'], $run->rows()->orderBy('source_row')->pluck('kind')->all());
    }

    public function test_preview_only_writes_technical_tables_and_never_serializes_rows_into_session(): void
    {
        [$company, $user, $branch] = $this->context();
        // Unrelated license setup occurs before observing preview SQL.
        $company->license()->create(['status' => 'active', 'plan' => 'Prueba']);
        $before = $this->businessSnapshot();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $response = $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->post(route('importaciones.clientes.preview'), ['customer_file' => $this->file([['puntos_iniciales' => '100']])])->assertOk();
        $run = $response->viewData('run');
        $this->assertSame('uploaded', $run->status);
        Queue::assertPushed(ProcessCustomerImport::class, fn ($job) => $job->companyId === $company->id && $job->runId === $run->id);
        $this->drain($run);
        $writes = collect(DB::getQueryLog())->filter(fn ($query) => preg_match('/^\s*(insert|update|delete|replace)\b/i', $query['query']));
        foreach ($writes as $query) {
            $this->assertMatchesRegularExpression('/customer_import_(runs|rows)/', $query['query']);
        }
        DB::disableQueryLog();
        $this->assertSame($before, $this->businessSnapshot());
        $this->assertArrayNotHasKey('rows', session('customer_import_preview'));
        $this->get(route('importaciones.clientes.status', $run->id))->assertOk()->assertSee('Crear 1 clientes nuevos');
    }

    public function test_initial_points_are_auditable_idempotent_and_zero_does_not_create_an_account(): void
    {
        [$company, $user] = $this->context();
        $file = $this->file([
            ['identificacion' => '001234', 'nombre*' => 'María Ñúñez', 'direccion' => 'ÁÉÍÓÚ ñ Ñ', 'puntos_iniciales' => '500.2500'],
            ['identificacion' => 'ZERO', 'puntos_iniciales' => '0'],
            ['nombre*' => 'Sin identificadores', 'puntos_iniciales' => '2'],
        ]);
        $service = app(CustomerImportRunService::class);
        $run = $service->upload($file, $company->id, $user->id);
        $this->drain($run);
        $service->confirm($run->id, $company->id, $user->id);
        $this->drain($run);
        $service->confirm($run->id, $company->id, $user->id);
        (new ProcessCustomerImport($run->id, $company->id))->handle($service);
        $this->assertDatabaseCount('customers', 3);
        $this->assertDatabaseCount('loyalty_accounts', 2);
        $this->assertDatabaseCount('loyalty_movements', 2);
        $movement = LoyaltyMovement::orderBy('id')->first();
        $this->assertSame('500.2500', $movement->points);
        $this->assertSame('adjustment', $movement->type);
        $this->assertSame($company->id, $movement->company_id);
        $this->assertSame($user->id, $movement->user_id);
        $this->assertSame(CustomerImportRun::class, $movement->source_type);
        $this->assertSame($run->id, (int) $movement->source_id);
        $this->assertNotNull($movement->effective_at);
        $this->assertStringStartsWith('p32:', $movement->event_key);
        $this->assertDatabaseHas('customers', ['identification' => '001234', 'name' => 'María Ñúñez', 'address' => 'ÁÉÍÓÚ ñ Ñ']);
        $again = $service->upload($file, $company->id, $user->id);
        $this->drain($again);
        $this->assertSame(3, (int) $again->refresh()->existing_count);
        $service->confirm($again->id, $company->id, $user->id);
        $this->drain($again);
        $this->assertDatabaseCount('customers', 3);
        $this->assertDatabaseCount('loyalty_movements', 2);
    }

    public function test_failure_between_customer_points_and_row_checkpoint_rolls_back_and_retry_is_safe(): void
    {
        [$company, $user] = $this->context();
        $run = $this->upload($company, $user, [['identificacion' => 'RECOVERY', 'puntos_iniciales' => '100']]);
        $this->drain($run);
        $service = app(CustomerImportRunService::class);
        $service->confirm($run->id, $company->id, $user->id);
        $fail = true;
        CustomerImportRow::updating(function ($row) use (&$fail) {
            if ($fail && $row->kind === 'created') {
                throw new RuntimeException('Simulated interruption after customer and points');
            }
        });
        try {
            $service->step($run->id, $company->id);
            $this->fail('The injected interruption must fail the chunk.');
        } catch (RuntimeException $exception) {
            (new ProcessCustomerImport($run->id, $company->id))->failed($exception);
        } finally {
            $fail = false;
        }
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('loyalty_accounts', 0);
        $this->assertDatabaseCount('loyalty_movements', 0);
        $this->assertNull($run->rows()->first()->processed_at);
        $this->assertSame('failed', $run->refresh()->status);
        $service->retry($run->id, $company->id, $user->id);
        $this->drain($run);
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('loyalty_movements', 1);
        $this->assertNotNull($run->rows()->first()->processed_at);
    }

    public function test_revalidation_handles_new_existing_and_new_conflict_at_confirmation(): void
    {
        [$company, $user] = $this->context();
        $run = $this->upload($company, $user, [
            ['identificacion' => 'A'], ['identificacion' => 'B', 'telefono' => '88888888'], ['identificacion' => 'C'],
        ]);
        $this->drain($run);
        $this->customer($company, ['identification' => 'A']);
        $this->customer($company, ['identification' => 'B']);
        $this->customer($company, ['identification' => 'OTHER', 'phone' => '88888888']);
        app(CustomerImportRunService::class)->confirm($run->id, $company->id, $user->id);
        $this->drain($run);
        $this->assertSame(['existing', 'conflict', 'created'], $run->rows()->orderBy('source_row')->pluck('kind')->all());
        $this->assertDatabaseCount('customers', 4);
    }

    public function test_every_http_operation_and_job_is_company_scoped_and_permission_protected(): void
    {
        [$a, $userA, $branchA] = $this->context();
        [$b, $userB, $branchB] = $this->context();
        $run = $this->upload($b, $userB, [['identificacion' => 'B']]);
        $this->actingAs($userA)->withSession(['active_company_id' => $a->id, 'active_branch_id' => $branchA->id]);
        $this->get(route('importaciones.clientes.status', $run->id))->assertNotFound();
        $this->get(route('importaciones.clientes.report', $run->id))->assertNotFound();
        $this->post(route('importaciones.clientes.retry', $run->id))->assertNotFound();
        $this->post(route('importaciones.clientes.import'), ['run_id' => $run->id])->assertNotFound();
        try {
            app(CustomerImportRunService::class)->step($run->id, $a->id);
            $this->fail('Cross-company job must fail.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->assertSame('uploaded', $run->refresh()->status);
        }
        $this->actingAs($userB)->withSession(['active_company_id' => $b->id, 'active_branch_id' => $branchB->id]);
        DB::table('permission_role')->delete();
        $this->get(route('importaciones.clientes.status', $run->id))->assertForbidden();
        $this->get(route('importaciones.clientes.report', $run->id))->assertForbidden();
        $this->post(route('importaciones.clientes.retry', $run->id))->assertForbidden();
        $this->post(route('importaciones.clientes.import'), ['run_id' => $run->id])->assertForbidden();
    }

    public function test_excel_company_id_is_ignored_and_points_belong_only_to_authenticated_company(): void
    {
        [$a, $user] = $this->context();
        [$b] = $this->context();
        $this->customer($b, ['identification' => 'SAME']);
        $file = $this->file([['identificacion' => 'SAME', 'company_id' => $b->id, 'puntos_iniciales' => '99']], [...CustomerImportService::HEADERS, 'company_id']);
        $run = app(CustomerImportRunService::class)->upload($file, $a->id, $user->id);
        $this->drain($run);
        app(CustomerImportRunService::class)->confirm($run->id, $a->id, $user->id);
        $this->drain($run);
        $this->assertDatabaseCount('customers', 2);
        $this->assertDatabaseHas('loyalty_movements', ['company_id' => $a->id, 'points' => '99.0000']);
        $this->assertDatabaseMissing('loyalty_accounts', ['company_id' => $b->id]);
    }

    public function test_purge_removes_private_data_but_keeps_audit_and_reupload_idempotency(): void
    {
        [$company, $user] = $this->context();
        $file = $this->file([['nombre*' => 'Sin contactos', 'puntos_iniciales' => '10']]);
        $service = app(CustomerImportRunService::class);
        $run = $service->upload($file, $company->id, $user->id);
        $this->drain($run);
        $service->confirm($run->id, $company->id, $user->id);
        $this->drain($run);
        $path = $run->refresh()->private_file_path;
        $this->assertFalse($service->purge($run->id, $company->id));
        $this->travel(31)->days();
        $before = $this->businessSnapshot();
        $this->assertTrue($service->purge($run->id, $company->id));
        Storage::disk('local')->assertMissing($path);
        $this->assertNull($run->rows()->first()->data);
        $this->assertNotNull($run->rows()->first()->created_customer_id);
        $this->assertSame($before, $this->businessSnapshot());
        $again = $service->upload($file, $company->id, $user->id);
        $this->drain($again);
        $this->assertSame('existing', $again->rows()->first()->kind);
    }

    public function test_old_csv_without_points_and_migration_rollback_preserve_business_tables(): void
    {
        [$company, $user] = $this->context();
        $file = UploadedFile::fake()->createWithContent('viejo.csv', "tipo_cliente*,nombre*,identificacion\nindividual,María Ñúñez,00123\n");
        $run = app(CustomerImportRunService::class)->upload($file, $company->id, $user->id);
        $this->drain($run);
        $this->assertSame('0', $run->rows()->first()->data['initial_points']);
        $this->assertSame('00123', $run->rows()->first()->data['identification']);
        $before = $this->businessSnapshot();
        (require database_path('migrations/2026_09_10_000002_create_customer_import_rows_table.php'))->down();
        (require database_path('migrations/2026_09_10_000001_create_customer_import_runs_table.php'))->down();
        $this->assertFalse(Schema::hasTable('customer_import_runs'));
        $this->assertFalse(Schema::hasTable('customer_import_rows'));
        $this->assertSame($before, $this->businessSnapshot());
        (require database_path('migrations/2026_09_10_000001_create_customer_import_runs_table.php'))->up();
        (require database_path('migrations/2026_09_10_000002_create_customer_import_rows_table.php'))->up();
    }

    public function test_retry_after_crash_creates_movement_for_existing_account(): void
    {
        [$company, $user] = $this->context();
        $service = app(CustomerImportRunService::class);
        $file = $this->file([['identificacion' => 'RETRY-TEST', 'nombre*' => 'Test', 'puntos_iniciales' => '25.5000']]);
        $run = $service->upload($file, $company->id, $user->id);
        $this->drain($run);
        $service->confirm($run->id, $company->id, $user->id);

        $fail = true;
        CustomerImportRow::updating(function ($row) use (&$fail) {
            if ($fail && $row->kind === 'created') {
                throw new RuntimeException('Simulated crash after customer and points');
            }
        });
        try {
            $service->step($run->id, $company->id);
            $this->fail('Expected exception');
        } catch (RuntimeException $exception) {
            (new ProcessCustomerImport($run->id, $company->id))->failed($exception);
        } finally {
            $fail = false;
        }
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('loyalty_accounts', 0);
        $this->assertDatabaseCount('loyalty_movements', 0);

        $service->retry($run->id, $company->id, $user->id);
        $this->drain($run);

        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('loyalty_accounts', 1);
        $this->assertDatabaseCount('loyalty_movements', 1);
        $movement = LoyaltyMovement::first();
        $this->assertSame('25.5000', $movement->points);
        $this->assertSame('adjustment', $movement->type);
    }

    public function test_missing_temp_file_during_analysis_fails_gracefully(): void
    {
        [$company, $user] = $this->context();
        $service = app(CustomerImportRunService::class);
        $file = $this->file([['identificacion' => 'MISSING-FILE', 'nombre*' => 'Test', 'puntos_iniciales' => '10']]);
        $run = $service->upload($file, $company->id, $user->id);

        Storage::disk('local')->delete($run->refresh()->private_file_path);
        $run->refresh();

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->expectExceptionMessage('El archivo temporal no existe');
        $service->step($run->id, $company->id);
    }

    public function test_purge_handles_missing_physical_file_gracefully(): void
    {
        [$company, $user] = $this->context();
        $file = $this->file([['nombre*' => 'Sin contactos', 'puntos_iniciales' => '10']]);
        $service = app(CustomerImportRunService::class);
        $run = $service->upload($file, $company->id, $user->id);
        $this->drain($run);
        $service->confirm($run->id, $company->id, $user->id);
        $this->drain($run);

        $path = $run->refresh()->private_file_path;
        Storage::disk('local')->delete($path);

        $this->travel(31)->days();
        $before = $this->businessSnapshot();
        $this->assertTrue($service->purge($run->id, $company->id));
        $this->assertNull($run->rows()->first()->data);
        $this->assertNotNull($run->rows()->first()->created_customer_id);
        $this->assertSame($before, $this->businessSnapshot());
    }

    public function test_chunk_reading_handles_large_file_without_loading_all(): void
    {
        [$company, $user] = $this->context();
        $rows = [];
        for ($i = 0; $i < 1100; $i++) {
            $rows[] = ['individual', '01', 'VOLUMEN-'.$i, 'Cliente '.$i];
        }
        $file = $this->file($rows);
        $service = app(CustomerImportRunService::class);
        $run = $service->upload($file, $company->id, $user->id);
        $this->drain($run);
        $this->assertSame(1100, (int) $run->analyzed_rows);
        $this->assertGreaterThan(1, (int) $run->attempts);
        $this->assertDatabaseCount('customer_import_rows', 1100);
    }

    private function drain(CustomerImportRun $run): void
    {
        $service = app(CustomerImportRunService::class);
        while ($service->step($run->id, $run->company_id)) {
        }
        $run->refresh();
    }

    private function upload(Company $company, User $user, array $rows): CustomerImportRun
    {
        return app(CustomerImportRunService::class)->upload($this->file($rows), $company->id, $user->id);
    }

    private function file(array $rows, ?array $headers = null): UploadedFile
    {
        $headers ??= CustomerImportService::HEADERS;
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([$headers]);
        foreach ($rows as $index => $data) {
            $data += ['tipo_cliente*' => 'individual', 'nombre*' => 'Cliente', 'codigo_pais' => '+506'];
            foreach ($headers as $column => $field) {
                $sheet->setCellValueExplicit([$column + 1, $index + 2], (string) ($data[$field] ?? ''), DataType::TYPE_STRING);
            }
        }
        ob_start();
        (new Xlsx($book))->save('php://output');
        $bytes = ob_get_clean();
        $book->disconnectWorksheets();

        return UploadedFile::fake()->createWithContent('clientes.xlsx', $bytes);
    }

    private function customer(Company $company, array $data = []): Customer
    {
        return Customer::create($data + ['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Original', 'phone_country_code' => '+506', 'is_active' => true]);
    }

    private function businessSnapshot(): array
    {
        return collect(['customers', 'loyalty_accounts', 'loyalty_movements'])->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    private function context(): array
    {
        $company = Company::create(['trade_name' => 'Clientes', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'default_phone_country_code' => '+506', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'PRI', 'is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Importador', 'is_active' => true]);
        $permission = Permission::firstOrCreate(['name' => 'clientes.crear'], ['label' => 'Crear clientes', 'module' => 'Clientes', 'is_active' => true]);
        $role->permissions()->attach($permission);
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return [$company, $user, $branch];
    }
}
