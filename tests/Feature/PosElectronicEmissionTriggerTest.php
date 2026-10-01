<?php

namespace Tests\Feature;

use App\Contracts\Fiscal\FiscalProviderInterface;
use App\DTOs\Fiscal\FiscalDocumentStatus;
use App\DTOs\Fiscal\FiscalEmissionRequest;
use App\DTOs\Fiscal\FiscalEmissionResult;
use App\Jobs\EmitElectronicDocument;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\CompanyCashSetting;
use App\Models\CompanyLicense;
use App\Models\Customer;
use App\Models\ElectronicDocument;
use App\Models\FiscalProfile;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;
use App\Services\CompanyLicenseService;
use App\Services\Facturaencr\FacturaencrProvider;
use App\Services\Fiscal\FiscalManager;
use App\Services\Fiscal\PosEmissionDispatcher;
use App\Services\PosDefaultDocumentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class PosElectronicEmissionTriggerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Http::preventStrayRequests();
    }

    public function test_auto_emit_off_by_default_keeps_checkout_unchanged_and_dispatches_no_job(): void
    {
        Queue::fake();

        [$company, $branch, $user, $method] = $this->context();
        $product = $this->product($company, true);
        $this->stock($branch, $product, 5);

        $this->assertFalse((bool) config('fiscal.emission.auto_emit'));

        $this->checkout($user, $company, $branch, $method, [[
            'product_id' => $product->id,
            'quantity' => 1,
        ]], 2000, [
            'document_type' => Sale::DOCUMENT_ELECTRONIC_INVOICE,
        ], $this->customer($company)->id)->assertOk();

        Queue::assertNotPushed(EmitElectronicDocument::class);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('sale_payments', 1);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertEquals(4, DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
        $this->assertDatabaseCount('electronic_documents', 0);
        Http::assertNothingSent();
    }

    public function test_electronic_invoice_checkout_dispatches_exactly_one_job(): void
    {
        Queue::fake();
        config(['fiscal.emission.auto_emit' => true]);

        [$company, $branch, $user, $method] = $this->context();
        $product = $this->product($company, false);

        $this->checkout($user, $company, $branch, $method, [[
            'product_id' => $product->id,
            'quantity' => 1,
        ]], 2000, [
            'document_type' => Sale::DOCUMENT_ELECTRONIC_INVOICE,
        ], $this->customer($company)->id)->assertOk();

        $sale = Sale::latest('id')->firstOrFail();

        Queue::assertPushed(EmitElectronicDocument::class, 1);
        Queue::assertPushed(EmitElectronicDocument::class, fn (EmitElectronicDocument $job) => $job->saleId === $sale->id
            && $job->documentType === Sale::DOCUMENT_ELECTRONIC_INVOICE);
        Http::assertNothingSent();
    }

    public function test_electronic_ticket_checkout_dispatches_exactly_one_job(): void
    {
        Queue::fake();
        config(['fiscal.emission.auto_emit' => true]);

        [$company, $branch, $user, $method] = $this->context();
        $product = $this->product($company, false);

        $this->checkout($user, $company, $branch, $method, [[
            'product_id' => $product->id,
            'quantity' => 1,
        ]], 2000, [
            'document_type' => Sale::DOCUMENT_ELECTRONIC_TICKET,
        ])->assertOk();

        $sale = Sale::latest('id')->firstOrFail();

        $this->assertSame(Sale::DOCUMENT_ELECTRONIC_TICKET, $sale->document_type);
        Queue::assertPushed(EmitElectronicDocument::class, 1);
        Queue::assertPushed(EmitElectronicDocument::class, fn (EmitElectronicDocument $job) => $job->saleId === $sale->id
            && $job->documentType === Sale::DOCUMENT_ELECTRONIC_TICKET);
        Http::assertNothingSent();
    }

    public function test_non_electronic_and_historical_sales_are_never_dispatched(): void
    {
        Queue::fake();
        config(['fiscal.emission.auto_emit' => true]);

        [$company, $branch, $user] = $this->context();
        $dispatcher = app(PosEmissionDispatcher::class);

        $notaCredito = $this->sale($company, $branch, $user, ['document_type' => 'nota_credito']);
        $historical = $this->sale($company, $branch, $user, [
            'document_type' => Sale::DOCUMENT_ELECTRONIC_TICKET,
            'is_historical' => true,
        ]);
        $electronica = $this->sale($company, $branch, $user);

        $this->assertFalse($dispatcher->forSale($notaCredito));
        $this->assertFalse($dispatcher->forSale($historical));
        $this->assertTrue($dispatcher->forSale($electronica));

        Queue::assertPushed(EmitElectronicDocument::class, 1);
        Queue::assertPushed(EmitElectronicDocument::class, fn (EmitElectronicDocument $job) => $job->saleId === $electronica->id);
        Http::assertNothingSent();
    }

    public function test_internal_ticket_checkout_dispatches_no_job(): void
    {
        Queue::fake();
        config(['fiscal.emission.auto_emit' => true]);

        [$company, $branch, $user, $method] = $this->context();
        $product = $this->product($company, false);

        $this->checkout($user, $company, $branch, $method, [[
            'product_id' => $product->id,
            'quantity' => 1,
        ]], 2000, [
            'document_type' => Sale::DOCUMENT_TICKET,
        ])->assertOk();

        $sale = Sale::latest('id')->firstOrFail();

        $this->assertSame(Sale::DOCUMENT_TICKET, $sale->document_type);
        $this->assertSame(Sale::STATUS_COMPLETED, $sale->status);
        Queue::assertNotPushed(EmitElectronicDocument::class);
        $this->assertDatabaseCount('electronic_documents', 0);
        Http::assertNothingSent();
    }

    public function test_dispatcher_rejects_internal_ticket_but_accepts_fiscal_types(): void
    {
        Queue::fake();
        config(['fiscal.emission.auto_emit' => true]);

        [$company, $branch, $user] = $this->context();
        $dispatcher = app(PosEmissionDispatcher::class);

        $internal = $this->sale($company, $branch, $user, ['document_type' => Sale::DOCUMENT_TICKET]);
        $ticket = $this->sale($company, $branch, $user, ['document_type' => Sale::DOCUMENT_ELECTRONIC_TICKET]);
        $invoice = $this->sale($company, $branch, $user, ['document_type' => Sale::DOCUMENT_ELECTRONIC_INVOICE]);

        $this->assertFalse($dispatcher->forSale($internal));
        $this->assertTrue($dispatcher->forSale($ticket));
        $this->assertTrue($dispatcher->forSale($invoice));

        Queue::assertPushed(EmitElectronicDocument::class, 2);
        Http::assertNothingSent();
    }

    public function test_missing_document_type_falls_back_to_company_default_ticket(): void
    {
        Queue::fake();
        config(['fiscal.emission.auto_emit' => true]);

        [$company, $branch, $user, $method] = $this->context();
        $product = $this->product($company, false);

        $this->assertDatabaseMissing('company_cash_settings', ['company_id' => $company->id]);

        $this->checkout($user, $company, $branch, $method, [[
            'product_id' => $product->id,
            'quantity' => 1,
        ]], 2000)->assertOk();

        $sale = Sale::latest('id')->firstOrFail();

        $this->assertSame(Sale::DOCUMENT_TICKET, $sale->document_type);
        Queue::assertNotPushed(EmitElectronicDocument::class);
        $this->assertDatabaseCount('electronic_documents', 0);
        Http::assertNothingSent();
    }

    public function test_configured_te_default_is_used_when_document_type_missing(): void
    {
        Queue::fake();
        config(['fiscal.emission.auto_emit' => true]);

        [$company, $branch, $user, $method] = $this->context();
        $product = $this->product($company, false);

        CompanyCashSetting::query()->updateOrCreate(
            ['company_id' => $company->id],
            ['default_document_type' => Sale::DOCUMENT_ELECTRONIC_TICKET]
        );

        $this->checkout($user, $company, $branch, $method, [[
            'product_id' => $product->id,
            'quantity' => 1,
        ]], 2000)->assertOk();

        $sale = Sale::latest('id')->firstOrFail();

        $this->assertSame(Sale::DOCUMENT_ELECTRONIC_TICKET, $sale->document_type);
        Queue::assertPushed(EmitElectronicDocument::class, 1);
        Http::assertNothingSent();
    }

    public function test_configured_fe_default_is_used_when_document_type_missing(): void
    {
        Queue::fake();
        config(['fiscal.emission.auto_emit' => true]);

        [$company, $branch, $user, $method] = $this->context();
        $product = $this->product($company, false);

        CompanyCashSetting::query()->updateOrCreate(
            ['company_id' => $company->id],
            ['default_document_type' => Sale::DOCUMENT_ELECTRONIC_INVOICE]
        );

        $this->checkout($user, $company, $branch, $method, [[
            'product_id' => $product->id,
            'quantity' => 1,
        ]], 2000, [], $this->customer($company)->id)->assertOk();

        $sale = Sale::latest('id')->firstOrFail();

        $this->assertSame(Sale::DOCUMENT_ELECTRONIC_INVOICE, $sale->document_type);
        Queue::assertPushed(EmitElectronicDocument::class, 1);
        Http::assertNothingSent();
    }

    public function test_invalid_configured_default_falls_back_to_ticket(): void
    {
        config(['fiscal.emission.auto_emit' => true]);

        [$company] = $this->context();

        CompanyCashSetting::query()->updateOrCreate(
            ['company_id' => $company->id],
            ['default_document_type' => 'factura_magica']
        );

        $this->assertSame(
            Sale::DOCUMENT_TICKET,
            app(PosDefaultDocumentType::class)->resolve($company->id)
        );
        $this->assertSame(
            Sale::DOCUMENT_TICKET,
            app(PosDefaultDocumentType::class)->resolve(null)
        );
    }

    public function test_dispatch_happens_after_the_sale_transaction_is_committed(): void
    {
        $fake = Queue::fake();
        config(['fiscal.emission.auto_emit' => true]);

        $levels = [];
        $fake->beforePushing(function ($job) use (&$levels): void {
            if ($job instanceof EmitElectronicDocument) {
                $levels[] = DB::transactionLevel();
            }
        });

        [$company, $branch, $user, $method] = $this->context();
        $product = $this->product($company, true);
        $this->stock($branch, $product, 5);

        $levelBeforeCheckout = DB::transactionLevel();

        $this->checkout($user, $company, $branch, $method, [[
            'product_id' => $product->id,
            'quantity' => 1,
        ]], 2000, [
            'document_type' => Sale::DOCUMENT_ELECTRONIC_INVOICE,
        ], $this->customer($company)->id)->assertOk();

        $this->assertSame([$levelBeforeCheckout], $levels, 'El job se despachó con la transacción de la venta todavía abierta.');
        $this->assertDatabaseCount('sales', 1);
        $this->assertSame(Sale::STATUS_COMPLETED, Sale::firstOrFail()->status);
    }

    public function test_rolled_back_sale_dispatches_nothing(): void
    {
        Queue::fake();
        config(['fiscal.emission.auto_emit' => true]);

        Sale::created(function (): void {
            throw new \RuntimeException('Fallo posterior a la creación de la venta.');
        });

        [$company, $branch, $user, $method] = $this->context();
        $product = $this->product($company, false);

        $response = $this->checkout($user, $company, $branch, $method, [[
            'product_id' => $product->id,
            'quantity' => 1,
        ]], 2000);

        $this->assertSame(500, $response->getStatusCode());
        Queue::assertNotPushed(EmitElectronicDocument::class);
        $this->assertDatabaseCount('sales', 0);
        Http::assertNothingSent();
    }

    public function test_fiscal_failure_never_alters_confirmed_sale_cash_or_inventory(): void
    {
        config([
            'fiscal.emission.auto_emit' => true,
            'fiscal.provider' => 'fake',
            'fiscal.providers.fake' => FailingFakeFiscalProvider::class,
        ]);

        [$company, $branch, $user, $method] = $this->context();
        $product = $this->product($company, true);
        $this->stock($branch, $product, 5);
        $cashSession = $this->cashSession($company, $branch, $user);

        $this->checkout($user, $company, $branch, $method, [[
            'product_id' => $product->id,
            'quantity' => 1,
        ]], 2000, [
            'document_type' => Sale::DOCUMENT_ELECTRONIC_INVOICE,
        ], $this->customer($company)->id)->assertOk();

        $sale = Sale::firstOrFail();

        $this->assertSame(Sale::STATUS_COMPLETED, $sale->status);
        $this->assertNotNull($sale->completed_at);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('sale_payments', 1);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertEquals(4, DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
        $this->assertDatabaseHas('cash_sessions', [
            'id' => $cashSession->id,
            'status' => CashSession::STATUS_OPEN,
        ]);
        $this->assertDatabaseCount('electronic_documents', 0);

        $provider = app(FiscalManager::class)->provider();
        $this->assertInstanceOf(FailingFakeFiscalProvider::class, $provider);
        $this->assertCount(1, $provider->requests);
        Http::assertNothingSent();
    }

    public function test_double_dispatch_and_job_retry_never_duplicate_the_electronic_document(): void
    {
        $fake = Queue::fake();
        config([
            'fiscal.emission.auto_emit' => true,
            'fiscal.provider' => 'fake',
            'fiscal.providers.fake' => EmissionFakeFiscalProvider::class,
        ]);

        [$company, $branch, $user, $method] = $this->context();
        $product = $this->product($company, false);

        $this->checkout($user, $company, $branch, $method, [[
            'product_id' => $product->id,
            'quantity' => 1,
        ]], 2000, [
            'document_type' => Sale::DOCUMENT_ELECTRONIC_INVOICE,
        ], $this->customer($company)->id)->assertOk();

        $sale = Sale::latest('id')->firstOrFail();

        $this->assertCount(1, $fake->pushed(EmitElectronicDocument::class));

        EmitElectronicDocument::dispatch($sale->id, $sale->document_type);
        EmitElectronicDocument::dispatch($sale->id, $sale->document_type);

        Queue::assertPushed(EmitElectronicDocument::class, 1);

        $manager = app(FiscalManager::class);
        (new EmitElectronicDocument($sale->id, $sale->document_type))->handle($manager);
        (new EmitElectronicDocument($sale->id, $sale->document_type))->handle($manager);

        $provider = app(FiscalManager::class)->provider();
        $this->assertCount(1, $provider->requests);
        $this->assertSame(1, ElectronicDocument::count());

        $document = ElectronicDocument::firstOrFail();
        $this->assertSame('fake', $document->provider);
        $this->assertNotEmpty($document->idempotency_key);
        Http::assertNothingSent();
    }

    public function test_job_emits_only_through_the_mvs_contract_with_a_fake_provider_and_no_http(): void
    {
        config([
            'fiscal.emission.auto_emit' => true,
            'fiscal.provider' => 'fake',
            'fiscal.providers.fake' => EmissionFakeFiscalProvider::class,
        ]);

        [$company, $branch, $user, $method] = $this->context();
        $product = $this->product($company, false);

        $this->checkout($user, $company, $branch, $method, [[
            'product_id' => $product->id,
            'quantity' => 1,
        ]], 2000, [
            'document_type' => Sale::DOCUMENT_ELECTRONIC_INVOICE,
        ], $this->customer($company)->id)->assertOk();

        $sale = Sale::latest('id')->firstOrFail();
        $manager = app(FiscalManager::class);
        $provider = $manager->provider();

        $this->assertInstanceOf(FiscalProviderInterface::class, $provider);
        $this->assertNotInstanceOf(FacturaencrProvider::class, $provider);
        $this->assertCount(1, $provider->requests);

        $request = $provider->requests[0];
        $this->assertInstanceOf(FiscalEmissionRequest::class, $request);
        $this->assertSame($sale->id, $request->sale->id);
        $this->assertSame(Sale::DOCUMENT_ELECTRONIC_INVOICE, $request->sale->document_type);
        $this->assertNotEmpty($request->saleItems);

        $document = ElectronicDocument::sole();
        $this->assertSame('fake', $document->provider);
        $this->assertNotEmpty($document->idempotency_key);

        (new EmitElectronicDocument($sale->id, $sale->document_type))->handle($manager);

        $this->assertSame(1, ElectronicDocument::count());
        $this->assertSame($document->idempotency_key, ElectronicDocument::sole()->idempotency_key);
        $this->assertCount(1, $provider->requests);
        Http::assertNothingSent();
    }

    private function context(string $name = 'Empresa'): array
    {
        $company = Company::create([
            'trade_name' => $name.uniqid(),
            'legal_name' => $name.' S.A.',
            'identification_number' => '3101000000',
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);

        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'PRI-'.$company->id, 'is_active' => true]);

        $user = User::factory()->create();
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach (['pos.acceder', 'ventas.crear'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'POS', 'is_active' => true]);
            $role->permissions()->syncWithoutDetaching($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        app(CompanyLicenseService::class)->ensure($company);
        CompanyLicense::query()->where('company_id', $company->id)->update(['fiscal_enabled' => true]);

        return [$company, $branch, $user, $this->payment($company)];
    }

    private function payment(Company $company, array $attributes = []): PaymentMethod
    {
        return PaymentMethod::create(array_merge([
            'company_id' => $company->id,
            'code' => 'cash-'.uniqid(),
            'name' => 'Efectivo',
            'type' => 'cash',
            'is_active' => true,
            'allows_change' => true,
        ], $attributes));
    }

    private function product(Company $company, bool $tracked, bool $decimals = false, array $attributes = []): Product
    {
        $suffix = uniqid();
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Cat '.$suffix, 'slug' => 'cat-'.$suffix, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad', 'abbreviation' => 'U', 'slug' => 'u-'.$suffix, 'allows_decimals' => $decimals, 'is_active' => true]);

        return Product::create(array_merge([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'name' => 'Producto '.$suffix,
            'internal_code' => 'P-'.$suffix,
            'cost' => 500,
            'sale_price' => 1000,
            'stock' => 123,
            'tax_rate' => 13,
            'fiscal_profile_id' => FiscalProfile::where('tax_code', '01')->where('tax_rate_code', '08')->value('id'),
            'cabys_code' => '0111100000100',
            'track_inventory' => $tracked,
            'is_active' => true,
        ], $attributes));
    }

    private function customer(Company $company, array $attributes = []): Customer
    {
        return Customer::create(array_merge([
            'company_id' => $company->id,
            'name' => 'Cliente '.uniqid(),
            'customer_type' => 'individual',
            'identification_type' => '01',
            'identification' => '1234567890',
            'is_active' => true,
        ], $attributes));
    }

    private function stock(Branch $branch, Product $product, float $stock): void
    {
        DB::table('branch_product')->insert([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'stock' => $stock,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function openCashSession(Company $company, Branch $branch, User $user): CashSession
    {
        $existing = CashSession::query()
            ->forCompany($company->id)
            ->forBranch($branch->id)
            ->where('opened_by', $user->id)
            ->where('status', CashSession::STATUS_OPEN)
            ->first();

        return $existing ?? $this->cashSession($company, $branch, $user);
    }

    private function cashSession(Company $company, Branch $branch, User $user): CashSession
    {
        $register = CashRegister::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'code' => 'CAJA-'.uniqid(),
            'name' => 'Caja',
            'is_active' => true,
        ]);

        return CashSession::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'cash_register_id' => $register->id,
            'session_number' => 'CAJA-'.uniqid(),
            'opened_by' => $user->id,
            'status' => CashSession::STATUS_OPEN,
            'open_guard' => CashSession::OPEN_GUARD,
            'opening_amount' => 0,
            'opened_at' => now(),
        ]);
    }

    private function sale(Company $company, Branch $branch, User $user, array $attributes = []): Sale
    {
        return Sale::create(array_merge([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'sale_number' => 'POS-'.strtoupper(Str::random(12)),
            'document_type' => Sale::DOCUMENT_ELECTRONIC_INVOICE,
            'sale_condition' => 'cash',
            'status' => Sale::STATUS_COMPLETED,
            'currency_code' => 'CRC',
            'exchange_rate' => 1,
            'subtotal' => 1000,
            'discount_total' => 0,
            'tax_total' => 0,
            'rounding_total' => 0,
            'total' => 1000,
            'paid_total' => 1000,
            'balance_due' => 0,
            'completed_at' => now(),
        ], $attributes));
    }

    private function checkout(User $user, Company $company, Branch $branch, PaymentMethod $method, array $items, int $received, array $extra = [], ?int $customer = null)
    {
        $cashSession = $this->openCashSession($company, $branch, $user);
        $total = (int) round(collect($items)->sum(function (array $item) {
            $product = Product::findOrFail($item['product_id']);

            return (float) $product->sale_price * (float) $item['quantity'] * (1 + ((float) ($product->tax_rate ?? 0) / 100));
        }), 0, PHP_ROUND_HALF_UP);

        return $this->actingAs($user)->withSession([
            'active_company_id' => $company->id,
            'active_branch_id' => $branch->id,
        ])->postJson(route('pos.checkout'), array_merge([
            'checkout_token' => (string) Str::uuid(),
            'cash_session_id' => $cashSession->id,
            'customer_id' => $customer,
            'payments' => [[
                'payment_method_id' => $method->id,
                'amount' => $total,
                'received_amount' => $received,
                'reference' => null,
            ]],
            'items' => $items,
        ], $extra));
    }
}

class EmissionFakeFiscalProvider implements FiscalProviderInterface
{
    /** @var array<int, FiscalEmissionRequest> */
    public array $requests = [];

    public function providerCode(): string
    {
        return 'fake';
    }

    public function emit(FiscalEmissionRequest $request): FiscalEmissionResult
    {
        $this->requests[] = $request;

        $document = ElectronicDocument::create([
            'company_id' => $request->sale->company_id,
            'sale_id' => $request->sale->id,
            'provider' => $this->providerCode(),
            'document_type' => $request->sale->document_type === Sale::DOCUMENT_ELECTRONIC_INVOICE ? '01' : '04',
            'environment' => 'sandbox',
            'idempotency_key' => md5($request->sale->company_id.'-'.$request->sale->id),
            'provider_document_id' => 'fake-'.$request->sale->id,
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

class FailingFakeFiscalProvider implements FiscalProviderInterface
{
    /** @var array<int, FiscalEmissionRequest> */
    public array $requests = [];

    public function providerCode(): string
    {
        return 'fake';
    }

    public function emit(FiscalEmissionRequest $request): FiscalEmissionResult
    {
        $this->requests[] = $request;

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
