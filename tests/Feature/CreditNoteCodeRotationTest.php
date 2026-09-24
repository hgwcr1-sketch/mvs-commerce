<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\CreditNoteApplication;
use App\Models\CreditNoteCodeRotation;
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
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Fase 4B-4: regeneración controlada del código de NC Consumer Final.
 *
 * Verifica rotación segura (nuevo hash, invalidación inmediata del anterior),
 * permisos administrativos, multitenancy, auditoría sin secretos, rate limit,
 * entrega única del nuevo código, invariantes monetarias intactas y regresión
 * de los flujos 4B-2/4B-3 con el código rotado.
 */
class CreditNoteCodeRotationTest extends TestCase
{
    use RefreshDatabase;

    private const GENERIC = 'La Nota de Crédito no está disponible para regenerar su código.';

    private const CODE_PATTERN = '/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{4}-[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{4}-[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{4}$/';

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('cn-rotation-'.uniqid());
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

    private function user(Company $company, Branch $branch, array $permissions, string $roleName = ''): User
    {
        $name = trim($roleName) !== '' ? trim($roleName) : 'Rol '.uniqid();
        $user = User::factory()->create();
        $role = Role::create(['company_id' => $company->id, 'name' => $name, 'is_active' => true]);
        foreach ($permissions as $perm) {
            $role->permissions()->syncWithoutDetaching(Permission::firstOrCreate(
                ['name' => $perm],
                ['label' => $perm, 'module' => 'Notas de Crédito', 'is_active' => true],
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

    private function nominativeNote(Company $company, Branch $branch, User $user): array
    {
        $product = $this->product($company);
        $customer = $this->customer($company);
        $sale = $this->originSale($company, $branch, $user, $product, 10000, $customer);
        $return = $this->returnForOrigin($sale, $user, 10000);
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

    private function regenerate(User $user, Company $company, Branch $branch, int $creditNoteId, string $reason): TestResponse
    {
        return $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->post(route('notas-credito.codes.regenerate', $creditNoteId), ['reason' => $reason]);
    }

    private function regenerateJson(User $user, Company $company, Branch $branch, int $creditNoteId, string $reason): TestResponse
    {
        return $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->postJson(route('notas-credito.codes.regenerate', $creditNoteId), ['reason' => $reason]);
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

    private function rotationCount(int $creditNoteId): int
    {
        return CreditNoteCodeRotation::query()->where('credit_note_id', $creditNoteId)->count();
    }

    // ── superficie / permisos ──

    public function test_index_requires_regenerate_permission(): void
    {
        [$company, $branch] = $this->context();
        $noPerm = $this->user($company, $branch, ['pos.acceder']);
        $this->actingAs($noPerm)->withSession($this->activeSession($company, $branch))
            ->get(route('notas-credito.codes.index'))->assertForbidden();

        $withPerm = $this->user($company, $branch, ['notas_credito.regenerar_codigo']);
        $this->actingAs($withPerm)->withSession($this->activeSession($company, $branch))
            ->get(route('notas-credito.codes.index'))->assertOk();
    }

    public function test_administrador_can_regenerate(): void
    {
        [$company, $branch, $user] = $this->context();
        $admin = $this->user($company, $branch, ['notas_credito.regenerar_codigo'], 'Administrador');
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);

        $this->regenerate($admin, $company, $branch, $note->id, 'El cliente perdió su código')
            ->assertRedirect(route('notas-credito.codes.delivered'));
        $this->assertSame(1, $this->rotationCount($note->id));
    }

    public function test_administrador_local_can_regenerate(): void
    {
        [$company, $branch, $user] = $this->context();
        $admin = $this->user($company, $branch, ['notas_credito.regenerar_codigo'], 'Administrador Local');
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);

        $this->regenerate($admin, $company, $branch, $note->id, 'Código extraviado por el cliente')
            ->assertRedirect(route('notas-credito.codes.delivered'));
        $this->assertSame(1, $this->rotationCount($note->id));
    }

    public function test_cajero_cannot_regenerate(): void
    {
        [$company, $branch, $user] = $this->context();
        $cashier = $this->user($company, $branch, ['pos.acceder', 'notas_credito.aplicar'], 'Cajero');
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);

        $this->regenerate($cashier, $company, $branch, $note->id, 'Motivo')
            ->assertForbidden();
        $this->assertSame(0, $this->rotationCount($note->id));
    }

    public function test_vendedor_cannot_regenerate(): void
    {
        [$company, $branch, $user] = $this->context();
        $seller = $this->user($company, $branch, ['ventas.crear'], 'Vendedor');
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);

        $this->regenerate($seller, $company, $branch, $note->id, 'Motivo')
            ->assertForbidden();
        $this->assertSame(0, $this->rotationCount($note->id));
    }

    public function test_wrong_company_returns_404(): void
    {
        [$company, $branch, $user] = $this->context();
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);

        $other = Company::create(['trade_name' => 'Otra'.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $otherBranch = $this->branch($other, 'Principal');
        $otherAdmin = $this->user($other, $otherBranch, ['notas_credito.regenerar_codigo'], 'Administrador');

        $this->regenerateJson($otherAdmin, $other, $otherBranch, $note->id, 'Intentar NC ajena')
            ->assertNotFound();
        $this->assertSame(0, $this->rotationCount($note->id));
    }

    // ── elegibilidad ──

    public function test_nominative_cannot_regenerate(): void
    {
        [$company, $branch, $user] = $this->context();
        $admin = $this->user($company, $branch, ['notas_credito.regenerar_codigo'], 'Administrador');
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->nominativeNote($company, $branch, $user);

        $response = $this->regenerateJson($admin, $company, $branch, $note->id, 'Motivo de prueba');
        $response->assertSessionHasErrors('credit_note');
        $this->assertSame([self::GENERIC], session('errors')->get('credit_note'));
        $this->assertSame(0, $this->rotationCount($note->id));
    }

    public function test_partially_applied_can_regenerate(): void
    {
        [$company, $branch, $user] = $this->context();
        $admin = $this->user($company, $branch, ['notas_credito.regenerar_codigo'], 'Administrador');
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);

        $note->update(['status' => CreditNote::STATUS_PARTIALLY_APPLIED, 'applied_amount' => '4000.0000', 'balance' => '6000.0000']);

        $this->regenerate($admin, $company, $branch, $note->id, 'Código parcial extraviado')
            ->assertRedirect(route('notas-credito.codes.delivered'));
        $this->assertSame(1, $this->rotationCount($note->id));
        $this->assertDatabaseHas('credit_notes', ['id' => $note->id, 'balance' => '6000.0000', 'applied_amount' => '4000.0000']);
    }

    public function test_zero_balance_cannot_regenerate(): void
    {
        [$company, $branch, $user] = $this->context();
        $admin = $this->user($company, $branch, ['notas_credito.regenerar_codigo'], 'Administrador');
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);
        $note->update(['balance' => '0.0000']);

        $response = $this->regenerateJson($admin, $company, $branch, $note->id, 'Motivo de prueba');
        $response->assertSessionHasErrors('credit_note');
        $this->assertSame([self::GENERIC], session('errors')->get('credit_note'));
        $this->assertSame(0, $this->rotationCount($note->id));
    }

    public function test_fully_applied_cannot_regenerate(): void
    {
        [$company, $branch, $user] = $this->context();
        $admin = $this->user($company, $branch, ['notas_credito.regenerar_codigo'], 'Administrador');
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);
        $note->update(['status' => CreditNote::STATUS_APPLIED, 'applied_amount' => '10000.0000', 'balance' => '0.0000']);

        $response = $this->regenerateJson($admin, $company, $branch, $note->id, 'Motivo de prueba');
        $response->assertSessionHasErrors('credit_note');
        $this->assertSame(0, $this->rotationCount($note->id));
    }

    public function test_voided_cannot_regenerate(): void
    {
        [$company, $branch, $user] = $this->context();
        $admin = $this->user($company, $branch, ['notas_credito.regenerar_codigo'], 'Administrador');
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);
        $note->update(['status' => CreditNote::STATUS_VOIDED, 'balance' => '0.0000']);

        $response = $this->regenerateJson($admin, $company, $branch, $note->id, 'Motivo de prueba');
        $response->assertSessionHasErrors('credit_note');
        $this->assertSame(0, $this->rotationCount($note->id));
    }

    public function test_expired_cannot_regenerate_and_status_unchanged(): void
    {
        [$company, $branch, $user] = $this->context();
        $admin = $this->user($company, $branch, ['notas_credito.regenerar_codigo'], 'Administrador');
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);
        $note->update(['expires_at' => now()->subDay()]);

        $response = $this->regenerateJson($admin, $company, $branch, $note->id, 'Motivo de prueba');
        $response->assertSessionHasErrors('credit_note');
        $this->assertSame([self::GENERIC], session('errors')->get('credit_note'));
        $this->assertSame(0, $this->rotationCount($note->id));
        $this->assertDatabaseHas('credit_notes', ['id' => $note->id, 'status' => CreditNote::STATUS_ISSUED, 'balance' => '10000.0000', 'expires_at' => $note->expires_at]);
    }

    // ── motivo ──

    public function test_reason_is_required(): void
    {
        [$company, $branch, $user] = $this->context();
        $admin = $this->user($company, $branch, ['notas_credito.regenerar_codigo'], 'Administrador');
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);

        $response = $this->regenerateJson($admin, $company, $branch, $note->id, '');
        $response->assertSessionHasErrors('reason');
        $this->assertSame(0, $this->rotationCount($note->id));
    }

    public function test_whitespace_only_reason_rejected(): void
    {
        [$company, $branch, $user] = $this->context();
        $admin = $this->user($company, $branch, ['notas_credito.regenerar_codigo'], 'Administrador');
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);

        $response = $this->regenerateJson($admin, $company, $branch, $note->id, '   ');
        $response->assertSessionHasErrors('reason');
        $this->assertSame(0, $this->rotationCount($note->id));
    }

    public function test_reason_too_short_rejected(): void
    {
        [$company, $branch, $user] = $this->context();
        $admin = $this->user($company, $branch, ['notas_credito.regenerar_codigo'], 'Administrador');
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);

        $response = $this->regenerateJson($admin, $company, $branch, $note->id, 'ab');
        $response->assertSessionHasErrors('reason');
        $this->assertSame(0, $this->rotationCount($note->id));
    }

    public function test_reason_too_long_rejected(): void
    {
        [$company, $branch, $user] = $this->context();
        $admin = $this->user($company, $branch, ['notas_credito.regenerar_codigo'], 'Administrador');
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);

        $response = $this->regenerateJson($admin, $company, $branch, $note->id, str_repeat('a', 501));
        $response->assertSessionHasErrors('reason');
        $this->assertSame(0, $this->rotationCount($note->id));
    }

    public function test_service_rejects_short_and_overlong_reason_directly(): void
    {
        [$company, $branch, $user] = $this->context();
        $admin = $this->user($company, $branch, ['notas_credito.regenerar_codigo'], 'Administrador');
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);

        foreach (['ab', str_repeat('a', 501)] as $badReason) {
            try {
                $this->service()->regenerateApplicationCode($company->id, $note->id, $admin, $badReason);
                $this->fail("Se esperaba ValidationException para el motivo: {$badReason}");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('reason', $e->errors());
            }
            $this->assertSame(0, $this->rotationCount($note->id));
        }

        $this->assertSame($note->fresh()->application_code_hash, $note->application_code_hash);
    }

    // ── rotación segura e invariantes ──

    public function test_rotation_generates_secure_code_and_invalidates_previous(): void
    {
        [$company, $branch, $user] = $this->context();
        $admin = $this->user($company, $branch, ['notas_credito.regenerar_codigo'], 'Administrador');
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $originalCode] = $this->consumerFinalNote($company, $branch, $user, 10000);

        $originalHash = $note->application_code_hash;
        $originalBalance = $note->balance;
        $originalExpires = $note->expires_at?->toDateTimeString();
        $originalIssued = $note->issued_at->toDateTimeString();
        $originalIssuedAmount = $note->issued_amount;
        $originalOffset = $note->offset_amount;
        $originalApplied = $note->applied_amount;
        $originalNumber = $note->credit_note_number;
        $originalCustomer = $note->customer_id;
        $originalReturn = $note->sale_return_id;

        $this->regenerate($admin, $company, $branch, $note->id, 'El cliente perdió su código')->assertRedirect(route('notas-credito.codes.delivered'));

        $rotated = $note->fresh();
        $newHash = $rotated->application_code_hash;

        $this->assertNotSame($originalHash, $newHash);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $newHash);

        $this->assertSame($originalBalance, $rotated->balance);
        $this->assertSame($originalExpires, $rotated->expires_at?->toDateTimeString());
        $this->assertSame($originalIssued, $rotated->issued_at->toDateTimeString());
        $this->assertSame($originalIssuedAmount, $rotated->issued_amount);
        $this->assertSame($originalOffset, $rotated->offset_amount);
        $this->assertSame($originalApplied, $rotated->applied_amount);
        $this->assertSame($originalNumber, $rotated->credit_note_number);
        $this->assertSame($originalCustomer, $rotated->customer_id);
        $this->assertSame($originalReturn, $rotated->sale_return_id);
        $this->assertSame(CreditNote::STATUS_ISSUED, $rotated->status);
    }

    public function test_old_code_stops_authorizing_and_new_code_authorizes(): void
    {
        [$company, $branch, $user] = $this->context();
        $admin = $this->user($company, $branch, ['notas_credito.regenerar_codigo'], 'Administrador');
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $originalCode] = $this->consumerFinalNote($company, $branch, $user, 10000);

        $this->regenerate($admin, $company, $branch, $note->id, 'Código vencido pide cambio')->assertRedirect(route('notas-credito.codes.delivered'));

        $newCode = session()->get('credit_note_rotation_delivery.application_code');
        $this->assertNotEmpty($newCode);

        $this->bearerValidate($user, $company, $branch, $note->credit_note_number, $originalCode, '10000')
            ->assertUnprocessable();

        $this->bearerValidate($user, $company, $branch, $note->credit_note_number, $newCode, '10000')
            ->assertOk();
    }

    public function test_new_code_matches_certified_format_and_differs(): void
    {
        [$company, $branch, $user] = $this->context();
        $admin = $this->user($company, $branch, ['notas_credito.regenerar_codigo'], 'Administrador');
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $originalCode] = $this->consumerFinalNote($company, $branch, $user, 10000);

        $this->regenerate($admin, $company, $branch, $note->id, 'Formato de código')->assertRedirect(route('notas-credito.codes.delivered'));

        $newCode = session()->get('credit_note_rotation_delivery.application_code');
        $this->assertMatchesRegularExpression(self::CODE_PATTERN, $newCode);
        $this->assertNotSame($originalCode, $newCode);
    }

    public function test_plaintext_new_code_is_not_persisted(): void
    {
        [$company, $branch, $user] = $this->context();
        $admin = $this->user($company, $branch, ['notas_credito.regenerar_codigo'], 'Administrador');
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $originalCode] = $this->consumerFinalNote($company, $branch, $user, 10000);

        $this->regenerate($admin, $company, $branch, $note->id, 'Código perdido')->assertRedirect(route('notas-credito.codes.delivered'));

        $newCode = session()->get('credit_note_rotation_delivery.application_code');
        $hash = CreditNoteService::applicationCodeHash($newCode);

        $this->assertDatabaseHas('credit_notes', ['id' => $note->id, 'application_code_hash' => $hash]);
        $this->assertDatabaseMissing('credit_notes', ['id' => $note->id, 'application_code_hash' => $newCode]);
        $this->assertDatabaseMissing('credit_notes', ['id' => $note->id, 'application_code_hash' => $originalCode]);

        $rotation = CreditNoteCodeRotation::query()->where('credit_note_id', $note->id)->firstOrFail();
        $this->assertStringNotContainsString($newCode, $rotation->reason);
        $this->assertStringNotContainsString($originalCode, $rotation->reason);
        $this->assertSame('Código perdido', $rotation->reason);
    }

    public function test_audit_table_stores_no_codes_or_hashes(): void
    {
        $columns = Schema::getColumnListing('credit_note_code_rotations');
        foreach ($columns as $column) {
            $this->assertStringNotContainsStringIgnoringCase('hash', $column, 'La auditoría no debe almacenar hashes.');
            $this->assertStringNotContainsStringIgnoringCase('code', $column, 'La auditoría no debe almacenar códigos.');
        }
        $this->assertContains('reason', $columns);
        $this->assertContains('company_id', $columns);
        $this->assertContains('credit_note_id', $columns);
        $this->assertContains('user_id', $columns);
        $this->assertContains('created_at', $columns);
    }

    public function test_audit_records_user_company_note_reason_and_time(): void
    {
        [$company, $branch, $user] = $this->context();
        $admin = $this->user($company, $branch, ['notas_credito.regenerar_codigo'], 'Administrador');
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);

        $this->regenerate($admin, $company, $branch, $note->id, 'Motivo de auditoría')->assertRedirect(route('notas-credito.codes.delivered'));

        $rotation = CreditNoteCodeRotation::query()->where('credit_note_id', $note->id)->firstOrFail();
        $this->assertSame($company->id, $rotation->company_id);
        $this->assertSame($note->id, $rotation->credit_note_id);
        $this->assertSame($admin->id, $rotation->user_id);
        $this->assertSame('Motivo de auditoría', $rotation->reason);
        $this->assertNotNull($rotation->created_at);
    }

    public function test_rotation_creates_no_sale_entities_or_applications(): void
    {
        [$company, $branch, $user] = $this->context();
        $admin = $this->user($company, $branch, ['notas_credito.regenerar_codigo'], 'Administrador');
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);

        $applicationsBefore = CreditNoteApplication::count();
        $salePaymentsBefore = SalePayment::count();
        $salesBefore = Sale::count();
        $cashMovementsBefore = DB::table('cash_movements')->count();

        $this->regenerate($admin, $company, $branch, $note->id, 'Solo rotación')->assertRedirect(route('notas-credito.codes.delivered'));

        $this->assertSame($applicationsBefore, CreditNoteApplication::count());
        $this->assertSame($salePaymentsBefore, SalePayment::count());
        $this->assertSame($salesBefore, Sale::count());
        $this->assertSame($cashMovementsBefore, DB::table('cash_movements')->count());
    }

    public function test_application_history_is_preserved(): void
    {
        [$company, $branch, $user] = $this->context();
        $admin = $this->user($company, $branch, ['notas_credito.regenerar_codigo'], 'Administrador');
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);

        $app = CreditNoteApplication::create([
            'company_id' => $note->company_id,
            'credit_note_id' => $note->id,
            'sale_id' => $note->sale_id,
            'customer_id' => null,
            'amount' => '2000.0000',
            'application_token' => 'history-'.uniqid(),
            'applied_by' => $user->id,
            'applied_at' => now(),
            'status' => CreditNoteApplication::STATUS_APPLIED,
            'notes' => 'Historial previo',
        ]);

        $this->regenerate($admin, $company, $branch, $note->id, 'Rotar con historial')->assertRedirect(route('notas-credito.codes.delivered'));

        $this->assertDatabaseHas('credit_note_applications', ['id' => $app->id, 'amount' => '2000.0000', 'status' => CreditNoteApplication::STATUS_APPLIED]);
        $this->assertSame(1, CreditNoteApplication::query()->where('credit_note_id', $note->id)->count());
    }

    // ── rate limit ──

    public function test_rate_limit_blocks_after_five_regenerations_per_minute(): void
    {
        [$company, $branch, $user] = $this->context();
        $admin = $this->user($company, $branch, ['notas_credito.regenerar_codigo'], 'Administrador');
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);

        RateLimiter::clear('cn-rotation:'.$company->id.':'.$admin->id);

        for ($i = 1; $i <= 5; $i++) {
            $this->regenerate($admin, $company, $branch, $note->id, 'Rotación '.$i)
                ->assertRedirect(route('notas-credito.codes.delivered'));
        }

        $blocked = $this->regenerate($admin, $company, $branch, $note->id, 'Sexta rotación');
        $blocked->assertSessionHasErrors('reason');

        $this->assertSame(5, $this->rotationCount($note->id));
    }

    // ── entrega única ──

    public function test_delivered_page_shows_new_code_once_then_hides_it(): void
    {
        [$company, $branch, $user] = $this->context();
        $admin = $this->user($company, $branch, ['notas_credito.regenerar_codigo'], 'Administrador');
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);

        $this->regenerate($admin, $company, $branch, $note->id, 'Entrega única')->assertRedirect(route('notas-credito.codes.delivered'));

        $newCode = session()->get('credit_note_rotation_delivery.application_code');
        $this->assertNotEmpty($newCode);

        $first = $this->actingAs($admin)->withSession($this->activeSession($company, $branch))
            ->get(route('notas-credito.codes.delivered'))->assertOk();
        $first->assertSee($note->credit_note_number);
        $first->assertSee($newCode);
        $this->assertStringContainsString('no-store', $first->headers->get('Cache-Control'));

        $second = $this->actingAs($admin)->withSession($this->activeSession($company, $branch))
            ->get(route('notas-credito.codes.delivered'));
        $second->assertRedirect(route('notas-credito.codes.index'));
    }

    // ── regresión 4B-2 / 4B-3 ──

    public function test_rotated_code_applies_at_pos_checkout(): void
    {
        [$company, $branch, $user, $product] = $this->context();
        $admin = $this->user($company, $branch, ['notas_credito.regenerar_codigo'], 'Administrador');
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $user, 10000);

        $this->regenerate($admin, $company, $branch, $note->id, 'Rotado para checkout')->assertRedirect(route('notas-credito.codes.delivered'));
        $newCode = session()->get('credit_note_rotation_delivery.application_code');

        $this->checkout($user, $company, $branch, $product, [$this->bearerEntry($note->credit_note_number, $newCode, '10000')])
            ->assertOk()->assertJsonPath('duplicate', false);

        $sale = Sale::latest('id')->first();
        $this->assertSame(1, CreditNoteApplication::where('sale_id', $sale->id)->count());
        $this->assertDatabaseHas('credit_notes', ['id' => $note->id, 'balance' => '0.0000', 'status' => CreditNote::STATUS_APPLIED]);
    }

    public function test_bearer_validate_requires_aplicar_permission_not_regenerar(): void
    {
        [$company, $branch] = $this->context();
        $company->update(['credit_note_consumer_final' => true]);
        $issuer = $this->user($company, $branch, ['pos.acceder', 'ventas.crear', 'notas_credito.aplicar']);
        [$note, $code] = $this->consumerFinalNote($company, $branch, $issuer, 10000);

        $regeneratorOnly = $this->user($company, $branch, ['notas_credito.regenerar_codigo'], 'Administrador');
        $this->bearerValidate($regeneratorOnly, $company, $branch, $note->credit_note_number, $code, '10000')->assertForbidden();
    }

    public function test_nominative_flow_unchanged_after_rotation_feature(): void
    {
        [$company, $branch, $user] = $this->context();
        $company->update(['credit_note_consumer_final' => true]);
        [$note, $code] = $this->nominativeNote($company, $branch, $user);

        $this->assertFalse($note->isConsumerFinal());
        $this->assertNull($note->application_code_hash);
        $this->assertSame(CreditNote::STATUS_ISSUED, $note->fresh()->status);
        $this->assertSame('10000.0000', $note->fresh()->balance);

        $available = $this->service()->availableForCustomer($company->id, $note->customer_id);
        $this->assertTrue($available->contains('id', $note->id));
    }
}