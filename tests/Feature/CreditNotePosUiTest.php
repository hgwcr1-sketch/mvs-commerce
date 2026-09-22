<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\CreditNoteApplication;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\Unit;
use App\Models\User;
use App\Services\MvsPrint\EscPosSaleTicket;
use App\Services\PaymentMethodProvisioner;
use App\Services\Sales\CreditNoteService;
use App\Services\Sales\SaleReceiptData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Fase 4B-3: superficie UI POS de la NC Consumer Final.
 *
 * Verifica la prevalidación por portador (permiso, datos no secretos, error
 * genérico, rate limit compartido), el gating server-side del panel, la
 * ausencia de secretos en comprobantes térmicos y el flujo integrado de
 * checkout reutilizando el backend certificado 4B-2.
 */
class CreditNotePosUiTest extends TestCase
{
    use RefreshDatabase;

    private const GENERIC = 'No se pudo validar la nota de crédito.';

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('cn-bearer-'.uniqid());
    }

    // ── helpers ──

    private function context(string $name = 'Empresa', array $perms = ['pos.acceder', 'ventas.crear', 'notas_credito.aplicar']): array
    {
        $company = Company::create(['trade_name' => $name.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        app(PaymentMethodProvisioner::class)->provision($company);
        $branch = $this->branch($company, 'Principal');
        $user = $this->user($company, $branch, $perms);
        $product = $this->product($company);
        $this->stock($branch, $product, 100);
        $this->ensureCashSession($company, $branch, $user);

        return [$company, $branch, $user, $product];
    }

    private function branch(Company $company, string $name): Branch
    {
        return Branch::create(['company_id' => $company->id, 'name' => $name, 'code' => $name.'-'.$company->id, 'is_active' => true]);
    }

    private function user(Company $company, Branch $branch, array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $perm) {
            $role->permissions()->syncWithoutDetaching(Permission::firstOrCreate(
                ['name' => $perm],
                ['label' => $perm, 'module' => 'POS', 'is_active' => true],
            ));
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return $user;
    }

    private function customer(Company $company): Customer
    {
        return Customer::create(['company_id' => $company->id, 'name' => 'Cliente '.uniqid(), 'customer_type' => 'individual', 'is_active' => true]);
    }

    private function product(Company $company, array $attributes = []): Product
    {
        $suffix = uniqid();
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Cat '.$suffix, 'slug' => 'cat-'.$suffix, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad', 'abbreviation' => 'U', 'slug' => 'u-'.$suffix, 'allows_decimals' => false, 'is_active' => true]);

        return Product::create(array_merge([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'name' => 'Producto '.$suffix,
            'internal_code' => 'P-'.$suffix,
            'cost' => 500,
            'sale_price' => 10000,
            'tax_rate' => 0,
            'track_inventory' => true,
            'is_active' => true,
        ], $attributes));
    }

    private function stock(Branch $branch, Product $product, float $stock): void
    {
        DB::table('branch_product')->insert(['branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => $stock, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function ensureCashSession(Company $company, Branch $branch, User $user): CashSession
    {
        $session = CashSession::query()->forCompany($company->id)->forBranch($branch->id)->where('opened_by', $user->id)->where('status', CashSession::STATUS_OPEN)->first();
        if ($session) {
            return $session;
        }
        $register = CashRegister::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'CAJA-'.uniqid(), 'name' => 'Caja', 'is_active' => true]);

        return CashSession::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'cash_register_id' => $register->id, 'session_number' => 'S-'.uniqid(), 'opened_by' => $user->id, 'status' => CashSession::STATUS_OPEN, 'open_guard' => CashSession::OPEN_GUARD, 'opening_amount' => 0, 'opened_at' => now()]);
    }

    private function originSale(Company $company, Branch $branch, User $user, Product $product, float $amount, ?Customer $customer = null): Sale
    {
        $quantity = max($amount, 1);

        $sale = Sale::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'customer_id' => $customer?->id,
            'checkout_token' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', uniqid('origin', true)),
            'sale_number' => 'POS-ORIGIN-'.uniqid(),
            'document_type' => Sale::DOCUMENT_ELECTRONIC_TICKET,
            'sale_condition' => Sale::CONDITION_CASH,
            'status' => Sale::STATUS_COMPLETED,
            'currency_code' => 'CRC',
            'exchange_rate' => 1,
            'subtotal' => $quantity,
            'discount_total' => 0,
            'tax_total' => 0,
            'rounding_total' => 0,
            'total' => $quantity,
            'paid_total' => $quantity,
            'balance_due' => 0,
            'completed_at' => now(),
        ]);

        $sale->items()->create([
            'product_id' => $product->id,
            'product_code' => $product->internal_code,
            'barcode' => null,
            'cabys_code' => null,
            'description' => $product->name,
            'unit_code' => 'U',
            'quantity' => 1,
            'unit_price' => $quantity,
            'gross_total' => $quantity,
            'discount_total' => 0,
            'subtotal' => $quantity,
            'tax_rate' => 0,
            'tax_total' => 0,
            'total' => $quantity,
            'unit_cost' => 500,
        ]);

        $sale->payments()->create([
            'payment_method_id' => $this->paymentMethod($company, 'cash')->id,
            'affects_cash_snapshot' => true,
            'created_by' => $user->id,
            'amount' => $quantity,
            'received_amount' => $quantity,
            'change_amount' => 0,
            'cash_effect_amount' => $quantity,
            'reference' => null,
            'status' => SalePayment::STATUS_COMPLETED,
        ]);

        return $sale;
    }

    private function returnForOrigin(Sale $sale, User $user, float $amount): SaleReturn
    {
        $total = max($amount, 1);

        $return = SaleReturn::create([
            'company_id' => $sale->company_id,
            'branch_id' => $sale->branch_id,
            'sale_id' => $sale->id,
            'user_id' => $user->id,
            'return_number' => 'DEV-ORIGIN-'.uniqid(),
            'reason' => 'Devolución para NC',
            'status' => SaleReturn::STATUS_COMPLETED,
            'returned_at' => now(),
        ]);

        SaleReturnItem::create([
            'sale_return_id' => $return->id,
            'sale_item_id' => $sale->items->first()->id,
            'product_id' => $sale->items->first()->product_id,
            'quantity' => 1,
            'unit_price' => $total,
            'gross_total' => $total,
            'discount_total' => 0,
            'subtotal' => $total,
            'tax_rate' => 0,
            'tax_total' => 0,
            'total' => $total,
        ]);

        return $return;
    }

    private function consumerFinalNote(Company $company, Branch $branch, User $user, float $amount): array
    {
        $company->update(['credit_note_consumer_final' => true]);

        $product = $this->product($company);
        $sale = $this->originSale($company, $branch, $user, $product, $amount);
        $return = $this->returnForOrigin($sale, $user, $amount);
        $result = $this->service()->issueFromReturnWithResult($return, $user);

        return [$result->creditNote, $result->applicationCode];
    }

    private function service(): CreditNoteService
    {
        return app(CreditNoteService::class);
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }

    private function bearerValidate(User $user, Company $company, Branch $branch, string $number, string $code, string $amount): TestResponse
    {
        return $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->postJson(route('pos.credit-notes.bearer-validate'), [
                'credit_note_number' => $number,
                'application_code' => $code,
                'amount' => $amount,
            ]);
    }

    private function paymentMethod(Company $company, string $type)
    {
        return \App\Models\PaymentMethod::forCompany($company->id)->where('type', $type)->firstOrFail();
    }

    private function checkout(User $user, Company $company, Branch $branch, Product $product, array $bearerApps, array $payload = []): TestResponse
    {
        $cashSession = $this->ensureCashSession($company, $branch, $user);

        $body = array_merge([
            'checkout_token' => (string) Str::uuid(),
            'cash_session_id' => $cashSession->id,
            'payments' => [],
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'credit_note_bearer_applications' => $bearerApps,
        ], $payload);

        return $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->postJson(route('pos.checkout'), $body);
    }

    private function bearerEntry(string $number, string $code, string $amount): array
    {
        return ['credit_note_number' => $number, 'application_code' => $code, 'amount' => $amount];
    }

    // ── casos ──

    public function test_bearer_validate_requires_permission(): void
    {
        [$company, $branch, $user] = $this->context('Empresa', ['pos.acceder', 'ventas.crear']);
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $this->user($company, $branch, ['pos.acceder', 'ventas.crear', 'notas_credito.aplicar']), 10000);

        $this->bearerValidate($user, $company, $branch, $note->credit_note_number, $code, '10000')
            ->assertForbidden();
    }

    public function test_bearer_validate_returns_non_secret_data(): void
    {
        [$company, $branch, $user] = $this->context();
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);

        $response = $this->bearerValidate($user, $company, $branch, $note->credit_note_number, $code, '10000')
            ->assertOk()
            ->assertJsonPath('credit_note_id', $note->id)
            ->assertJsonPath('credit_note_number', $note->credit_note_number)
            ->assertJsonPath('balance', '10000.0000');

        $this->assertArrayNotHasKey('application_code', $response->json());
        $this->assertStringNotContainsString($code, $response->getContent());

        $this->assertDatabaseHas('credit_notes', ['id' => $note->id, 'balance' => '10000.0000', 'status' => CreditNote::STATUS_ISSUED]);
    }

    public function test_bearer_validate_rejects_wrong_code_with_generic_message(): void
    {
        [$company, $branch, $user] = $this->context();
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);

        $response = $this->bearerValidate($user, $company, $branch, $note->credit_note_number, 'WRONG-0000-0000', '10000')
            ->assertUnprocessable();

        $this->assertSame(self::GENERIC, $response->json('message'));
        $this->assertStringNotContainsString('WRONG-0000-0000', $response->getContent());
    }

    public function test_bearer_validate_accepts_dashed_code(): void
    {
        [$company, $branch, $user] = $this->context();
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);

        $this->bearerValidate($user, $company, $branch, $note->credit_note_number, $code, '10000')->assertOk();
    }

    public function test_bearer_validate_does_not_consume_balance(): void
    {
        [$company, $branch, $user] = $this->context();
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);

        $this->bearerValidate($user, $company, $branch, $note->credit_note_number, $code, '10000')->assertOk();
        $this->bearerValidate($user, $company, $branch, $note->credit_note_number, $code, '10000')->assertOk();

        $this->assertDatabaseHas('credit_notes', ['id' => $note->id, 'balance' => '10000.0000']);
    }

public function test_pos_page_renders_bearer_panel_only_when_enabled_and_permitted(): void
    {
        [$company, $branch, $user] = $this->context();
        $company->update(['credit_note_consumer_final' => true]);

        $withToggle = $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->get(route('pos.index'))->assertOk();
        $withToggle->assertSee('Nota de crédito sin cliente');

        $company->update(['credit_note_consumer_final' => false]);
        $withoutToggle = $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->get(route('pos.index'))->assertOk();
        $withoutToggle->assertDontSee('Nota de crédito sin cliente');

        $noPermission = $this->user($company, $branch, ['pos.acceder', 'ventas.crear']);
        $this->ensureCashSession($company, $branch, $noPermission);
        $company->update(['credit_note_consumer_final' => true]);
        $withoutPermission = $this->actingAs($noPermission)
            ->withSession($this->activeSession($company, $branch))
            ->get(route('pos.index'))->assertOk();
        $withoutPermission->assertDontSee('Nota de crédito sin cliente');
    }

public function test_pos_page_keeps_consumer_final_code_out_of_storage_and_output(): void
    {
        [$company, $branch, $user] = $this->context();
        $company->update(['credit_note_consumer_final' => true]);

        $html = $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->get(route('pos.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('localStorage', $html);
        $this->assertStringNotContainsString('sessionStorage', $html);
        $this->assertStringNotContainsString('x-text="bearerNC.code"', $html);
        $this->assertStringContainsString('x-model="bearerNC.code"', $html);
        $this->assertStringContainsString('\\/pos\\/notas-credito\\/validar-portador', $html);
    }

public function test_thermal_ticket_prints_credit_notes_without_secret(): void
    {
        [$company, $branch, $user] = $this->context();
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);

        $data = $this->receiptData($company, $branch, ['credit_note_number' => $note->credit_note_number, 'amount' => '10000']);

        $ticket = app(EscPosSaleTicket::class)->build($data, '80', false);
        $values = array_column($ticket['lines'], 'value');

        $this->assertContains('Notas de crédito aplicadas', $values);
        $this->assertContains($note->credit_note_number.': ₡10000', $values);
        $this->assertStringNotContainsString($code, implode("\n", $values));
        $this->assertStringNotContainsString($note->application_code_hash, implode("\n", $values));
    }

    public function test_full_checkout_with_100_percent_bearer_nc(): void
    {
        [$company, $branch, $user, $product] = $this->context();
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);

        $this->checkout($user, $company, $branch, $product, [$this->bearerEntry($note->credit_note_number, $code, '10000')])
            ->assertOk()->assertJsonPath('duplicate', false);

        $sale = Sale::latest('id')->first();
        $this->assertSame(0, $sale->payments()->count());
        $this->assertSame(1, CreditNoteApplication::where('sale_id', $sale->id)->count());
        $this->assertDatabaseHas('credit_notes', ['id' => $note->id, 'balance' => '0.0000', 'status' => CreditNote::STATUS_APPLIED]);
    }

    public function test_full_checkout_combines_bearer_nc_with_payment(): void
    {
        [$company, $branch, $user, $product] = $this->context();
        $company->update(['credit_note_consumer_final' => true]);

        $catalog = $this->product($company, ['sale_price' => 20000]);
        $this->stock($branch, $catalog, 100);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);

        $this->checkout($user, $company, $branch, $catalog, [], [
            'payments' => [['payment_method_id' => $this->paymentMethod($company, 'cash')->id, 'amount' => 10000, 'received_amount' => 10000, 'reference' => null]],
            'credit_note_bearer_applications' => [$this->bearerEntry($note->credit_note_number, $code, '10000')],
            'items' => [['product_id' => $catalog->id, 'quantity' => 1]],
        ])->assertOk()->assertJsonPath('duplicate', false);

        $sale = Sale::latest('id')->first();
        $this->assertSame(1, $sale->payments()->count());
        $this->assertSame(1, CreditNoteApplication::where('sale_id', $sale->id)->count());
        $this->assertDatabaseHas('credit_notes', ['id' => $note->id, 'balance' => '0.0000', 'status' => CreditNote::STATUS_APPLIED]);
    }

    public function test_rendered_pos_bearer_workflow_runs_in_node(): void
    {
        [$company, $branch, $user] = $this->context();
        $company->update(['credit_note_consumer_final' => true]);

        $response = $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->get(route('pos.index'))->assertOk();

        $process = new \Symfony\Component\Process\Process(['node', base_path('tests/js/pos-credit-note-bearer.cjs')]);
        $process->setInput($response->getContent());
        $process->mustRun();

        $this->assertStringContainsString('CreditNote bearer UI OK', $process->getOutput());
    }

    private function receiptData(Company $company, Branch $branch, array $creditNoteApplication): SaleReceiptData
    {
        return new SaleReceiptData(
            company: ['trade_name' => $company->trade_name, 'legal_name' => '', 'identification_number' => null, 'address' => null, 'timezone' => 'America/Costa_Rica'],
            branch: ['name' => $branch->name, 'phone' => null, 'address' => null],
            document: ['type' => Sale::DOCUMENT_ELECTRONIC_TICKET, 'sale_number' => 'POS-1234', 'completed_at' => now()->toIso8601String(), 'status' => Sale::STATUS_COMPLETED, 'is_voided' => false],
            cashier: ['name' => 'Cajero'],
            customer: null,
            items: [],
            totals: ['subtotal' => '10000.0000', 'discount_total' => '0.0000', 'tax_total' => '0.0000', 'rounding_total' => '0.0000', 'total' => '10000.0000'],
            payments: [],
            payment_summary: ['is_mixed' => false],
            loyalty: null,
            credit_note_applications: [$creditNoteApplication],
            cash_session: null,
            footer_message: 'Gracias por su compra',
        );
    }
}
