<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyBackupRecord;
use App\Models\CompanyBackupSetting;
use App\Models\Role;
use App\Models\User;
use App\Services\Backups\CompanyBackupRunner;
use App\Services\Backups\CompanyBackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FakeCompanyBackupRunner extends CompanyBackupRunner
{
    public array $calls = [];

    public int $exitCode = 0;

    public function run(array $command, array $env = []): array
    {
        $this->calls[] = ['command' => $command, 'env' => $env];

        return ['exit_code' => $this->exitCode, 'output' => 'fake-output'];
    }
}

class PlatformCompanyBackupsTest extends TestCase
{
    use RefreshDatabase;

    private FakeCompanyBackupRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner = new FakeCompanyBackupRunner();
        $this->app->instance(CompanyBackupRunner::class, $this->runner);
    }

    public function test_backup_settings_are_isolated_per_company(): void
    {
        $platformAdmin = $this->platformAdmin();
        [$companyA] = $this->tenant('Empresa A', 'a-owner@backup.test');
        [$companyB] = $this->tenant('Empresa B', 'b-owner@backup.test');

        $this->actingAs($platformAdmin)->patch(route('platform.backups.update', $companyA), [
            'is_enabled' => 1,
            'plan' => 'estandar',
            'frequency' => 'semanal',
            'retention_days' => 60,
            'manual_backup_allowed' => 1,
            'external_copy' => 'preparada',
        ])->assertRedirect();

        $settingsA = CompanyBackupSetting::query()->where('company_id', $companyA->id)->firstOrFail();
        $settingsB = CompanyBackupSetting::query()->where('company_id', $companyB->id)->first();

        $this->assertTrue($settingsA->is_enabled);
        $this->assertSame('semanal', $settingsA->frequency);
        $this->assertNotNull($settingsA->next_backup_at);
        $this->assertNull($settingsB, 'La configuración de B no debe existir ni heredar la de A.');
    }

    public function test_only_platform_admin_can_access_backup_endpoints(): void
    {
        [, $tenant] = $this->tenant('Empresa Privada', 'tenant@backup.test');
        [$company] = $this->tenant('Empresa C', 'c-owner@backup.test');

        $payload = [
            'is_enabled' => 1,
            'plan' => 'basico',
            'frequency' => 'diario',
            'retention_days' => 30,
            'manual_backup_allowed' => 1,
            'external_copy' => 'preparada',
        ];

        $this->actingAs($tenant)->patch(route('platform.backups.update', $company), $payload)->assertForbidden();
        $this->actingAs($tenant)->post(route('platform.backups.run', $company))->assertForbidden();
        $this->actingAs($tenant)->post(route('platform.backups.restore-test', $company))->assertForbidden();
        $this->actingAs($tenant)->get(route('platform.companies.show', $company))->assertForbidden();

        $this->assertSame(0, CompanyBackupRecord::query()->count());
    }

    public function test_enable_disable_and_settings_persist(): void
    {
        $platformAdmin = $this->platformAdmin();
        [$company] = $this->tenant('Empresa D', 'd-owner@backup.test');

        $this->actingAs($platformAdmin)->patch(route('platform.backups.update', $company), [
            'is_enabled' => 1,
            'plan' => 'estandar',
            'frequency' => 'mensual',
            'retention_days' => 90,
            'manual_backup_allowed' => 1,
            'external_copy' => 'activada',
        ])->assertRedirect();

        $settings = CompanyBackupSetting::query()->where('company_id', $company->id)->firstOrFail();
        $this->assertTrue($settings->is_enabled);
        $this->assertSame('mensual', $settings->frequency);
        $this->assertSame(90, $settings->retention_days);
        $this->assertTrue($settings->encryption_required, 'Cifrado obligatorio con copia externa activada.');

        $this->actingAs($platformAdmin)->patch(route('platform.backups.update', $company), [
            'plan' => 'estandar',
            'frequency' => 'mensual',
            'retention_days' => 90,
            'external_copy' => 'activada',
        ])->assertRedirect();

        $settings->refresh();
        $this->assertFalse($settings->is_enabled, 'Sin checkbox is_enabled el servicio queda desactivado.');
        $this->assertFalse($settings->manual_backup_allowed);
        $this->assertNull($settings->next_backup_at);
    }

    public function test_fe_plan_enforces_five_year_retention(): void
    {
        $platformAdmin = $this->platformAdmin();
        [$company] = $this->tenant('Empresa FE', 'fe@backup.test');

        $this->actingAs($platformAdmin)->patch(route('platform.backups.update', $company), [
            'is_enabled' => 1,
            'plan' => 'fe_5_anios',
            'frequency' => 'diario',
            'retention_days' => 30,
            'manual_backup_allowed' => 1,
            'external_copy' => 'preparada',
        ])->assertRedirect();

        $settings = CompanyBackupSetting::query()->where('company_id', $company->id)->firstOrFail();
        $this->assertSame(1825, $settings->retention_days);
    }

    public function test_manual_backup_calls_existing_export_and_validate_flow(): void
    {
        $platformAdmin = $this->platformAdmin();
        [$company] = $this->tenant('Empresa E', 'e-owner@backup.test');
        $service = app(CompanyBackupService::class);
        $service->updateSettings($company, [
            'is_enabled' => true,
            'plan' => 'basico',
            'frequency' => 'diario',
            'retention_days' => 30,
            'manual_backup_allowed' => true,
            'external_copy' => 'preparada',
        ], $platformAdmin);

        $this->actingAs($platformAdmin)->post(route('platform.backups.run', $company))->assertRedirect();

        $this->assertCount(2, $this->runner->calls);
        $export = $this->runner->calls[0]['command'];
        $validate = $this->runner->calls[1]['command'];

        $this->assertStringContainsString('export-company.sh', $export[1]);
        $this->assertContains('--company-id', $export);
        $this->assertContains((string) $company->id, $export);
        $this->assertStringContainsString('validate-company-export.sh', $validate[1]);

        $record = CompanyBackupRecord::query()->where('company_id', $company->id)->where('kind', 'manual')->firstOrFail();
        $this->assertSame('success', $record->status);

        $settings = CompanyBackupSetting::query()->where('company_id', $company->id)->firstOrFail();
        $this->assertSame('success', $settings->last_status);
        $this->assertNotNull($settings->next_backup_at);
    }

    public function test_scheduler_only_runs_active_enabled_companies(): void
    {
        $platformAdmin = $this->platformAdmin();
        [$companyA] = $this->tenant('Empresa A Off', 'a-off@backup.test');
        [$companyB] = $this->tenant('Empresa B On', 'b-on@backup.test');
        $service = app(CompanyBackupService::class);

        $disabled = $service->settings($companyA);
        $enabled = $service->updateSettings($companyB, [
            'is_enabled' => true,
            'plan' => 'basico',
            'frequency' => 'diario',
            'retention_days' => 30,
            'manual_backup_allowed' => true,
            'external_copy' => 'preparada',
        ], $platformAdmin);
        $enabled->forceFill(['next_backup_at' => now()->subMinute()])->save();

        $this->artisan('backup:companies')->assertSuccessful();

        $exportedIds = array_values(array_filter(array_map(function ($call) {
            $command = $call['command'];
            $index = array_search('--company-id', $command, true);

            return $index === false ? null : $command[$index + 1];
        }, $this->runner->calls)));

        $this->assertSame([(string) $companyB->id], $exportedIds, 'Solo la empresa activa y pendiente debe ejecutarse.');
        $this->assertSame(0, CompanyBackupRecord::query()->where('company_id', $companyA->id)->count());
        $this->assertSame(1, CompanyBackupRecord::query()->where('company_id', $companyB->id)->count());
        $this->assertFalse($disabled->refresh()->is_enabled);
    }

    public function test_restore_test_uses_company_restore_test_database(): void
    {
        $platformAdmin = $this->platformAdmin();
        [$company] = $this->tenant('Empresa R', 'r-owner@backup.test');
        $service = app(CompanyBackupService::class);
        $service->updateSettings($company, [
            'is_enabled' => true,
            'plan' => 'basico',
            'frequency' => 'diario',
            'retention_days' => 30,
            'manual_backup_allowed' => true,
            'external_copy' => 'preparada',
        ], $platformAdmin);

        $backupDirectory = storage_path('company-backups-test/'.$company->id.'/manual');
        File::ensureDirectoryExists($backupDirectory);
        CompanyBackupRecord::query()->create([
            'company_id' => $company->id,
            'kind' => 'manual',
            'status' => 'success',
            'path' => $backupDirectory,
            'started_at' => now(),
            'finished_at' => now(),
            'message' => 'Backup previo',
        ]);

        $this->actingAs($platformAdmin)->post(route('platform.backups.restore-test', $company))->assertRedirect();

        $this->assertCount(1, $this->runner->calls);
        $command = $this->runner->calls[0]['command'];
        $this->assertStringContainsString('restore-company.sh', $command[1]);
        $dbIndex = array_search('--db', $command, true);
        $this->assertNotFalse($dbIndex);
        $this->assertStringEndsWith('_company_restore_test', $command[$dbIndex + 1]);
        $this->assertStringContainsString((string) $company->id, $command[$dbIndex + 1]);

        $record = CompanyBackupRecord::query()->where('company_id', $company->id)->where('kind', 'restore_test')->firstOrFail();
        $this->assertSame('success', $record->status);

        File::deleteDirectory(storage_path('company-backups-test'));
    }

    public function test_company_a_never_shows_or_runs_company_b_history(): void
    {
        $platformAdmin = $this->platformAdmin();
        [$companyA] = $this->tenant('Empresa Norte Segura', 'norte@backup.test');
        [$companyB] = $this->tenant('Empresa Sur Reservada', 'sur@backup.test');

        CompanyBackupRecord::query()->create([
            'company_id' => $companyA->id,
            'kind' => 'manual',
            'status' => 'success',
            'message' => 'MARCADOR_RESPALDO_A',
            'finished_at' => now(),
        ]);
        CompanyBackupRecord::query()->create([
            'company_id' => $companyB->id,
            'kind' => 'manual',
            'status' => 'success',
            'message' => 'MARCADOR_RESPALDO_B',
            'finished_at' => now(),
        ]);

        $this->actingAs($platformAdmin)->get(route('platform.companies.show', $companyA))
            ->assertOk()
            ->assertSee('MARCADOR_RESPALDO_A')
            ->assertDontSee('MARCADOR_RESPALDO_B');

        $this->actingAs($platformAdmin)->get(route('platform.companies.show', $companyB))
            ->assertOk()
            ->assertSee('MARCADOR_RESPALDO_B')
            ->assertDontSee('MARCADOR_RESPALDO_A');
    }

    private function platformAdmin(): User
    {
        return User::factory()->create(['is_active' => true, 'is_platform_admin' => true]);
    }

    private function tenant(string $name, string $email): array
    {
        $company = Company::create(['trade_name' => $name, 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Administrador', 'is_active' => true]);
        $owner = User::factory()->create(['email' => $email, 'is_active' => true]);
        $owner->companies()->attach($company->id, ['role_id' => $role->id]);

        return [$company, $owner];
    }
}
