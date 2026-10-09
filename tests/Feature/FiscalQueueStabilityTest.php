<?php

namespace Tests\Feature;

use App\Jobs\EmitElectronicDocument;
use App\Jobs\PollElectronicDocumentStatus;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\CashSessionMailNotification;
use App\Models\Company;
use App\Models\CompanyLicense;
use App\Models\Customer;
use App\Models\ElectronicDocument;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\CompanyLicenseService;
use App\Services\Facturaencr\FacturaencrInvoiceMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class FiscalQueueStabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);
    }

    public function test_dispatch_pending_100_runs_creates_exactly_one_job(): void
    {
        [$company, $session] = $this->cashContext();
        $notification = $this->pendingNotification($company, $session);

        for ($i = 0; $i < 100; $i++) {
            $this->artisan('cash:notifications:dispatch-pending')->assertSuccessful();
        }

        $this->assertSame(1, DB::table('jobs')->count(), '1 notification_id = máximo 1 job pendiente');
        $this->assertSame('pending', $notification->fresh()->status, 'el job aún no corre: el unique lock evita duplicados');
    }

    public function test_two_notifications_create_two_jobs(): void
    {
        [$company, $session] = $this->cashContext();
        [, $otherSession] = $this->cashContext();
        $this->pendingNotification($company, $session);
        $this->pendingNotification($company, $otherSession);

        $this->artisan('cash:notifications:dispatch-pending')->assertSuccessful();

        $this->assertSame(2, DB::table('jobs')->count());
    }

    public function test_sent_and_skipped_notifications_enqueue_zero_jobs(): void
    {
        [$company, $session] = $this->cashContext();
        [, $otherSession] = $this->cashContext();
        $sent = $this->pendingNotification($company, $session);
        $sent->update(['status' => CashSessionMailNotification::STATUS_SENT, 'sent_at' => now()]);
        $skipped = $this->pendingNotification($company, $otherSession);
        $skipped->update(['status' => CashSessionMailNotification::STATUS_SKIPPED]);

        $this->artisan('cash:notifications:dispatch-pending')->assertSuccessful();

        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_failed_notification_is_recoverable(): void
    {
        [$company, $session] = $this->cashContext();
        $failed = $this->pendingNotification($company, $session, [
            'status' => CashSessionMailNotification::STATUS_FAILED,
            'attempts' => 1,
            'available_at' => now()->subMinute(),
        ]);

        $this->artisan('cash:notifications:dispatch-pending')->assertSuccessful();

        $this->assertSame(1, DB::table('jobs')->count(), 'fallo recuperable: se vuelve a encolar');
        $this->assertStringContainsString('SendCashSessionMailNotification', DB::table('jobs')->value('payload'));
        $this->assertSame(CashSessionMailNotification::STATUS_FAILED, $failed->fresh()->status, 'el estado solo lo transiciona el job');
    }

    public function test_notification_in_backoff_is_not_enqueued(): void
    {
        [$company, $session] = $this->cashContext();
        $this->pendingNotification($company, $session, [
            'status' => CashSessionMailNotification::STATUS_FAILED,
            'attempts' => 2,
            'available_at' => now()->addHour(),
        ]);

        $this->artisan('cash:notifications:dispatch-pending')->assertSuccessful();

        $this->assertSame(0, DB::table('jobs')->count(), 'backoff respetado');
    }

    public function test_emit_electronic_document_is_dispatched_to_fiscal_queue(): void
    {
        Queue::fake();

        EmitElectronicDocument::dispatch(715, Sale::DOCUMENT_ELECTRONIC_INVOICE);
        PollElectronicDocumentStatus::dispatch(715);

        Queue::assertPushed(EmitElectronicDocument::class, function ($job) {
            return $job->queue === 'fiscal' && $job->saleId === 715;
        });
        Queue::assertPushed(PollElectronicDocumentStatus::class, function ($job) {
            return $job->queue === 'fiscal' && $job->electronicDocumentId === 715;
        });
    }

    public function test_dispatching_same_sale_twice_keeps_single_fiscal_job(): void
    {
        EmitElectronicDocument::dispatch(715, Sale::DOCUMENT_ELECTRONIC_INVOICE);
        EmitElectronicDocument::dispatch(715, Sale::DOCUMENT_ELECTRONIC_INVOICE);

        $this->assertSame(1, DB::table('jobs')->where('queue', 'fiscal')->count(), 'idempotencia ShouldBeUnique intacta');
    }

    public function test_fiscal_queue_is_drained_while_default_queue_stays_saturated(): void
    {
        [$company, $session] = $this->cashContext();
        [, $otherSession] = $this->cashContext();
        [, $thirdSession] = $this->cashContext();
        $this->pendingNotification($company, $session);
        $this->pendingNotification($company, $otherSession);
        $this->pendingNotification($company, $thirdSession);

        $this->artisan('cash:notifications:dispatch-pending')->assertSuccessful();
        $this->assertSame(3, DB::table('jobs')->where('queue', 'default')->count(), 'cola default saturada con jobs inocuos');

        // Venta fiscal sin cliente: el job termina limpio (skip) sin llamadas HTTP.
        $sale = Sale::create([
            'company_id' => $company->id,
            'branch_id' => Branch::where('company_id', $company->id)->first()->id,
            'user_id' => User::factory()->create()->id,
            'sale_number' => 'POS-FQ-1',
            'document_type' => Sale::DOCUMENT_ELECTRONIC_INVOICE,
            'sale_condition' => 'cash',
            'status' => Sale::STATUS_COMPLETED,
            'currency_code' => 'CRC',
            'exchange_rate' => 1,
            'subtotal' => 100,
            'discount_total' => 0,
            'tax_total' => 0,
            'rounding_total' => 0,
            'total' => 100,
            'paid_total' => 100,
            'balance_due' => 0,
            'completed_at' => now(),
        ]);
        EmitElectronicDocument::dispatch($sale->id, Sale::DOCUMENT_ELECTRONIC_INVOICE);
        $this->assertSame(1, DB::table('jobs')->where('queue', 'fiscal')->count());

        $this->artisan('queue:work', ['--once' => true, '--queue' => 'fiscal', '--sleep' => 0])->assertSuccessful();

        $this->assertSame(0, DB::table('jobs')->where('queue', 'fiscal')->count(), 'fiscal procesado sin tocar default');
        $this->assertSame(3, DB::table('jobs')->where('queue', 'default')->count(), 'default sigue intacta');
    }

    public function test_e2e_emission_on_fiscal_queue_single_post_polls_to_final_state(): void
    {
        Http::fake(function ($request) {
            $body = [
                'documentId' => 'sbx-1',
                'clave' => str_repeat('5', 50),
                'consecutivo' => '00100001010000000001',
            ];

            if ($request->method() === 'POST') {
                return Http::response($body + ['status' => 'queued'], 202);
            }

            return Http::response($body + ['status' => 'accepted'], 200);
        });

        $sale = $this->emittableSale();
        $expectedKey = (new FacturaencrInvoiceMapper)->idempotencyKey($sale, '01');

        [$company, $session] = $this->cashContext();
        $this->pendingNotification($company, $session);
        $this->artisan('cash:notifications:dispatch-pending')->assertSuccessful();

        EmitElectronicDocument::dispatch($sale->id, Sale::DOCUMENT_ELECTRONIC_INVOICE);
        $this->assertSame(1, DB::table('jobs')->where('queue', 'fiscal')->count());

        $this->artisan('queue:work', ['--once' => true, '--queue' => 'fiscal', '--sleep' => 0])->assertSuccessful();

        $document = ElectronicDocument::query()->where('sale_id', $sale->id)->sole();
        Http::assertSentCount(1);
        $this->assertSame($expectedKey, $document->idempotency_key, 'misma idempotency key');
        $this->assertSame(1, ElectronicDocument::query()->where('sale_id', $sale->id)->count(), 'cero duplicación');
        $this->assertNotNull($document->provider_document_id);
        $this->assertSame(1, DB::table('jobs')->where('queue', 'default')->count(), 'default no fue consumida');

        // El POST 202 (no final) programa el polling automático en la cola fiscal.
        $pollPayload = DB::table('jobs')->where('queue', 'fiscal')->value('payload');
        $this->assertStringContainsString('PollElectronicDocumentStatus', (string) $pollPayload, 'poll encolado en fiscal, no en default');

        // Ejecuta el poll ya disponible: GET de estado hasta accepted, sin re-POST.
        DB::table('jobs')->where('queue', 'fiscal')->update(['available_at' => now()->getTimestamp()]);
        $this->artisan('queue:work', ['--once' => true, '--queue' => 'fiscal', '--sleep' => 0])->assertSuccessful();

        $final = $document->fresh();
        $this->assertSame('accepted', $final->status, 'polling automático hasta estado final');
        $this->assertSame($expectedKey, $final->idempotency_key);
        $this->assertSame(1, ElectronicDocument::query()->where('sale_id', $sale->id)->count());
        $this->assertSame(0, DB::table('jobs')->where('queue', 'fiscal')->count(), 'fiscal drenada');
        Http::assertSentCount(2, 'exactamente 1 POST de emisión + 1 GET de estado');
        $this->assertSame(1, DB::table('jobs')->where('queue', 'default')->where('payload', 'like', '%SendCashSessionMailNotification%')->count(), 'default intacta (solo caja)');
    }

    public function test_fiscal_queue_health_reports_pending_backlog_and_failed_jobs(): void
    {
        $this->artisan('fiscal:queue-health')->assertExitCode(0);

        DB::table('jobs')->insert([
            'queue' => 'fiscal',
            'payload' => '{"job":"x"}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->subMinutes(5)->getTimestamp(),
            'created_at' => now()->subMinutes(5)->getTimestamp(),
        ]);
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'fiscal',
            'payload' => '{"displayName":"App\\\\Jobs\\\\EmitElectronicDocument"}',
            'exception' => 'boom',
            'failed_at' => now(),
        ]);

        $this->artisan('fiscal:queue-health')->assertExitCode(1);
        $this->artisan('fiscal:queue-health --json')->assertExitCode(1);
    }

    private function cashContext(): array
    {
        $company = Company::create([
            'trade_name' => 'FQ '.Str::random(5),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
        $branch = Branch::create([
            'company_id' => $company->id,
            'name' => 'Principal',
            'code' => 'P'.Str::random(5),
            'is_active' => true,
        ]);
        $user = User::factory()->create();
        $register = CashRegister::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'code' => 'C'.Str::random(5),
            'name' => 'Caja',
            'is_active' => true,
        ]);
        $session = CashSession::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'cash_register_id' => $register->id,
            'session_number' => 'CAJA-'.Str::random(8),
            'opened_by' => $user->id,
            'status' => 'open',
            'open_guard' => 'OPEN',
            'currency_code' => 'CRC',
            'opening_amount' => 1000,
            'tolerance_snapshot' => 0,
            'blind_closing_snapshot' => true,
            'accepts_usd_snapshot' => false,
            'opening_amount_usd' => 0,
            'opened_at' => now(),
        ]);

        return [$company, $session];
    }

    private function emittableSale(): Sale
    {
        $company = Company::create([
            'trade_name' => 'FQ FE',
            'legal_name' => 'FQ FE S.A.',
            'identification_number' => '3101000000',
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'FQE', 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id);
        $user->branches()->attach($branch->id);

        app(CompanyLicenseService::class)->ensure($company);
        CompanyLicense::query()->where('company_id', $company->id)->update(['fiscal_enabled' => true]);

        $customer = Customer::create([
            'company_id' => $company->id,
            'customer_type' => 'individual',
            'identification_type' => '01',
            'identification' => '123456789',
            'name' => 'Juan Pérez',
        ]);
        $method = PaymentMethod::create([
            'company_id' => $company->id,
            'code' => 'cash',
            'name' => 'Efectivo',
            'type' => 'cash',
            'is_system' => true,
            'is_active' => true,
            'affects_cash' => true,
            'requires_reference' => false,
            'allows_change' => true,
        ]);

        $sale = Sale::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'sale_number' => 'POS-FQ-E2E',
            'document_type' => Sale::DOCUMENT_ELECTRONIC_INVOICE,
            'sale_condition' => 'cash',
            'status' => Sale::STATUS_COMPLETED,
            'currency_code' => 'CRC',
            'exchange_rate' => 1,
            'subtotal' => 900,
            'discount_total' => 100,
            'tax_total' => 117,
            'rounding_total' => 0,
            'total' => 1017,
            'paid_total' => 1017,
            'balance_due' => 0,
            'completed_at' => now(),
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'cash_session_id' => null,
            'payment_method_id' => $method->id,
            'created_by' => $user->id,
            'status' => 'completed',
            'amount' => 1017,
            'received_amount' => 1200,
            'change_amount' => 183,
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_code' => 'P01',
            'cabys_code' => '5060101000000',
            'description' => 'Producto E2E',
            'unit_code' => 'un',
            'quantity' => 1,
            'unit_price' => 1000,
            'gross_total' => 1000,
            'discount_total' => 100,
            'subtotal' => 900,
            'tax_rate' => 13,
            'tax_code' => '01',
            'tax_rate_code' => '08',
            'tax_total' => 117,
            'total' => 1017,
            'unit_cost' => 500,
        ]);

        return $sale;
    }

    private function pendingNotification(Company $company, CashSession $session, array $overrides = []): CashSessionMailNotification
    {
        return CashSessionMailNotification::create(array_merge([
            'company_id' => $company->id,
            'cash_session_id' => $session->id,
            'notification_type' => CashSessionMailNotification::TYPE_OPENED,
            'recipients' => ['caja@example.com'],
            'status' => CashSessionMailNotification::STATUS_PENDING,
            'attempts' => 0,
        ], $overrides));
    }
}
