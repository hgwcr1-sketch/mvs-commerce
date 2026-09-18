<?php

namespace Tests\Feature;

use App\Models\AccountReceivable;
use App\Models\AccountReceivableAdjustment;
use App\Models\AccountReceivablePayment;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\PaymentMethod;
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
use App\Services\Sales\AccountsReceivableReconciliationService;
use App\Services\Sales\CreditNoteService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class CreditNoteReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $suffix = ''): Company
    {
        return Company::create([
            'trade_name' => 'Empresa '.$suffix.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
    }

    private function branch(Company $company, string $name): Branch
    {
        return Branch::create([
            'company_id' => $company->id,
            'name' => $name,
            'code' => strtoupper(substr($name, 0, 4)).'-'.$company->id.'-'.uniqid(),
            'is_active' => true,
        ]);
    }

    private function userWithPermission(Company $company, Branch $branch, array $permissions): User
    {
        $user = User::factory()->create();

        $role = Role::create([
            'company_id' => $company->id,
            'name' => 'Rol NC '.uniqid(),
            'is_active' => true,
        ]);

        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['label' => $name, 'module' => 'Ventas', 'is_active' => true],
            );
            $role->permissions()->syncWithoutDetaching($permission);
        }

        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return $user;
    }

    private function customer(Company $company, string $name = 'Cliente'): Customer
    {
        return Customer::create([
            'company_id' => $company->id,
            'name' => $name.' '.uniqid(),
            'identification' => 'NCID-'.uniqid(),
            'is_active' => true,
        ]);
    }

    private function product(Company $company, bool $trackInventory = false): Product
    {
        $category = ProductCategory::create([
            'company_id' => $company->id,
            'name' => 'Categoria '.uniqid(),
            'slug' => 'categoria-'.uniqid(),
            'is_active' => true,
        ]);

        $unit = Unit::create([
            'company_id' => $company->id,
            'name' => 'Unidad',
            'abbreviation' => 'U',
            'slug' => 'u-'.uniqid(),
            'allows_decimals' => true,
            'is_active' => true,
        ]);

        return Product::create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'name' => 'Producto '.uniqid(),
            'internal_code' => 'P-'.uniqid(),
            'cost' => 500,
            'sale_price' => 1000,
            'stock' => 50,
            'tax_rate' => 13,
            'track_inventory' => $trackInventory,
            'is_active' => true,
        ]);
    }

    private function paymentMethodId(Company $company): int
    {
        return PaymentMethod::firstOrCreate(
            ['company_id' => $company->id, 'code' => 'EFECTIVO-'.$company->id],
            [
                'name' => 'Efectivo',
                'type' => 'cash',
                'allows_change' => true,
                'affects_cash' => true,
                'is_active' => true,
            ],
        )->id;
    }

    private function completedSale(
        Company $company,
        Branch $branch,
        User $user,
        int $productId,
        int $qty,
        ?Customer $customer = null,
    ): Sale {
        $sale = Sale::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'customer_id' => $customer?->id,
            'checkout_token' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', uniqid('ncrecon', true)),
            'sale_number' => 'POS-RECON-'.uniqid(),
            'document_type' => Sale::DOCUMENT_ELECTRONIC_TICKET,
            'sale_condition' => Sale::CONDITION_CASH,
            'status' => Sale::STATUS_COMPLETED,
            'currency_code' => 'CRC',
            'exchange_rate' => 1,
            'subtotal' => 1000 * $qty,
            'discount_total' => 0,
            'tax_total' => 130 * $qty,
            'rounding_total' => 0,
            'total' => 1130 * $qty,
            'paid_total' => 0,
            'balance_due' => 1130 * $qty,
            'completed_at' => now(),
        ]);

        $sale->items()->create([
            'product_id' => $productId,
            'product_code' => 'P-CODE',
            'barcode' => null,
            'cabys_code' => null,
            'description' => 'Producto NC',
            'unit_code' => 'U',
            'quantity' => $qty,
            'unit_price' => 1000,
            'gross_total' => 1000 * $qty,
            'discount_total' => 0,
            'subtotal' => 1000 * $qty,
            'tax_rate' => 13,
            'tax_total' => 130 * $qty,
            'total' => 1130 * $qty,
            'unit_cost' => 600,
        ]);

        return $sale;
    }

    private function postReturn(User $user, Company $company, Branch $branch, Sale $sale, array $payload)
    {
        return $this->actingAs($user)
            ->withSession([
                'active_company_id' => $company->id,
                'active_branch_id' => $branch->id,
            ])
            ->post(route('ventas.return.store', $sale), $payload);
    }

    private function creditNotes(): CreditNoteService
    {
        return app(CreditNoteService::class);
    }

    private function scenario(): array
    {
        $company = $this->company();
        $branch = $this->branch($company, 'Principal');
        $user = $this->userWithPermission($company, $branch, ['devoluciones.crear']);
        $customer = $this->customer($company);

        return [$company, $branch, $user, $customer];
    }

    /**
     * NC 20.000 / deuda 30.000
     * → offset 20.000
     * → deuda 10.000
     * → NC disponible 0
     */
    public function test_nc_smaller_than_ar_balance_offsets_full_nc_amount(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $product = $this->product($company);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 20, $customer);

        AccountReceivable::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sale_id' => $sale->id,
            'issued_at' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'original_amount' => '22600.0000',
            'balance_due' => '30000.0000',
            'status' => AccountReceivable::STATUS_PARTIAL,
            'currency_code' => 'CRC',
        ]);

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Devolucion parcial NC < AR',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 2]],
        ])->assertSessionHasNoErrors();

        $note = CreditNote::sole();
        $this->assertSame('2260.0000', (string) $note->issued_amount);
        $this->assertSame('2260.0000', (string) $note->offset_amount);
        $this->assertSame('0.0000', (string) $note->applied_amount);
        $this->assertSame('0.0000', (string) $note->balance);
        $this->assertFalse((bool) $note->requires_ar_review);

        $ar = AccountReceivable::firstWhere('sale_id', $sale->id);
        $this->assertSame('27740.0000', (string) $ar->balance_due);

        $this->assertDatabaseCount('accounts_receivable_payments', 0);
    }

    /**
     * NC 22600 (full return of 20 items) / deuda 5000
     * → offset 5000
     * → deuda 0
     * → NC disponible 17600
     */
    public function test_nc_larger_than_ar_balance_offsets_only_ar_balance(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $product = $this->product($company);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 20, $customer);

        AccountReceivable::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sale_id' => $sale->id,
            'issued_at' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'original_amount' => '22600.0000',
            'balance_due' => '5000.0000',
            'status' => AccountReceivable::STATUS_PARTIAL,
            'currency_code' => 'CRC',
        ]);

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Devolucion NC > AR',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 20]],
        ])->assertSessionHasNoErrors();

        $note = CreditNote::sole();
        $this->assertSame('22600.0000', (string) $note->issued_amount);
        $this->assertSame('5000.0000', (string) $note->offset_amount);
        $this->assertSame('0.0000', (string) $note->applied_amount);
        $this->assertSame('17600.0000', (string) $note->balance);
        $this->assertFalse((bool) $note->requires_ar_review);

        $ar = AccountReceivable::firstWhere('sale_id', $sale->id);
        $this->assertSame('0.0000', (string) $ar->balance_due);
        $this->assertSame(AccountReceivable::STATUS_PAID, $ar->status);

        $this->assertDatabaseCount('accounts_receivable_payments', 0);
    }

    /**
     * NC 20.000 / deuda ya pagada
     * → offset 0
     * → NC disponible 20.000
     */
    public function test_nc_on_already_paid_ar_creates_no_offset(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $product = $this->product($company);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 20, $customer);

        AccountReceivable::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sale_id' => $sale->id,
            'issued_at' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'original_amount' => '22600.0000',
            'balance_due' => '0.0000',
            'status' => AccountReceivable::STATUS_PAID,
            'currency_code' => 'CRC',
        ]);

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Devolucion NC deuda pagada',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 2]],
        ])->assertSessionHasNoErrors();

        $note = CreditNote::sole();
        $this->assertSame('2260.0000', (string) $note->issued_amount);
        $this->assertSame('0.0000', (string) $note->offset_amount);
        $this->assertSame('0.0000', (string) $note->applied_amount);
        $this->assertSame('2260.0000', (string) $note->balance);
        $this->assertFalse((bool) $note->requires_ar_review);
        $this->assertSame(CreditNote::STATUS_ISSUED, $note->status);

        $this->assertDatabaseCount('accounts_receivable_payments', 0);
        $this->assertDatabaseCount('accounts_receivable_adjustments', 0);
    }

    public function test_nc_on_sale_without_ar_has_no_offset(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $product = $this->product($company);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 5, $customer);

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Devolucion sin CxC',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 2]],
        ])->assertSessionHasNoErrors();

        $note = CreditNote::sole();
        $this->assertSame('2260.0000', (string) $note->issued_amount);
        $this->assertSame('0.0000', (string) $note->offset_amount);
        $this->assertSame('2260.0000', (string) $note->balance);
        $this->assertFalse((bool) $note->requires_ar_review);

        $this->assertDatabaseCount('accounts_receivable_adjustments', 0);
    }

    public function test_reconciliation_is_idempotent(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $product = $this->product($company);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 5, $customer);

        $ar = AccountReceivable::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sale_id' => $sale->id,
            'issued_at' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'original_amount' => '5650.0000',
            'balance_due' => '5650.0000',
            'status' => AccountReceivable::STATUS_PENDING,
            'currency_code' => 'CRC',
        ]);

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Devolucion idempotente',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 3]],
        ])->assertSessionHasNoErrors();

        $note = CreditNote::sole();
        $this->assertSame('3390.0000', (string) $note->offset_amount);
        $this->assertSame('0.0000', (string) $note->balance);
        $this->assertDatabaseCount('accounts_receivable_adjustments', 1);

        $existingAdjustment = AccountReceivableAdjustment::sole();

        $reconService = app(AccountsReceivableReconciliationService::class);

        $second = $reconService->reconcile($note->fresh(), $user);

        $this->assertNull($second);
        $this->assertDatabaseCount('accounts_receivable_adjustments', 1);

        $this->assertSame($existingAdjustment->fresh()->id, AccountReceivableAdjustment::sole()->id);
        $this->assertSame('3390.0000', (string) $note->fresh()->offset_amount);
    }

    public function test_adjustment_does_not_create_payment_or_cash_movement(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $product = $this->product($company);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 5, $customer);

        AccountReceivable::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sale_id' => $sale->id,
            'issued_at' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'original_amount' => '5650.0000',
            'balance_due' => '5650.0000',
            'status' => AccountReceivable::STATUS_PENDING,
            'currency_code' => 'CRC',
        ]);

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Devolucion sin efecto caja',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 2]],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('accounts_receivable_payments', 0);
        $this->assertDatabaseCount('cash_movements', 0);

        $adjustment = AccountReceivableAdjustment::sole();
        $this->assertSame(AccountReceivableAdjustment::TYPE_CREDIT_NOTE_OFFSET, $adjustment->type);
        $this->assertSame(AccountReceivableAdjustment::STATUS_ACTIVE, $adjustment->status);
    }

    public function test_nc_with_active_offset_cannot_be_voided(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $product = $this->product($company);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 20, $customer);

        AccountReceivable::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sale_id' => $sale->id,
            'issued_at' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'original_amount' => '22600.0000',
            'balance_due' => '3000.0000',
            'status' => AccountReceivable::STATUS_PARTIAL,
            'currency_code' => 'CRC',
        ]);

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Devolucion NC con offset parcial',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 5]],
        ])->assertSessionHasNoErrors();

        $note = CreditNote::sole();
        $this->assertSame('3000.0000', (string) $note->offset_amount);
        $this->assertSame('2650.0000', (string) $note->balance);
        $this->assertSame(CreditNote::STATUS_PARTIALLY_APPLIED, $note->status);

        try {
            $this->creditNotes()->void($note, $user, 'Intento anular NC con offset');
            $this->fail('Se esperaba rechazo de anulacion para NC con compensaciones CxC activas.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('credit_note', $e->errors());
            $this->assertStringContainsString('compensaciones', $e->errors()['credit_note'][0]);
        }

        $note->refresh();
        $this->assertSame(CreditNote::STATUS_PARTIALLY_APPLIED, $note->status);
        $this->assertNull($note->voided_at);
    }

    public function test_financial_invariant_issued_equals_offset_plus_applied_plus_balance(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $product = $this->product($company);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 5, $customer);

        AccountReceivable::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sale_id' => $sale->id,
            'issued_at' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'original_amount' => '5650.0000',
            'balance_due' => '3000.0000',
            'status' => AccountReceivable::STATUS_PARTIAL,
            'currency_code' => 'CRC',
        ]);

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Devolucion invariant',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 2]],
        ])->assertSessionHasNoErrors();

        $note = CreditNote::sole();

        $sum = bcadd(
            (string) $note->offset_amount,
            bcadd((string) $note->applied_amount, (string) $note->balance, 4),
            4,
        );
        $this->assertSame((string) $note->issued_amount, $sum);
    }

    public function test_conciliation_sets_ar_status_to_paid_when_fully_offset(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $product = $this->product($company);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 5, $customer);

        AccountReceivable::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sale_id' => $sale->id,
            'issued_at' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'original_amount' => '5650.0000',
            'balance_due' => '2260.0000',
            'status' => AccountReceivable::STATUS_PARTIAL,
            'currency_code' => 'CRC',
        ]);

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Devolucion paga CxC',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 2]],
        ])->assertSessionHasNoErrors();

        $ar = AccountReceivable::firstWhere('sale_id', $sale->id);
        $this->assertSame('0.0000', (string) $ar->balance_due);
        $this->assertSame(AccountReceivable::STATUS_PAID, $ar->status);
    }

    public function test_conciliation_preserves_ar_status_when_partial_offset(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $product = $this->product($company);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 5, $customer);

        AccountReceivable::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sale_id' => $sale->id,
            'issued_at' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'original_amount' => '5650.0000',
            'balance_due' => '5650.0000',
            'status' => AccountReceivable::STATUS_PENDING,
            'currency_code' => 'CRC',
        ]);

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Devolucion parcial CxC',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 2]],
        ])->assertSessionHasNoErrors();

        $ar = AccountReceivable::firstWhere('sale_id', $sale->id);
        $this->assertSame('3390.0000', (string) $ar->balance_due);
        $this->assertSame(AccountReceivable::STATUS_PENDING, $ar->status);
    }

    public function test_adjustment_records_balance_before_and_after(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $product = $this->product($company);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 5, $customer);

        AccountReceivable::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sale_id' => $sale->id,
            'issued_at' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'original_amount' => '5650.0000',
            'balance_due' => '5650.0000',
            'status' => AccountReceivable::STATUS_PENDING,
            'currency_code' => 'CRC',
        ]);

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Devolucion auditoria',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 2]],
        ])->assertSessionHasNoErrors();

        $adjustment = AccountReceivableAdjustment::sole();
        $this->assertSame('5650.0000', (string) $adjustment->balance_before);
        $this->assertSame('3390.0000', (string) $adjustment->balance_after);
        $this->assertSame('2260.0000', (string) $adjustment->amount);
        $this->assertNotNull($adjustment->idempotency_key);
        $this->assertSame($user->id, (int) $adjustment->created_by);
    }

    public function test_void_sale_with_active_adjustment_is_rejected(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $product = $this->product($company);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 5, $customer);

        $account = AccountReceivable::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sale_id' => $sale->id,
            'issued_at' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'original_amount' => '5650.0000',
            'balance_due' => '5650.0000',
            'status' => AccountReceivable::STATUS_PENDING,
            'currency_code' => 'CRC',
        ]);

        AccountReceivableAdjustment::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'account_receivable_id' => $account->id,
            'credit_note_id' => null,
            'type' => AccountReceivableAdjustment::TYPE_CREDIT_NOTE_OFFSET,
            'amount' => '2260.0000',
            'balance_before' => '5650.0000',
            'balance_after' => '3390.0000',
            'reason' => 'Ajuste directo para prueba',
            'status' => AccountReceivableAdjustment::STATUS_ACTIVE,
            'idempotency_key' => 'test-void-sale-adjustment',
            'created_by' => $user->id,
        ]);

        $this->assertSame(Sale::STATUS_COMPLETED, $sale->status);

        session(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('compensaciones CxC activas');
        app(\App\Services\Sales\SaleVoidService::class)->void($sale, $user, 'Intento anular con offset activo');
    }

    public function test_conciliation_occurs_atomically_with_return(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $product = $this->product($company);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 5, $customer);

        AccountReceivable::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sale_id' => $sale->id,
            'issued_at' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'original_amount' => '5650.0000',
            'balance_due' => '5650.0000',
            'status' => AccountReceivable::STATUS_PENDING,
            'currency_code' => 'CRC',
        ]);

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Atomicidad',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 2]],
        ])->assertSessionHasNoErrors();

        $note = CreditNote::sole();
        $adjustment = AccountReceivableAdjustment::sole();
        $ar = AccountReceivable::firstWhere('sale_id', $sale->id);

        $this->assertSame($note->id, (int) $adjustment->credit_note_id);
        $this->assertSame($ar->id, (int) $adjustment->account_receivable_id);
        $this->assertSame($company->id, (int) $adjustment->company_id);
        $this->assertSame($branch->id, (int) $adjustment->branch_id);
        $this->assertFalse((bool) $note->requires_ar_review);
    }

    public function test_nc_on_cancelled_ar_has_no_offset(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $product = $this->product($company);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 5, $customer);

        AccountReceivable::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sale_id' => $sale->id,
            'issued_at' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'original_amount' => '5650.0000',
            'balance_due' => '0.0000',
            'status' => AccountReceivable::STATUS_CANCELLED,
            'currency_code' => 'CRC',
        ]);

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Devolucion CxC cancelada',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 2]],
        ])->assertSessionHasNoErrors();

        $note = CreditNote::sole();
        $this->assertSame('0.0000', (string) $note->offset_amount);
        $this->assertSame('2260.0000', (string) $note->balance);
        $this->assertFalse((bool) $note->requires_ar_review);
        $this->assertDatabaseCount('accounts_receivable_adjustments', 0);
    }

    public function test_historical_payments_preserved_after_nc_offset(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $product = $this->product($company);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 5, $customer);

        $ar = AccountReceivable::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sale_id' => $sale->id,
            'issued_at' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'original_amount' => '5650.0000',
            'balance_due' => '5650.0000',
            'status' => AccountReceivable::STATUS_PENDING,
            'currency_code' => 'CRC',
        ]);

        $cashRegister = \App\Models\CashRegister::firstOrCreate(
            ['company_id' => $company->id, 'code' => 'CAJA-TEST-'.$company->id],
            ['branch_id' => $branch->id, 'name' => 'Caja Test', 'is_active' => true, 'is_default' => true],
        );

        $cashSession = \App\Models\CashSession::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'cash_register_id' => $cashRegister->id,
            'session_number' => 'S-HP-001',
            'opened_by' => $user->id,
            'status' => \App\Models\CashSession::STATUS_OPEN,
            'currency_code' => 'CRC',
            'opening_amount' => '0.0000',
            'expected_cash' => '0.0000',
            'tolerance_snapshot' => '0.0000',
            'opened_at' => now()->subDays(10),
        ]);

        $methodCash = PaymentMethod::firstOrCreate(
            ['company_id' => $company->id, 'code' => 'EFECTIVO-'.$company->id],
            ['name' => 'Efectivo', 'type' => 'cash', 'allows_change' => true, 'affects_cash' => true, 'is_active' => true],
        );

        $methodSinpe = PaymentMethod::firstOrCreate(
            ['company_id' => $company->id, 'code' => 'SINPE-'.$company->id],
            ['name' => 'SINPE', 'type' => 'sinpe', 'allows_change' => false, 'affects_cash' => true, 'is_active' => true],
        );

        DB::table('accounts_receivable_payments')->insert([
            [
                'account_receivable_id' => $ar->id,
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'customer_id' => $customer->id,
                'user_id' => $user->id,
                'cash_session_id' => $cashSession->id,
                'payment_method_id' => $methodCash->id,
                'amount' => '1000.0000',
                'affects_cash_snapshot' => true,
                'cash_effect_amount' => '1000.0000',
                'reference' => 'PAGO-REF-001',
                'notes' => 'Primer abono',
                'paid_at' => now()->subDays(5),
                'created_at' => now()->subDays(5),
                'updated_at' => now()->subDays(5),
            ],
            [
                'account_receivable_id' => $ar->id,
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'customer_id' => $customer->id,
                'user_id' => $user->id,
                'cash_session_id' => $cashSession->id,
                'payment_method_id' => $methodSinpe->id,
                'amount' => '2000.0000',
                'affects_cash_snapshot' => true,
                'cash_effect_amount' => '2000.0000',
                'reference' => 'SINPE-REF-002',
                'notes' => 'Segundo abono',
                'paid_at' => now()->subDays(3),
                'created_at' => now()->subDays(3),
                'updated_at' => now()->subDays(3),
            ],
        ]);

        $ar->update(['balance_due' => '2650.0000', 'status' => AccountReceivable::STATUS_PARTIAL]);

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Devolucion con pagos historicos',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 2]],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('accounts_receivable_payments', 2);

        $p1 = DB::table('accounts_receivable_payments')
            ->where('reference', 'PAGO-REF-001')->first();
        $this->assertEquals(1000, (int) $p1->amount);
        $this->assertSame($methodCash->id, $p1->payment_method_id);
        $this->assertNotNull($p1->paid_at);
        $this->assertSame('Primer abono', $p1->notes);

        $p2 = DB::table('accounts_receivable_payments')
            ->where('reference', 'SINPE-REF-002')->first();
        $this->assertEquals(2000, (int) $p2->amount);
        $this->assertSame($methodSinpe->id, $p2->payment_method_id);
        $this->assertSame('Segundo abono', $p2->notes);

        $this->assertDatabaseCount('accounts_receivable_adjustments', 1);

        $note = CreditNote::sole();
        $this->assertSame('2260.0000', (string) $note->offset_amount);
        $this->assertSame('0.0000', (string) $note->balance);

        $ar->refresh();
        $this->assertSame('390.0000', (string) $ar->balance_due);

        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_reconcile_rejects_nc_and_ar_on_different_branch(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $branchB = $this->branch($company, 'Sucursal B');
        $product = $this->product($company);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 5, $customer);

        AccountReceivable::create([
            'company_id' => $company->id,
            'branch_id' => $branchB->id,
            'customer_id' => $customer->id,
            'sale_id' => $sale->id,
            'issued_at' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'original_amount' => '5650.0000',
            'balance_due' => '5650.0000',
            'status' => AccountReceivable::STATUS_PENDING,
            'currency_code' => 'CRC',
        ]);

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Devolucion branch distinta',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 2]],
        ])->assertSessionHasNoErrors();

        $note = CreditNote::sole();
        $this->assertSame('0.0000', (string) $note->offset_amount);
        $this->assertSame('2260.0000', (string) $note->balance);
        $this->assertTrue((bool) $note->requires_ar_review);
        $this->assertDatabaseCount('accounts_receivable_adjustments', 0);

        $ar = AccountReceivable::firstWhere('sale_id', $sale->id);
        $this->assertSame('5650.0000', (string) $ar->balance_due);
        $this->assertSame(AccountReceivable::STATUS_PENDING, $ar->status);
    }

    public function test_credit_limit_freed_after_nc_offset(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $customer->update(['credit_limit' => '10000.0000', 'credit_days' => 30]);
        $product = $this->product($company);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 5, $customer);

        $ar = AccountReceivable::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sale_id' => $sale->id,
            'issued_at' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'original_amount' => '5650.0000',
            'balance_due' => '5650.0000',
            'status' => AccountReceivable::STATUS_PENDING,
            'currency_code' => 'CRC',
        ]);

        $usedBefore = (string) AccountReceivable::query()
            ->where('company_id', $company->id)
            ->where('customer_id', $customer->id)
            ->whereNotIn('status', [AccountReceivable::STATUS_PAID, AccountReceivable::STATUS_CANCELLED])
            ->sum('balance_due');

        $this->assertSame('5650', $usedBefore);

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Devolucion libera credito',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 2]],
        ])->assertSessionHasNoErrors();

        $ar->refresh();
        $this->assertSame('3390.0000', (string) $ar->balance_due);

        $usedAfter = (string) AccountReceivable::query()
            ->where('company_id', $company->id)
            ->where('customer_id', $customer->id)
            ->whereNotIn('status', [AccountReceivable::STATUS_PAID, AccountReceivable::STATUS_CANCELLED])
            ->sum('balance_due');

        $this->assertSame('3390', $usedAfter);

        $liberado = bcsub($usedBefore, $usedAfter, 4);
        $this->assertSame('2260.0000', $liberado);

        $note = CreditNote::sole();
        $this->assertSame('2260.0000', (string) $note->offset_amount);
    }

    public function test_reconcile_failure_rolls_back_entire_transaction(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $product = $this->product($company);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 5, $customer);

        AccountReceivable::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sale_id' => $sale->id,
            'issued_at' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'original_amount' => '5650.0000',
            'balance_due' => '5650.0000',
            'status' => AccountReceivable::STATUS_PENDING,
            'currency_code' => 'CRC',
        ]);

        $mock = Mockery::mock(AccountsReceivableReconciliationService::class);
        $mock->shouldReceive('reconcile')
            ->once()
            ->andThrow(new \RuntimeException('Fallo simulado de conciliacion'));
        $this->instance(AccountsReceivableReconciliationService::class, $mock);

        $saleReturn = SaleReturn::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'sale_id' => $sale->id,
            'user_id' => $user->id,
            'return_number' => 'DEV-ROLLBACK-'.uniqid(),
            'reason' => 'Prueba de rollback',
            'status' => SaleReturn::STATUS_COMPLETED,
            'returned_at' => now(),
        ]);

        SaleReturnItem::create([
            'sale_return_id' => $saleReturn->id,
            'sale_item_id' => $sale->items->first()->id,
            'product_id' => $product->id,
            'quantity' => '2.0000',
            'unit_price' => '1000.0000',
            'gross_total' => '2000.0000',
            'discount_total' => '0.0000',
            'subtotal' => '2000.0000',
            'tax_rate' => '13.0000',
            'tax_total' => '260.0000',
            'total' => '2260.0000',
        ]);

        try {
            $this->creditNotes()->issueFromReturn($saleReturn->fresh(), $user);
            $this->fail('Se esperaba excepcion por fallo de conciliacion.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Fallo simulado', $e->getMessage());
        }

        $this->app->forgetInstance(AccountsReceivableReconciliationService::class);

        $this->assertSame(0, CreditNote::count());
        $this->assertSame(0, AccountReceivableAdjustment::count());
        $this->assertSame(0, DB::table('credit_note_applications')->count());

        $ar = AccountReceivable::firstWhere('sale_id', $sale->id);
        $this->assertSame('5650.0000', (string) $ar->balance_due);
        $this->assertSame(AccountReceivable::STATUS_PENDING, $ar->status);
    }
}
