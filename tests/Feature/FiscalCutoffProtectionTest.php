<?php

namespace Tests\Feature;

use App\Contracts\Fiscal\FiscalProviderInterface;
use App\DTOs\Fiscal\FiscalDocumentStatus;
use App\DTOs\Fiscal\FiscalEmissionRequest;
use App\DTOs\Fiscal\FiscalEmissionResult;
use App\Jobs\EmitElectronicDocument;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyFiscalConfig;
use App\Models\CompanyLicense;
use App\Models\Customer;
use App\Models\ElectronicDocument;
use App\Models\FiscalSeries;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\CompanyLicenseService;
use App\Services\Fiscal\FiscalManager;
use App\Services\Fiscal\FiscalSeriesService;
use App\Services\Fiscal\PosEmissionDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Protección global `fiscal_start_at` (corte de inicio fiscal por empresa).
 *
 * Doble gate: POS (PosEmissionDispatcher::emittable) y job
 * (EmitElectronicDocument::confirmedElectronicSale). Las ventas completadas
 * antes del corte jamás emiten ni consumen cuota, no existe backfill, y
 * solo la venta individual por fuera del corte puede emitirse.
 */
class FiscalCutoffProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Http::preventStrayRequests();
        CutoffFakeProvider::reset();
        config([
            'fiscal.provider' => 'fake',
            'fiscal.providers.fake' => CutoffFakeProvider::class,
        ]);
    }

    public function test_pos_dispatch_skips_sales_completed_before_fiscal_start_at(): void
    {
        Queue::fake();
        config(['fiscal.emission.auto_emit' => true]);

        [$company, $branch, $user] = $this->context();
        $this->cutoff($company, now()->subHour());

        $dispatcher = app(PosEmissionDispatcher::class);
        $before = $this->sale($company, $branch, $user, ['completed_at' => now()->subDay()]);
        $after = $this->sale($company, $branch, $user, ['completed_at' => now()->subMinute()]);

        $this->assertFalse($dispatcher->forSale($before));
        $this->assertTrue($dispatcher->forSale($after));

        Queue::assertPushed(EmitElectronicDocument::class, 1);
        Queue::assertPushed(EmitElectronicDocument::class, fn (EmitElectronicDocument $job) => $job->saleId === $after->id);
        Http::assertNothingSent();
    }

    public function test_without_fiscal_start_at_dispatcher_behavior_is_unchanged(): void
    {
        Queue::fake();
        config(['fiscal.emission.auto_emit' => true]);

        [$company, $branch, $user] = $this->context();
        $this->assertDatabaseMissing('company_fiscal_configs', ['company_id' => $company->id]);

        $sale = $this->sale($company, $branch, $user, ['completed_at' => now()->subDays(60)]);

        $this->assertTrue(app(PosEmissionDispatcher::class)->forSale($sale));
        Queue::assertPushed(EmitElectronicDocument::class, 1);
    }

    public function test_job_skips_sale_before_cutoff_without_provider_call_nor_quota(): void
    {
        [$company, $branch, $user] = $this->context();
        $this->cutoff($company, now());
        $before = $this->sale($company, $branch, $user, ['completed_at' => now()->subDay()]);

        $log = Log::spy();

        (new EmitElectronicDocument($before->id, $before->document_type))->handle(app(FiscalManager::class));

        $log->shouldHaveReceived('info')->withArgs(function (string $message, array $context = []): bool {
            return $message === 'fiscal.emission.skipped'
                && ($context['reason'] ?? null) === 'before_fiscal_cutoff'
                && isset($context['sale_id'], $context['fiscal_start_at']);
        });

        $this->assertSame(0, CutoffFakeProvider::requests());
        $this->assertDatabaseCount('electronic_documents', 0);
        $this->assertDatabaseCount('fiscal_consumptions', 0);
        Http::assertNothingSent();
    }

    public function test_bulk_pre_cutoff_sales_never_emit_nor_consume_quota(): void
    {
        Queue::fake();
        config(['fiscal.emission.auto_emit' => true]);

        [$company, $branch, $user] = $this->context();
        $this->cutoff($company, now());
        $dispatcher = app(PosEmissionDispatcher::class);

        for ($i = 0; $i < 5; $i++) {
            $sale = $this->sale($company, $branch, $user, [
                'document_type' => Sale::DOCUMENT_ELECTRONIC_TICKET,
                'completed_at' => now()->subDays(30),
            ]);

            $this->assertFalse($dispatcher->forSale($sale));
            (new EmitElectronicDocument($sale->id, $sale->document_type))->handle(app(FiscalManager::class));
        }

        Queue::assertNotPushed(EmitElectronicDocument::class);
        $this->assertSame(0, CutoffFakeProvider::requests());
        $this->assertDatabaseCount('electronic_documents', 0);
        $this->assertDatabaseCount('fiscal_consumptions', 0);
        Http::assertNothingSent();
    }

    public function test_sale_at_cutoff_can_be_recovered_individually_and_seeds_official_series(): void
    {
        [$company, $branch, $user] = $this->context();
        $this->cutoff($company, now()->subDay());
        $sale = $this->sale($company, $branch, $user, ['completed_at' => now()]);

        $job = new EmitElectronicDocument($sale->id, $sale->document_type);
        $job->handle(app(FiscalManager::class));
        $job->handle(app(FiscalManager::class));

        $this->assertSame(1, CutoffFakeProvider::requests());
        $this->assertDatabaseCount('electronic_documents', 1);
        $this->assertDatabaseCount('fiscal_consumptions', 1);

        $series = FiscalSeries::query()->where('company_id', $company->id)->sole();
        $this->assertSame('001', $series->branch_code);
        $this->assertSame('00001', $series->terminal_code);
        $this->assertSame('01', $series->document_type);
        $this->assertSame(1, (int) $series->last_sequence);
        $this->assertSame('00100001010000000001', $series->last_consecutivo);
        Http::assertNothingSent();
    }

    public function test_portal_preferences_persist_and_clear_fiscal_start_at(): void
    {
        [$company, $branch, $user] = $this->context();
        $session = ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];

        $this->actingAs($user)->withSession($session)->put(route('fiscal.setup.store', ['step' => 'preferencias']), [
            'default_document' => '01',
            'auto_emit_enabled' => '1',
            'fiscal_start_at' => '2026-10-01',
        ])->assertRedirect(route('fiscal.setup', ['step' => 'confirmacion']));

        $this->assertSame('2026-10-01', CompanyFiscalConfig::query()
            ->where('company_id', $company->id)->sole()->fiscal_start_at->format('Y-m-d'));

        $before = $this->sale($company, $branch, $user, ['completed_at' => now()->subDays(30)]);
        $after = $this->sale($company, $branch, $user, ['completed_at' => now()]);

        config(['fiscal.emission.auto_emit' => true]);
        Queue::fake();

        $this->assertFalse(app(PosEmissionDispatcher::class)->forSale($before));
        $this->assertTrue(app(PosEmissionDispatcher::class)->forSale($after));

        $this->actingAs($user)->withSession($session)->put(route('fiscal.setup.store', ['step' => 'preferencias']), [
            'default_document' => '01',
            'auto_emit_enabled' => '1',
            'fiscal_start_at' => '',
        ])->assertRedirect(route('fiscal.setup', ['step' => 'confirmacion']));

        $this->assertNull(CompanyFiscalConfig::query()->where('company_id', $company->id)->sole()->fiscal_start_at);
        $this->assertTrue(app(PosEmissionDispatcher::class)->forSale($before));
    }

    public function test_emission_has_no_backfill_path_outside_pos_checkout(): void
    {
        $dispatchers = [];

        foreach ($this->phpFiles(app_path()) as $file) {
            if (str_contains((string) file_get_contents($file), 'EmitElectronicDocument::dispatch')) {
                $dispatchers[] = basename($file);
            }
        }

        $this->assertSame(['PosEmissionDispatcher.php'], array_values(array_unique($dispatchers)));

        $commandsWithJob = [];

        foreach ($this->phpFiles(app_path().'/Console') as $file) {
            if (str_contains((string) file_get_contents($file), 'EmitElectronicDocument')) {
                $commandsWithJob[] = basename($file);
            }
        }

        $this->assertSame([], array_values($commandsWithJob));

        $consoleRoutes = (string) file_get_contents(base_path('routes/console.php'));
        $this->assertStringNotContainsString('EmitElectronicDocument', $consoleRoutes);
        $this->assertDoesNotMatchRegularExpression('/Schedule::[^;]*\b(fiscal|emit)/i', $consoleRoutes);
    }

    private function context(): array
    {
        $company = Company::create([
            'trade_name' => 'F'.uniqid(),
            'legal_name' => 'F '.uniqid().' S.A.',
            'identification_number' => '3101000000',
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);

        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'PRI-'.$company->id, 'is_active' => true]);

        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);

        foreach (['pos.acceder', 'ventas.crear', 'fiscal.ver', 'fiscal.editar'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'POS', 'is_active' => true]);
            $role->permissions()->syncWithoutDetaching($permission);
        }

        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        app(CompanyLicenseService::class)->ensure($company);
        CompanyLicense::query()->where('company_id', $company->id)->update([
            'fiscal_enabled' => true,
            'fiscal_monthly_quota' => 50,
        ]);

        return [$company, $branch, $user];
    }

    private function cutoff(Company $company, $date): CompanyFiscalConfig
    {
        return CompanyFiscalConfig::updateOrCreate(['company_id' => $company->id], [
            'provider' => 'fake',
            'environment' => 'sandbox',
            'auto_emit_enabled' => true,
            'fiscal_start_at' => $date,
        ]);
    }

    private function sale(Company $company, Branch $branch, User $user, array $attributes = []): Sale
    {
        return Sale::create(array_merge([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'customer_id' => Customer::create([
                'company_id' => $company->id,
                'name' => 'Cliente '.uniqid(),
                'customer_type' => 'individual',
                'is_active' => true,
            ])->id,
            'sale_number' => 'POS-'.strtoupper(Str::random(12)),
            'document_type' => Sale::DOCUMENT_ELECTRONIC_INVOICE,
            'sale_condition' => 'cash',
            'status' => Sale::STATUS_COMPLETED,
            'currency_code' => 'CRC',
            'exchange_rate' => 1,
            'subtotal' => 2000,
            'discount_total' => 0,
            'tax_total' => 0,
            'rounding_total' => 0,
            'total' => 2000,
            'paid_total' => 2000,
            'balance_due' => 0,
            'completed_at' => now(),
        ], $attributes));
    }

    /** @return iterable<string> */
    private function phpFiles(string $directory): iterable
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }
}

class CutoffFakeProvider implements FiscalProviderInterface
{
    private static int $calls = 0;

    public static function requests(): int
    {
        return self::$calls;
    }

    public static function reset(): void
    {
        self::$calls = 0;
    }

    public function providerCode(): string
    {
        return 'fake';
    }

    public function emit(FiscalEmissionRequest $request): FiscalEmissionResult
    {
        self::$calls++;

        $existing = ElectronicDocument::query()
            ->where('company_id', $request->sale->company_id)
            ->where('sale_id', $request->sale->id)
            ->where('provider', $this->providerCode())
            ->first();

        if ($existing !== null) {
            return new FiscalEmissionResult(
                state: FiscalEmissionResult::STATE_ACCEPTED,
                electronicDocumentId: $existing->id,
                providerReference: $existing->provider_document_id,
                fiscalReference: $existing->clave,
            );
        }

        $document = ElectronicDocument::create([
            'company_id' => $request->sale->company_id,
            'sale_id' => $request->sale->id,
            'provider' => $this->providerCode(),
            'document_type' => $request->sale->document_type === Sale::DOCUMENT_ELECTRONIC_INVOICE ? '01' : '04',
            'environment' => 'sandbox',
            'attempt_number' => 1,
            'idempotency_key' => md5($request->sale->company_id.'-'.$request->sale->id),
            'provider_document_id' => 'fake-'.$request->sale->id,
            'status' => 'accepted',
            'clave' => str_repeat('7', 50),
            'consecutivo' => '00100001010000000001',
        ]);

        app(FiscalSeriesService::class)->observe($document);

        return new FiscalEmissionResult(
            state: FiscalEmissionResult::STATE_ACCEPTED,
            electronicDocumentId: $document->id,
            providerReference: $document->provider_document_id,
            fiscalReference: $document->clave,
        );
    }

    public function fetchStatus(ElectronicDocument $document): FiscalDocumentStatus
    {
        return new FiscalDocumentStatus(
            state: FiscalEmissionResult::STATE_ACCEPTED,
            final: true,
            providerReference: $document->provider_document_id,
            fiscalReference: $document->clave,
        );
    }
}
