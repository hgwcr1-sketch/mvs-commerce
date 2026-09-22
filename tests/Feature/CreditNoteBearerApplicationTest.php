<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashMovement;
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
use App\Services\PaymentMethodProvisioner;
use App\Services\Sales\CreditNoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Fase 4B-2: aplicación segura de NC Consumer Final por número + código.
 *
 * Verifica autorización por portador (number + code + amount), multitenancy,
 * toggle, expiración, doble gasto, idempotencia, rate limit, secretos no
 * persistidos y regresión nominativa.
 */
class CreditNoteBearerApplicationTest extends TestCase
{
    use RefreshDatabase;

    private const GENERIC = 'No se pudo validar la nota de crédito.';

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('cn-bearer-test');
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

    /**
     * Venta origen ya completada (efectivo) que da soporte a una NC.
     */
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

    /**
     * Devolución completada sobre una venta origen, con importe $amount.
     */
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

    /**
     * Crea una NC Consumer Final emitida por el flujo real (venta origen +
     * devolución), activando el toggle. Devuelve [note, code_en_plano] para
     * simular al cajero que conoce el código. $overrides se aplican DESPUÉS de
     * la emisión (p. ej. vencida, anulada, saldo 0).
     */
    private function consumerFinalNote(Company $company, Branch $branch, User $user, float $amount, array $overrides = []): array
    {
        $company->update(['credit_note_consumer_final' => true]);

        $product = $this->product($company);
        $sale = $this->originSale($company, $branch, $user, $product, $amount);
        $return = $this->returnForOrigin($sale, $user, $amount);
        $result = $this->service()->issueFromReturnWithResult($return, $user);

        $note = $result->creditNote;
        if ($overrides !== []) {
            $note->update($overrides);
            $note = $note->fresh();
        }

        return [$note, $result->applicationCode];
    }

    /**
     * NC nominativa emitida por el flujo real para una venta con cliente.
     */
    private function nominativeNote(Company $company, Branch $branch, Customer $customer, User $user, float $amount, array $overrides = []): CreditNote
    {
        $product = $this->product($company);
        $sale = $this->originSale($company, $branch, $user, $product, $amount, $customer);
        $return = $this->returnForOrigin($sale, $user, $amount);
        $note = $this->service()->issueFromReturn($return, $user);

        if ($overrides !== []) {
            $note->update($overrides);
            $note = $note->fresh();
        }

        return $note;
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
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

    private function paymentMethod(Company $company, string $type)
    {
        return \App\Models\PaymentMethod::forCompany($company->id)->where('type', $type)->firstOrFail();
    }

    private function voidSale(Sale $sale, User $user, string $reason = 'Anulación test'): TestResponse
    {
        return $this->actingAs($user)
            ->withSession(['active_company_id' => $sale->company_id, 'active_branch_id' => $sale->branch_id])
            ->post(route('ventas.void', $sale), ['reason' => $reason]);
    }

    private function service(): CreditNoteService
    {
        return app(CreditNoteService::class);
    }

    // ── casos ──

    public function test_valid_number_and_code_authorizes_application(): void
    {
        [$company, $branch, $user, $product] = $this->context();
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);

        $this->checkout($user, $company, $branch, $product, [$this->bearerEntry($note->credit_note_number, $code, '10000')])
            ->assertOk()->assertJsonPath('duplicate', false);

        $sale = Sale::latest('id')->first();
        $this->assertSame(1, CreditNoteApplication::where('sale_id', $sale->id)->count());
        $this->assertDatabaseHas('credit_notes', ['id' => $note->id, 'balance' => '0.0000', 'status' => CreditNote::STATUS_APPLIED]);
    }

    public function test_code_with_dashes_authorizes(): void
    {
        [$company, $branch, $user, $product] = $this->context();
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);

        // El formato XXXX-XXXX-XXXX (con guiones) y el monto total funcionan.
        $this->checkout($user, $company, $branch, $product, [$this->bearerEntry($note->credit_note_number, $code, '10000')])
            ->assertOk();
    }

    public function test_code_without_dashes_authorizes(): void
    {
        [$company, $branch, $user, $product] = $this->context();
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);
        $plain = str_replace('-', '', $code);

        $this->checkout($user, $company, $branch, $product, [$this->bearerEntry($note->credit_note_number, $plain, '10000')])
            ->assertOk();
    }

    public function test_lowercase_code_is_normalized(): void
    {
        [$company, $branch, $user, $product] = $this->context();
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);
        $lower = strtolower($code).' ';

        $this->checkout($user, $company, $branch, $product, [$this->bearerEntry($note->credit_note_number, $lower, '10000')])
            ->assertOk();
    }

    public function test_wrong_code_rejected_generically(): void
    {
        [$company, $branch, $user, $product] = $this->context();
        $company->update(['credit_note_consumer_final' => true]);
        [$note] = $this->consumerFinalNote($company, $branch, $user, 10000);

        $response = $this->checkout($user, $company, $branch, $product, [$this->bearerEntry($note->credit_note_number, 'ABCD2345EFGH', '10000')]);
        $response->assertUnprocessable();
        $this->assertSame(self::GENERIC, $response->json('message'));
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseHas('credit_notes', ['id' => $note->id, 'balance' => '10000.0000']);
    }

    public function test_unknown_number_rejected_generically(): void
    {
        [$company, $branch, $user, $product] = $this->context();
        $company->update(['credit_note_consumer_final' => true]);
        $this->consumerFinalNote($company, $branch, $user, 10000);

        $this->checkout($user, $company, $branch, $product, [$this->bearerEntry('NC-NO-EXISTE', 'ABCD2345EFGH', '10000')])
            ->assertUnprocessable()->assertJsonPath('message', self::GENERIC);
    }

    public function test_nc_from_other_company_rejected_generically(): void
    {
        [$companyA, $branchA, $userA] = $this->context('Empresa A');
        [$companyB, $branchB, $userB, $productB] = $this->context('Empresa B');
        $companyA->update(['credit_note_consumer_final' => true]);
        [$noteA, $codeA] = $this->consumerFinalNote($companyA, $branchA, $userA, 10000);

        $cashSession = $this->ensureCashSession($companyB, $branchB, $userB);
        $response = $this->actingAs($userB)
            ->withSession(['active_company_id' => $companyB->id, 'active_branch_id' => $branchB->id])
            ->postJson(route('pos.checkout'), [
                'checkout_token' => (string) Str::uuid(),
                'cash_session_id' => $cashSession->id,
                'customer_id' => null,
                'payments' => [],
                'items' => [['product_id' => $productB->id, 'quantity' => 1]],
                'credit_note_bearer_applications' => [$this->bearerEntry($noteA->credit_note_number, $codeA, '10000')],
            ]);

        $response->assertUnprocessable()->assertJsonPath('message', self::GENERIC);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseHas('credit_notes', ['id' => $noteA->id, 'balance' => '10000.0000']);
    }

    public function test_nominative_note_cannot_pass_bearer_flow(): void
    {
        [$company, $branch, $user, $product] = $this->context();
        $company->update(['credit_note_consumer_final' => true]);
        $customer = $this->customer($company);
        $note = $this->nominativeNote($company, $branch, $customer, $user, 10000, ['application_code_hash' => hash('sha256', 'SECRETPLACEHOLDER')]);

        try {
            $this->service()->authorizeBearerApplication($company->id, $note->credit_note_number, 'SECRETPLACEHOLDER', '10000');
            $this->fail('No debe autorizarse una NC nominativa por el flujo portador.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertSame(self::GENERIC, $e->errors()['credit_note_bearer_applications'][0]);
        }

        $this->checkout($user, $company, $branch, $product, [$this->bearerEntry($note->credit_note_number, 'SECRETPLACEHOLDER', '10000')])
            ->assertUnprocessable()->assertJsonPath('message', self::GENERIC);
    }

    public function test_null_hash_rejected_generically(): void
    {
        [$company, $branch, $user, $product] = $this->context();
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000, ['application_code_hash' => null]);

        $this->checkout($user, $company, $branch, $product, [$this->bearerEntry($note->credit_note_number, $code, '10000')])
            ->assertUnprocessable()->assertJsonPath('message', self::GENERIC);
    }

    public function test_toggle_off_rejects_even_with_valid_code(): void
    {
        [$company, $branch, $user, $product] = $this->context();
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);

        $company->update(['credit_note_consumer_final' => false]);

        $this->checkout($user, $company, $branch, $product, [$this->bearerEntry($note->credit_note_number, $code, '10000')])
            ->assertUnprocessable()->assertJsonPath('message', self::GENERIC);
        $this->assertDatabaseHas('credit_notes', ['id' => $note->id, 'balance' => '10000.0000']);
    }

    public function test_expired_note_rejected(): void
    {
        [$company, $branch, $user, $product] = $this->context();
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000, ['expires_at' => now()->subDay()]);

        $this->checkout($user, $company, $branch, $product, [$this->bearerEntry($note->credit_note_number, $code, '10000')])
            ->assertUnprocessable()->assertJsonPath('message', self::GENERIC);
        $this->assertDatabaseHas('credit_notes', ['id' => $note->id, 'balance' => '10000.0000']);
    }

    public function test_voided_note_rejected(): void
    {
        [$company, $branch, $user, $product] = $this->context();
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000, ['status' => CreditNote::STATUS_VOIDED, 'voided_at' => now()]);

        $this->checkout($user, $company, $branch, $product, [$this->bearerEntry($note->credit_note_number, $code, '10000')])
            ->assertUnprocessable()->assertJsonPath('message', self::GENERIC);
    }

    public function test_zero_balance_rejected(): void
    {
        [$company, $branch, $user, $product] = $this->context();
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 0, ['balance' => '0.0000', 'status' => CreditNote::STATUS_PARTIALLY_APPLIED]);

        $this->checkout($user, $company, $branch, $product, [$this->bearerEntry($note->credit_note_number, $code, '10000')])
            ->assertUnprocessable()->assertJsonPath('message', self::GENERIC);
    }

    public function test_zero_amount_rejected(): void
    {
        [$company, $branch, $user, $product] = $this->context();
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);

        $this->checkout($user, $company, $branch, $product, [$this->bearerEntry($note->credit_note_number, $code, '0')])
            ->assertUnprocessable();
        $this->assertDatabaseCount('sales', 1);
    }

    public function test_negative_amount_rejected(): void
    {
        [$company, $branch, $user, $product] = $this->context();
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);

        $this->checkout($user, $company, $branch, $product, [$this->bearerEntry($note->credit_note_number, $code, '-5')])
            ->assertUnprocessable();
        $this->assertDatabaseCount('sales', 1);
    }

    public function test_amount_over_balance_rejected_generically(): void
    {
        [$company, $branch, $user, $product] = $this->context();
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);

        $this->checkout($user, $company, $branch, $product, [$this->bearerEntry($note->credit_note_number, $code, '15000')])
            ->assertUnprocessable()->assertJsonPath('message', self::GENERIC);
        $this->assertDatabaseCount('sales', 1);
    }

    public function test_partial_application_leaves_correct_balance(): void
    {
        [$company, $branch, $user, $product] = $this->context();
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);

        $this->checkout($user, $company, $branch, $product, [$this->bearerEntry($note->credit_note_number, $code, '6000')], [
            'payments' => [['payment_method_id' => $this->paymentMethod($company, 'cash')->id, 'amount' => 4000, 'received_amount' => 4000]],
        ])->assertOk();

        $note->refresh();
        $this->assertSame('4000.0000', (string) $note->balance);
        $this->assertSame(CreditNote::STATUS_PARTIALLY_APPLIED, $note->status);

        $app = CreditNoteApplication::firstWhere('credit_note_id', $note->id);
        $this->assertSame('6000.0000', (string) $app->amount);
    }

    public function test_partial_application_and_second_partial_with_same_code(): void
    {
        [$company, $branch, $user, $product] = $this->context();
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 20000);

        $hashBefore = $note->application_code_hash;

        $this->checkout($user, $company, $branch, $product, [$this->bearerEntry($note->credit_note_number, $code, '10000')])
            ->assertOk();

        $note->refresh();
        $this->assertSame('10000.0000', (string) $note->balance);
        $this->assertSame(CreditNote::STATUS_PARTIALLY_APPLIED, $note->status);
        $this->assertSame($hashBefore, $note->application_code_hash);

        $this->checkout($user, $company, $branch, $product, [$this->bearerEntry($note->credit_note_number, $code, '10000')])
            ->assertOk();

        $note->refresh();
        $this->assertSame('0.0000', (string) $note->balance);
        $this->assertSame(CreditNote::STATUS_APPLIED, $note->status);
    }

    public function test_100_percent_note_checkout(): void
    {
        [$company, $branch, $user, $product] = $this->context();
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);

        $this->checkout($user, $company, $branch, $product, [$this->bearerEntry($note->credit_note_number, $code, '10000')])
            ->assertOk();

        $sale = Sale::latest('id')->first();
        $this->assertSame('10000.0000', (string) $sale->paid_total);
        $this->assertSame(0, SalePayment::where('sale_id', $sale->id)->count());
        $this->assertSame(0, CashMovement::where('sale_id', $sale->id)->count());
        $this->assertDatabaseHas('credit_note_applications', ['credit_note_id' => $note->id, 'sale_id' => $sale->id, 'amount' => '10000.0000']);
    }

    public function test_note_plus_cash(): void
    {
        [$company, $branch, $user, $product] = $this->context();
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 4000);
        $cash = $this->paymentMethod($company, 'cash');

        $this->checkout($user, $company, $branch, $product, [$this->bearerEntry($note->credit_note_number, $code, '4000')], [
            'payments' => [['payment_method_id' => $cash->id, 'amount' => 6000, 'received_amount' => 6000]],
        ])->assertOk();

        $sale = Sale::latest('id')->first();
        $payment = SalePayment::where('sale_id', $sale->id)->first();
        $this->assertSame('6000.0000', $payment->amount);
        $this->assertDatabaseHas('credit_notes', ['id' => $note->id, 'balance' => '0.0000']);
    }

    public function test_note_plus_card(): void
    {
        [$company, $branch, $user, $product] = $this->context();
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 4000);
        $card = $this->paymentMethod($company, 'card');

        $this->checkout($user, $company, $branch, $product, [$this->bearerEntry($note->credit_note_number, $code, '4000')], [
            'payments' => [['payment_method_id' => $card->id, 'amount' => 6000, 'reference' => 'CARD-4B2']],
        ])->assertOk();

        $sale = Sale::latest('id')->first();
        $this->assertSame('6000.0000', SalePayment::where('sale_id', $sale->id)->first()->amount);
    }

    public function test_note_plus_sinpe(): void
    {
        [$company, $branch, $user, $product] = $this->context();
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 4000);
        $sinpe = $this->paymentMethod($company, 'sinpe');

        $this->checkout($user, $company, $branch, $product, [$this->bearerEntry($note->credit_note_number, $code, '4000')], [
            'payments' => [['payment_method_id' => $sinpe->id, 'amount' => 6000, 'reference' => 'SINPE-4B2']],
        ])->assertOk();

        $sale = Sale::latest('id')->first();
        $this->assertSame('6000.0000', SalePayment::where('sale_id', $sale->id)->first()->amount);
    }

    public function test_note_plus_credit_when_rules_allow(): void
    {
        [$company, $branch, $user, $product] = $this->context();
        $company->update(['credit_note_consumer_final' => true]);
        $customer = $this->customer($company);
        $customer->update(['credit_limit' => 10000, 'credit_days' => 30]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 4000);
        $credit = $this->paymentMethod($company, 'credit');

        // Convención actual: el pago a crédito informa el total y la NC reduce
        // la cuenta por cobrar creada (Crédito V1).
        $this->checkout($user, $company, $branch, $product, [$this->bearerEntry($note->credit_note_number, $code, '4000')], [
            'customer_id' => $customer->id,
            'payments' => [['payment_method_id' => $credit->id, 'amount' => 10000, 'reference' => 'CRED-4B2']],
        ])->assertOk();

        $sale = Sale::latest('id')->first();
        $this->assertSame(Sale::CONDITION_CREDIT, $sale->sale_condition);
        $this->assertSame('6000.0000', (string) $sale->balance_due);

        $ar = \App\Models\AccountReceivable::where('sale_id', $sale->id)->firstOrFail();
        $this->assertSame('6000.0000', (string) $ar->original_amount);
        $this->assertDatabaseHas('credit_notes', ['id' => $note->id, 'balance' => '0.0000']);
    }

    public function test_destination_sale_without_customer_consumer_final(): void
    {
        [$company, $branch, $user, $product] = $this->context();
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);

        $this->checkout($user, $company, $branch, $product, [$this->bearerEntry($note->credit_note_number, $code, '10000')])
            ->assertOk();

        $sale = Sale::latest('id')->first();
        $this->assertNull($sale->customer_id);
        $app = CreditNoteApplication::firstWhere('sale_id', $sale->id);
        $this->assertNull($app->customer_id);
        $note->refresh();
        $this->assertNull($note->customer_id);
    }

    public function test_destination_sale_with_identified_customer_allowed(): void
    {
        [$company, $branch, $user, $product] = $this->context();
        $company->update(['credit_note_consumer_final' => true]);
        $customer = $this->customer($company);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);

        $this->checkout($user, $company, $branch, $product, [$this->bearerEntry($note->credit_note_number, $code, '10000')], [
            'customer_id' => $customer->id,
        ])->assertOk();

        $sale = Sale::latest('id')->first();
        $this->assertSame($customer->id, $sale->customer_id);
        $app = CreditNoteApplication::firstWhere('sale_id', $sale->id);
        $this->assertSame($customer->id, $app->customer_id);
        $note->refresh();
        $this->assertNull($note->customer_id);
    }

    public function test_nominative_regression(): void
    {
        [$company, $branch, $user, $product] = $this->context();
        $customer = $this->customer($company);
        $note = $this->nominativeNote($company, $branch, $customer, $user, 10000);

        $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->postJson(route('pos.checkout'), [
                'checkout_token' => (string) Str::uuid(),
                'customer_id' => $customer->id,
                'payments' => [],
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'credit_note_applications' => [['credit_note_id' => $note->id, 'amount' => 10000]],
            ])->assertOk();

        $this->assertDatabaseHas('credit_notes', ['id' => $note->id, 'balance' => '0.0000', 'application_code_hash' => null]);
    }

    public function test_bearer_note_rejected_when_sent_as_plain_credit_note_id(): void
    {
        [$company, $branch, $user, $product] = $this->context();
        $company->update(['credit_note_consumer_final' => true]);
        [$note] = $this->consumerFinalNote($company, $branch, $user, 10000);

        // Intentar saltarse el código enviando la NC por el flujo nominativo.
        $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->postJson(route('pos.checkout'), [
                'checkout_token' => (string) Str::uuid(),
                'cash_session_id' => $this->ensureCashSession($company, $branch, $user)->id,
                'customer_id' => null,
                'payments' => [],
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'credit_note_applications' => [['credit_note_id' => $note->id, 'amount' => 10000]],
            ])->assertUnprocessable();

        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseHas('credit_notes', ['id' => $note->id, 'balance' => '10000.0000']);
    }

    public function test_idempotency_same_checkout_token_no_duplicate(): void
    {
        [$company, $branch, $user, $product] = $this->context();
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);
        $token = (string) Str::uuid();
        $cashSession = $this->ensureCashSession($company, $branch, $user);

        $body = [
            'checkout_token' => $token,
            'cash_session_id' => $cashSession->id,
            'customer_id' => null,
            'payments' => [],
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'credit_note_bearer_applications' => [$this->bearerEntry($note->credit_note_number, $code, '10000')],
        ];

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.checkout'), $body)->assertOk();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.checkout'), $body)
            ->assertOk()->assertJsonPath('duplicate', true);

        $this->assertSame(2, Sale::count());
        $this->assertSame(1, CreditNoteApplication::count());
        $this->assertDatabaseHas('credit_notes', ['id' => $note->id, 'balance' => '0.0000']);
    }

    public function test_replay_with_new_token_cannot_double_spend(): void
    {
        [$company, $branch, $user, $product] = $this->context();
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);

        $this->checkout($user, $company, $branch, $product, [$this->bearerEntry($note->credit_note_number, $code, '10000')])
            ->assertOk();

        $this->checkout($user, $company, $branch, $product, [$this->bearerEntry($note->credit_note_number, $code, '10000')])
            ->assertUnprocessable()->assertJsonPath('message', self::GENERIC);

        $this->assertSame(2, Sale::count());
        $this->assertSame(1, CreditNoteApplication::count());
        $this->assertDatabaseHas('credit_notes', ['id' => $note->id, 'balance' => '0.0000']);
    }

    public function test_rate_limit_blocks_requests_when_exhausted(): void
    {
        [$company, $branch, $user, $product] = $this->context();
        $company->update(['credit_note_consumer_final' => true]);
        [$note] = $this->consumerFinalNote($company, $branch, $user, 10000);

        for ($i = 0; $i < 10; $i++) {
            $this->checkout($user, $company, $branch, $product, [$this->bearerEntry($note->credit_note_number, 'AAAAAAAAAAAA', '10000')])
                ->assertUnprocessable();
        }

        $this->checkout($user, $company, $branch, $product, [$this->bearerEntry($note->credit_note_number, 'AAAAAAAAAAAA', '10000')])
            ->assertUnprocessable()->assertJsonPath('message', self::GENERIC);

        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseHas('credit_notes', ['id' => $note->id, 'balance' => '10000.0000']);
    }

    public function test_rate_limit_does_not_block_other_user_or_lock_note(): void
    {
        [$company, $branch, $user, $product] = $this->context();
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);
        $other = $this->user($company, $branch, ['pos.acceder', 'ventas.crear', 'notas_credito.aplicar']);

        for ($i = 0; $i < 10; $i++) {
            $this->checkout($user, $company, $branch, $product, [$this->bearerEntry($note->credit_note_number, 'AAAAAAAAAAAA', '10000')])
                ->assertUnprocessable();
        }

        // El mismo todo el límite en user pero otro usuario aplica OK: no hay
        // bloqueo permanente de la NC ni umbral global.
        $this->checkout($other, $company, $branch, $product, [$this->bearerEntry($note->credit_note_number, $code, '10000')])
            ->assertOk();
    }

    public function test_secret_never_persisted_and_not_in_fingerprint(): void
    {
        [$company, $branch, $user, $product] = $this->context();
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);
        $plain = str_replace('-', '', $code);

        $response = $this->checkout($user, $company, $branch, $product, [$this->bearerEntry($note->credit_note_number, $code, '10000')])
            ->assertOk();

        $sale = Sale::latest('id')->first();

        $this->assertStringNotContainsString($code, $sale->request_fingerprint);
        $this->assertStringNotContainsString($plain, $sale->request_fingerprint);
        $this->assertStringNotContainsString($code, $response->getContent());

        $app = CreditNoteApplication::firstWhere('sale_id', $sale->id);
        $this->assertNull($app->application_code_hash ?? null);

        $note->refresh();
        $this->assertNotNull($note->application_code_hash);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $note->application_code_hash);

        $this->assertSame(0, SalePayment::where('sale_id', $sale->id)->count());
        $this->assertSame(0, CashMovement::where('sale_id', $sale->id)->count());
    }

    public function test_sale_void_restores_balance_and_code_unchanged(): void
    {
        [$company, $branch, $user, $product] = $this->context('Empresa', ['pos.acceder', 'ventas.crear', 'notas_credito.aplicar', 'ventas.anular']);
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);
        $hashBefore = $note->application_code_hash;

        $saleId = $this->checkout($user, $company, $branch, $product, [$this->bearerEntry($note->credit_note_number, $code, '10000')])->json('sale_id');
        $sale = Sale::findOrFail($saleId);

        $this->assertSame('0.0000', (string) $note->refresh()->balance);

        $this->voidSale($sale, $user)->assertRedirect();

        $note->refresh();
        $this->assertSame('10000.0000', (string) $note->balance);
        $this->assertSame(CreditNote::STATUS_ISSUED, $note->status);
        $this->assertSame($hashBefore, $note->application_code_hash);
        $this->assertSame(CreditNoteApplication::STATUS_VOIDED, CreditNoteApplication::firstWhere('sale_id', $sale->id)->status);

        // El mismo código sigue válido tras la reversión.
        $this->checkout($user, $company, $branch, $product, [$this->bearerEntry($note->credit_note_number, $code, '10000')])
            ->assertOk();
    }

    public function test_reversal_after_expiration_restores_balance_but_note_remains_unusable(): void
    {
        [$company, $branch, $user, $product] = $this->context('Empresa', ['pos.acceder', 'ventas.crear', 'notas_credito.aplicar', 'ventas.anular']);
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);

        $saleId = $this->checkout($user, $company, $branch, $product, [$this->bearerEntry($note->credit_note_number, $code, '10000')])->json('sale_id');
        $sale = Sale::findOrFail($saleId);

        $note->update(['expires_at' => now()->subDay()]);

        $this->voidSale($sale, $user)->assertRedirect();

        $note->refresh();
        $this->assertSame('10000.0000', (string) $note->balance);

        $this->checkout($user, $company, $branch, $product, [$this->bearerEntry($note->credit_note_number, $code, '10000')])
            ->assertUnprocessable()->assertJsonPath('message', self::GENERIC);
    }

    public function test_multitenancy_same_number_different_company(): void
    {
        [$companyA, $branchA, $userA] = $this->context('Empresa A');
        [$companyB, $branchB, $userB, $productB] = $this->context('Empresa B');

        $sharedNumber = 'NC-COMPARTIDO-42';

        [$noteA, $codeA] = $this->consumerFinalNote($companyA, $branchA, $userA, 10000, ['credit_note_number' => $sharedNumber]);
        [$noteB, $codeB] = $this->consumerFinalNote($companyB, $branchB, $userB, 10000, ['credit_note_number' => $sharedNumber]);
        $this->assertSame($sharedNumber, $noteA->credit_note_number);
        $this->assertSame($sharedNumber, $noteB->credit_note_number);

        // El código A sobre el número compartido jamás valida la NC de B.
        $cashSession = $this->ensureCashSession($companyB, $branchB, $userB);
        $response = $this->actingAs($userB)
            ->withSession(['active_company_id' => $companyB->id, 'active_branch_id' => $branchB->id])
            ->postJson(route('pos.checkout'), [
                'checkout_token' => (string) Str::uuid(),
                'cash_session_id' => $cashSession->id,
                'customer_id' => null,
                'payments' => [],
                'items' => [['product_id' => $productB->id, 'quantity' => 1]],
                'credit_note_bearer_applications' => [$this->bearerEntry($sharedNumber, $codeA, '10000')],
            ]);

        $response->assertUnprocessable()->assertJsonPath('message', self::GENERIC);

        // Su propio código B sí funciona en B.
        $this->actingAs($userB)
            ->withSession(['active_company_id' => $companyB->id, 'active_branch_id' => $branchB->id])
            ->postJson(route('pos.checkout'), [
                'checkout_token' => (string) Str::uuid(),
                'cash_session_id' => $cashSession->id,
                'customer_id' => null,
                'payments' => [],
                'items' => [['product_id' => $productB->id, 'quantity' => 1]],
                'credit_note_bearer_applications' => [$this->bearerEntry($sharedNumber, $codeB, '10000')],
            ])->assertOk();
    }

    public function test_cashier_with_apply_permission_can_apply(): void
    {
        [$company, $branch, $user, $product] = $this->context('Empresa', ['pos.acceder', 'ventas.crear', 'notas_credito.aplicar']);
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);

        $this->assertTrue($user->hasPermission('notas_credito.aplicar', $company));
        $this->assertFalse($user->hasPermission('notas_credito.crear', $company));
        $this->assertFalse($user->hasPermission('notas_credito.configurar', $company));

        $this->checkout($user, $company, $branch, $product, [$this->bearerEntry($note->credit_note_number, $code, '10000')])
            ->assertOk();
    }

    public function test_user_without_apply_permission_rejected(): void
    {
        [$company, $branch, $user, $product] = $this->context('Empresa', ['pos.acceder', 'ventas.crear']);
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);

        $this->checkout($user, $company, $branch, $product, [$this->bearerEntry($note->credit_note_number, $code, '10000')])
            ->assertUnprocessable();

        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseHas('credit_notes', ['id' => $note->id, 'balance' => '10000.0000']);
    }

    public function test_end_to_end_issue_then_apply_via_code(): void
    {
        [$company, $branch, $user, $product] = $this->context('Empresa', ['pos.acceder', 'ventas.crear', 'notas_credito.aplicar', 'devoluciones.crear']);
        $company->update(['credit_note_consumer_final' => true]);

        // 1) Venta original de Consumer Final.
        $cashSession = $this->ensureCashSession($company, $branch, $user);
        $origin = $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->postJson(route('pos.checkout'), [
                'checkout_token' => (string) Str::uuid(),
                'cash_session_id' => $cashSession->id,
                'customer_id' => null,
                'payments' => [['payment_method_id' => $this->paymentMethod($company, 'cash')->id, 'amount' => 10000, 'received_amount' => 10000]],
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])->assertOk()->json('sale_id');

        $sale = Sale::findOrFail($origin);

        // 2) Devolución emite NC Consumer Final y entrega el código una sola vez.
        $returnResponse = $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->post(route('ventas.return.store', $sale), [
                'reason' => 'Devolución end-to-end',
                'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 1]],
            ])->assertRedirect();

        $delivery = $returnResponse->getSession()->get('consumer_final_delivery');
        $this->assertNotNull($delivery);

        $note = CreditNote::where('credit_note_number', $delivery['credit_note_number'])->firstOrFail();
        $this->assertNull($note->customer_id);
        $this->assertSame('10000.0000', (string) $note->issued_amount);

        // 3) El cajero aplica la NC consumidor final presentando número + código.
        $this->checkout($user, $company, $branch, $product, [$this->bearerEntry($delivery['credit_note_number'], $delivery['application_code'], '10000')])
            ->assertOk();

        $note->refresh();
        $this->assertSame('0.0000', (string) $note->balance);
        $this->assertSame(CreditNote::STATUS_APPLIED, $note->status);
    }
}