<?php

namespace Tests\Feature;

use App\Contracts\Fiscal\FiscalProviderInterface;
use App\DTOs\Fiscal\FiscalDocumentStatus;
use App\DTOs\Fiscal\FiscalEmissionRequest;
use App\DTOs\Fiscal\FiscalEmissionResult;
use App\Exceptions\FiscalQuotaException;
use App\Jobs\EmitElectronicDocument;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\CompanyLicense;
use App\Models\Customer;
use App\Models\ElectronicDocument;
use App\Models\FiscalConsumption;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;
use App\Services\CompanyLicenseService;
use App\Services\Fiscal\FiscalConsumptionService;
use App\Services\Fiscal\FiscalManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class FiscalConsumptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Http::preventStrayRequests();
        FiscalQuotaFakeProvider::reset();
        config([
            'fiscal.provider' => 'fake',
            'fiscal.providers.fake' => FiscalQuotaFakeProvider::class,
        ]);
    }

    public function test_fiscal_disabled_blocks_backend_emit_before_post(): void
    {
        [$company, $branch, $user] = $this->context();
        $sale = $this->sale($company, $branch, $user);

        try {
            app(FiscalManager::class)->emit($this->emissionRequest($sale, $company));
            $this->fail('La emisión debió bloquearse con fiscal deshabilitado.');
        } catch (FiscalQuotaException $exception) {
            $this->assertSame('fiscal_disabled', $exception->reason);
        }

        $this->assertSame(0, FiscalQuotaFakeProvider::requests());
        $this->assertDatabaseCount('electronic_documents', 0);
        $this->assertDatabaseCount('fiscal_consumptions', 0);
        Http::assertNothingSent();
    }

    public function test_fiscal_disabled_checkout_rejects_electronic_types_but_accepts_ticket(): void
    {
        [$company, $branch, $user, $method] = $this->context();
        $product = $this->product($company);

        $this->checkout($user, $company, $branch, $method, [[
            'product_id' => $product->id, 'quantity' => 1,
        ]], 2000, ['document_type' => Sale::DOCUMENT_ELECTRONIC_TICKET])->assertUnprocessable();

        $this->checkout($user, $company, $branch, $method, [[
            'product_id' => $product->id, 'quantity' => 1,
        ]], 2000, ['document_type' => Sale::DOCUMENT_TICKET])->assertOk();

        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('fiscal_consumptions', 0);
        Http::assertNothingSent();
    }

    public function test_pos_shows_ticket_only_when_fiscal_disabled(): void
    {
        [$company, $branch, $user] = $this->context();
        $this->openCashSession($company, $branch, $user);

        $response = $this->actingAs($user)->withSession([
            'active_company_id' => $company->id, 'active_branch_id' => $branch->id,
        ])->get(route('pos.index'));

        $response->assertOk();
        $response->assertSee('Tiquete', false);
        $response->assertDontSee('Tiquete Electrónico 04', false);
        $response->assertDontSee('Factura Electrónica 01', false);

        $this->enableFiscal($company);

        $enabled = $this->actingAs($user)->withSession([
            'active_company_id' => $company->id, 'active_branch_id' => $branch->id,
        ])->get(route('pos.index'));

        $enabled->assertOk();
        $enabled->assertSee('Tiquete Electrónico 04', false);
        $enabled->assertSee('Factura Electrónica 01', false);
    }

    public function test_quota_included_consumes_exactly_one_unit(): void
    {
        [$company, $branch, $user] = $this->context();
        $this->enableFiscal($company, ['fiscal_monthly_quota' => 2]);
        $sale = $this->sale($company, $branch, $user);

        (new EmitElectronicDocument($sale->id, $sale->document_type))->handle(app(FiscalManager::class));

        $this->assertSame(1, FiscalQuotaFakeProvider::requests());
        $this->assertDatabaseCount('fiscal_consumptions', 1);
        $this->assertDatabaseHas('fiscal_consumptions', [
            'company_id' => $company->id,
            'classification' => FiscalConsumption::CLASSIFICATION_INCLUDED,
            'document_type' => '01',
        ]);
        $this->assertSame(1, app(FiscalConsumptionService::class)->monthlyUsage($company->id));
        Http::assertNothingSent();
    }

    public function test_quota_exhausted_blocks_before_post_without_overage(): void
    {
        [$company, $branch, $user] = $this->context();
        $this->enableFiscal($company, ['fiscal_monthly_quota' => 1]);
        $first = $this->sale($company, $branch, $user);
        (new EmitElectronicDocument($first->id, $first->document_type))->handle(app(FiscalManager::class));
        $this->assertSame(1, FiscalQuotaFakeProvider::requests());

        $second = $this->sale($company, $branch, $user);

        try {
            app(FiscalManager::class)->emit($this->emissionRequest($second, $company));
            $this->fail('La segunda emisión debió bloquearse por cuota agotada.');
        } catch (FiscalQuotaException $exception) {
            $this->assertSame('quota_exhausted', $exception->reason);
        }

        $this->assertSame(1, FiscalQuotaFakeProvider::requests());
        $this->assertDatabaseCount('fiscal_consumptions', 1);
        $this->assertDatabaseCount('electronic_documents', 1);
        Http::assertNothingSent();
    }

    public function test_overage_allowed_freezes_unit_price_per_document(): void
    {
        [$company, $branch, $user] = $this->context();
        $this->enableFiscal($company, [
            'fiscal_monthly_quota' => 1,
            'fiscal_overage_enabled' => true,
            'fiscal_overage_unit_price' => '25.5000',
        ]);

        $first = $this->sale($company, $branch, $user);
        (new EmitElectronicDocument($first->id, $first->document_type))->handle(app(FiscalManager::class));

        $second = $this->sale($company, $branch, $user);
        (new EmitElectronicDocument($second->id, $second->document_type))->handle(app(FiscalManager::class));

        $this->assertSame(2, FiscalQuotaFakeProvider::requests());
        $this->assertDatabaseCount('fiscal_consumptions', 2);

        $overage = FiscalConsumption::query()->where('classification', FiscalConsumption::CLASSIFICATION_OVERAGE)->sole();
        $this->assertSame('25.5000', $overage->unit_price);

        CompanyLicense::query()->where('company_id', $company->id)->update(['fiscal_overage_unit_price' => '30.0000']);

        $third = $this->sale($company, $branch, $user);
        (new EmitElectronicDocument($third->id, $third->document_type))->handle(app(FiscalManager::class));

        $this->assertSame('25.5000', $overage->fresh()->unit_price);
        $this->assertSame('30.0000', FiscalConsumption::query()->latest('id')->firstOrFail()->unit_price);
        Http::assertNothingSent();
    }

    public function test_retry_of_same_document_never_consumes_twice(): void
    {
        [$company, $branch, $user] = $this->context();
        $this->enableFiscal($company);
        $sale = $this->sale($company, $branch, $user);
        $manager = app(FiscalManager::class);

        (new EmitElectronicDocument($sale->id, $sale->document_type))->handle($manager);
        (new EmitElectronicDocument($sale->id, $sale->document_type))->handle($manager);
        $manager->emit($this->emissionRequest($sale, $company));

        $this->assertSame(1, ElectronicDocument::count());
        $this->assertDatabaseCount('fiscal_consumptions', 1);
        Http::assertNothingSent();
    }

    public function test_repeated_records_and_unique_backstop_prevent_double_charge(): void
    {
        [$company, $branch, $user] = $this->context();
        $this->enableFiscal($company);
        $sale = $this->sale($company, $branch, $user);
        $service = app(FiscalConsumptionService::class);
        $manager = app(FiscalManager::class);

        (new EmitElectronicDocument($sale->id, $sale->document_type))->handle($manager);

        $document = ElectronicDocument::sole();
        $this->assertSame($service->record($document)->id, $service->record($document)->id);

        try {
            FiscalConsumption::create([
                'company_id' => $company->id,
                'electronic_document_id' => $document->id,
                'document_type' => '01',
                'period' => $service->periodFor()->toDateString(),
                'classification' => FiscalConsumption::CLASSIFICATION_INCLUDED,
            ]);
            $this->fail('El constraint único debió bloquear la segunda fila.');
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            $this->assertTrue(true);
        }

        $this->assertDatabaseCount('fiscal_consumptions', 1);
        $this->assertSame(1, $service->monthlyUsage($company->id));
    }

    public function test_provider_error_consumes_nothing_and_preserves_history(): void
    {
        config(['fiscal.providers.fake' => FiscalQuotaFailingProvider::class]);

        [$company, $branch, $user] = $this->context();
        $this->enableFiscal($company);
        $sale = $this->sale($company, $branch, $user);

        try {
            app(FiscalManager::class)->emit($this->emissionRequest($sale, $company));
            $this->fail('El proveedor simulado debió fallar.');
        } catch (\RuntimeException $exception) {
            $this->assertNotInstanceOf(FiscalQuotaException::class, $exception);
        }

        $this->assertDatabaseCount('electronic_documents', 0);
        $this->assertDatabaseCount('fiscal_consumptions', 0);
        Http::assertNothingSent();
    }

    public function test_rejected_document_keeps_history_and_counts_conservatively(): void
    {
        [$company, $branch, $user] = $this->context();
        $this->enableFiscal($company);
        $sale = $this->sale($company, $branch, $user);

        $document = ElectronicDocument::create([
            'company_id' => $company->id,
            'sale_id' => $sale->id,
            'provider' => 'fake',
            'document_type' => '01',
            'environment' => 'sandbox',
            'idempotency_key' => md5("{$company->id}-{$sale->id}-01"),
            'status' => 'rejected',
            'last_error_code' => 'HACIENDA_REJECTED',
        ]);

        app(FiscalConsumptionService::class)->record($document);

        $this->assertSame(1, app(FiscalConsumptionService::class)->monthlyUsage($company->id));
        $this->assertSame('rejected', $document->fresh()->status);
    }

    public function test_new_month_starts_with_fresh_consumption(): void
    {
        [$company, $branch, $user] = $this->context();
        $this->enableFiscal($company, ['fiscal_monthly_quota' => 1]);
        $service = app(FiscalConsumptionService::class);

        $lastMonth = CarbonImmutable::now()->subMonthNoOverflow()->startOfMonth();
        $sale = $this->sale($company, $branch, $user);
        $document = ElectronicDocument::create([
            'company_id' => $company->id,
            'sale_id' => $sale->id,
            'provider' => 'fake',
            'document_type' => '01',
            'environment' => 'sandbox',
            'idempotency_key' => md5("{$company->id}-{$sale->id}-01"),
            'status' => 'accepted',
        ]);
        DB::table('electronic_documents')->where('id', $document->id)->update([
            'created_at' => $lastMonth->toDateTimeString(),
        ]);
        $document->refresh();

        $service->record($document);

        $this->assertSame(0, $service->monthlyUsage($company->id));
        $this->assertSame(1, $service->monthlyUsage($company->id, $lastMonth));
        $this->assertSame($lastMonth->toDateString(), FiscalConsumption::sole()->period->toDateString());
    }

    public function test_internal_ticket_never_consumes(): void
    {
        [$company, $branch, $user] = $this->context();
        $this->enableFiscal($company);
        $sale = $this->sale($company, $branch, $user, ['document_type' => Sale::DOCUMENT_TICKET]);

        try {
            app(FiscalManager::class)->emit($this->emissionRequest($sale, $company));
            $this->fail('El tiquete interno jamás debe emitirse fiscalmente.');
        } catch (FiscalQuotaException $exception) {
            $this->assertSame('non_consumable_type', $exception->reason);
        }

        $this->assertSame(0, FiscalQuotaFakeProvider::requests());
        $this->assertDatabaseCount('electronic_documents', 0);
        $this->assertDatabaseCount('fiscal_consumptions', 0);
    }

    public function test_tenant_cannot_modify_fiscal_contract_but_platform_admin_can(): void
    {
        [$company] = $this->context();

        $this->assertFalse(app(FiscalConsumptionService::class)->isFiscalEnabled($company->id));

        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyLicenseService::class)->updateContract(
            $company,
            $admin,
            'active',
            'Habilitación fiscal.',
            ['fiscal_enabled' => true, 'fiscal_monthly_quota' => 10]
        );

        $this->assertTrue(app(FiscalConsumptionService::class)->isFiscalEnabled($company->id));
        $this->assertSame(10, CompanyLicense::query()->where('company_id', $company->id)->value('fiscal_monthly_quota'));
    }

    public function test_tenant_license_view_shows_readonly_usage_breakdown(): void
    {
        [$company, $branch, $user] = $this->context();
        $this->enableFiscal($company, ['fiscal_monthly_quota' => 10]);
        $sale = $this->sale($company, $branch, $user);
        (new EmitElectronicDocument($sale->id, $sale->document_type))->handle(app(FiscalManager::class));

        $response = $this->actingAs($user)->withSession([
            'active_company_id' => $company->id, 'active_branch_id' => $branch->id,
        ])->get(route('license.status'));

        $response->assertOk();
        $response->assertSee('Documentos fiscales del mes', false);
        $response->assertSee('Facturas electrónicas (01)', false);
        $response->assertDontSee('fiscal_monthly_quota', false);
    }

    public function test_platform_view_shows_usage_and_contract_without_secrets(): void
    {
        [$company, $branch, $user] = $this->context();
        $this->enableFiscal($company, ['fiscal_monthly_quota' => 10]);
        $sale = $this->sale($company, $branch, $user);
        (new EmitElectronicDocument($sale->id, $sale->document_type))->handle(app(FiscalManager::class));

        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);

        $response = $this->actingAs($admin)->get(route('platform.companies.show', $company));

        $response->assertOk();
        $response->assertSee('Consumo fiscal del mes', false);
        $response->assertSee('Servicio fiscal', false);
        $response->assertDontSee('api_key', false);
        $response->assertDontSee('secret', false);
    }

    public function test_nc03_type_is_consumable_ready_without_post(): void
    {
        [$company, $branch, $user] = $this->context();
        $this->enableFiscal($company, ['fiscal_monthly_quota' => 10]);
        $service = app(FiscalConsumptionService::class);

        $this->assertContains('03', FiscalConsumptionService::CONSUMABLE_TYPES);
        $decision = $service->authorize($company->id, '03');
        $this->assertTrue($decision['allowed']);
        $this->assertSame(FiscalConsumption::CLASSIFICATION_INCLUDED, $decision['classification']);

        $sale = $this->sale($company, $branch, $user);
        $document = ElectronicDocument::create([
            'company_id' => $company->id,
            'sale_id' => $sale->id,
            'provider' => 'fake',
            'document_type' => '03',
            'environment' => 'sandbox',
            'idempotency_key' => md5("{$company->id}-{$sale->id}-03"),
            'status' => 'accepted',
        ]);

        $service->record($document);

        $this->assertSame(1, $service->monthlyUsage($company->id));
        $this->assertSame(['03' => 1], $service->monthlyBreakdown($company->id)['by_type']);
        $this->assertSame(0, FiscalQuotaFakeProvider::requests());
        Http::assertNothingSent();
    }

    private function context(string $name = 'Empresa'): array
    {
        $company = Company::create([
            'trade_name' => $name . uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'PRI-' . $company->id, 'is_active' => true]);

        $user = User::factory()->create();
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol ' . uniqid(), 'is_active' => true]);
        foreach (['pos.acceder', 'ventas.crear'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'POS', 'is_active' => true]);
            $role->permissions()->syncWithoutDetaching($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return [$company, $branch, $user, $this->payment($company)];
    }

    private function enableFiscal(Company $company, array $attributes = []): void
    {
        app(CompanyLicenseService::class)->ensure($company);
        CompanyLicense::query()->where('company_id', $company->id)->update(array_merge([
            'fiscal_enabled' => true,
        ], $attributes));
    }

    private function payment(Company $company): PaymentMethod
    {
        return PaymentMethod::create([
            'company_id' => $company->id, 'code' => 'cash-' . uniqid(), 'name' => 'Efectivo',
            'type' => 'cash', 'is_active' => true, 'allows_change' => true,
        ]);
    }

    private function product(Company $company): Product
    {
        $suffix = uniqid();
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Cat ' . $suffix, 'slug' => 'cat-' . $suffix, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad', 'abbreviation' => 'U', 'slug' => 'u-' . $suffix, 'is_active' => true]);

        $product = Product::create([
            'company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id,
            'name' => 'Producto ' . $suffix, 'internal_code' => 'P-' . $suffix,
            'cost' => 500, 'sale_price' => 2000, 'tax_rate' => 0,
            'track_inventory' => false, 'is_active' => true,
        ]);

        if ($product->fiscal_profile_id === null) {
            $product->fiscal_profile_id = \App\Models\FiscalProfile::query()->where('tax_code', '01')->where('tax_rate_code', '10')->value('id');
            $product->save();
        }

        return $product;
    }

    private function customer(Company $company): Customer
    {
        return Customer::create([
            'company_id' => $company->id, 'name' => 'Cliente ' . uniqid(),
            'customer_type' => 'individual', 'is_active' => true,
        ]);
    }

    private function sale(Company $company, Branch $branch, User $user, array $attributes = []): Sale
    {
        return Sale::create(array_merge([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'customer_id' => $this->customer($company)->id,
            'sale_number' => 'POS-' . strtoupper(Str::random(12)),
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

    private function emissionRequest(Sale $sale, Company $company): \App\DTOs\Fiscal\FiscalEmissionRequest
    {
        return new \App\DTOs\Fiscal\FiscalEmissionRequest(
            $sale->fresh(),
            $company,
            $sale->customer,
            [],
        );
    }

    private function openCashSession(Company $company, Branch $branch, User $user): CashSession
    {
        $register = CashRegister::create([
            'company_id' => $company->id, 'branch_id' => $branch->id,
            'code' => 'CAJA-' . uniqid(), 'name' => 'Caja', 'is_active' => true,
        ]);

        return CashSession::create([
            'company_id' => $company->id, 'branch_id' => $branch->id,
            'cash_register_id' => $register->id, 'session_number' => 'CAJA-' . uniqid(),
            'opened_by' => $user->id, 'status' => CashSession::STATUS_OPEN,
            'open_guard' => CashSession::OPEN_GUARD, 'opening_amount' => 0, 'opened_at' => now(),
        ]);
    }

    private function checkout(User $user, Company $company, Branch $branch, PaymentMethod $method, array $items, int $received, array $extra = [])
    {
        $cashSession = $this->openCashSession($company, $branch, $user);

        return $this->actingAs($user)->withSession([
            'active_company_id' => $company->id,
            'active_branch_id' => $branch->id,
        ])->postJson(route('pos.checkout'), array_merge([
            'checkout_token' => (string) Str::uuid(),
            'cash_session_id' => $cashSession->id,
            'payments' => [[
                'payment_method_id' => $method->id,
                'amount' => 2000,
                'received_amount' => $received,
                'reference' => null,
            ]],
            'items' => $items,
        ], $extra));
    }
}

class FiscalQuotaFakeProvider implements FiscalProviderInterface
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
            'idempotency_key' => md5($request->sale->company_id . '-' . $request->sale->id),
            'provider_document_id' => 'fake-' . $request->sale->id,
            'status' => 'accepted',
        ]);

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

class FiscalQuotaFailingProvider implements FiscalProviderInterface
{
    public function providerCode(): string
    {
        return 'fake';
    }

    public function emit(FiscalEmissionRequest $request): FiscalEmissionResult
    {
        throw new \RuntimeException('Fallo fiscal del proveedor simulado.');
    }

    public function fetchStatus(ElectronicDocument $document): FiscalDocumentStatus
    {
        return new FiscalDocumentStatus(
            state: FiscalEmissionResult::STATE_ERROR,
            final: false,
            providerReference: $document->provider_document_id,
            fiscalReference: $document->clave,
            message: 'no aplica',
        );
    }
}
