<?php

namespace Tests\Feature;

use App\Models\AccountReceivable;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\CreditNoteApplication;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyMovement;
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
use App\Services\Loyalty\LoyaltyAccountService;
use App\Services\Sales\CreditNoteService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CreditNoteTest extends TestCase
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

    private function product(Company $company, bool $trackInventory = true): Product
    {
        $category = ProductCategory::create([
            'company_id' => $company->id,
            'name' => 'Categoría '.uniqid(),
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

    private function seedStock(Branch $branch, Product $product, int $qty): void
    {
        DB::table('branch_product')->insert([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'stock' => $qty,
            'created_at' => now(),
            'updated_at' => now(),
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

    /**
     * Venta completada con cliente opcional. Línea única: qty × (1000 + 130 IVA).
     */
    private function completedSale(
        Company $company,
        Branch $branch,
        User $user,
        int $productId,
        int $qty,
        ?Customer $customer = null,
        bool $withPayment = true,
    ): Sale {
        $sale = Sale::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'customer_id' => $customer?->id,
            'checkout_token' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', uniqid('ncsale', true)),
            'sale_number' => 'POS-NC-'.uniqid(),
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
            'paid_total' => $withPayment ? 1130 * $qty : 0,
            'balance_due' => $withPayment ? 0 : 1130 * $qty,
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

        if ($withPayment) {
            $sale->payments()->create([
                'payment_method_id' => $this->paymentMethodId($company),
                'affects_cash_snapshot' => true,
                'created_by' => $user->id,
                'amount' => 1130 * $qty,
                'received_amount' => 1130 * $qty,
                'change_amount' => 0,
                'cash_effect_amount' => 1130 * $qty,
                'reference' => null,
                'status' => SalePayment::STATUS_COMPLETED,
            ]);
        }

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

    public function test_partial_return_of_customer_sale_issues_partial_credit_note(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $product = $this->product($company);
        $this->seedStock($branch, $product, 10);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 5, $customer);

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Devolución parcial con NC',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 2]],
        ])->assertSessionHasNoErrors();

        $note = CreditNote::sole();
        $this->assertSame($customer->id, (int) $note->customer_id);
        $this->assertSame($sale->id, (int) $note->sale_id);
        $this->assertSame(SaleReturn::sole()->id, (int) $note->sale_return_id);
        $this->assertSame('2260.0000', (string) $note->issued_amount);
        $this->assertSame('0.0000', (string) $note->applied_amount);
        $this->assertSame('2260.0000', (string) $note->balance);
        $this->assertSame(CreditNote::STATUS_ISSUED, $note->status);
        $this->assertSame('CRC', $note->currency_code);
        $this->assertFalse((bool) $note->requires_ar_review);
        $this->assertSame(Sale::STATUS_PARTIALLY_RETURNED, $sale->fresh()->status);
    }

    public function test_credit_note_numbering_format_and_sequence_per_company(): void
    {
        [$companyA, $branchA, $userA, $customerA] = $this->scenario();
        $productA = $this->product($companyA);
        $this->seedStock($branchA, $productA, 10);
        $saleA = $this->completedSale($companyA, $branchA, $userA, $productA->id, 5, $customerA);

        $this->postReturn($userA, $companyA, $branchA, $saleA, [
            'reason' => 'Primera devolución',
            'items' => [['sale_item_id' => $saleA->items->first()->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        $this->postReturn($userA, $companyA, $branchA, $saleA->fresh(), [
            'reason' => 'Segunda devolución',
            'items' => [['sale_item_id' => $saleA->items->first()->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        $numbers = CreditNote::query()->orderBy('id')->pluck('credit_note_number')->all();
        $this->assertSame(['NC-00000001', 'NC-00000002'], $numbers);

        [$companyB, $branchB, $userB, $customerB] = $this->scenario();
        $productB = $this->product($companyB);
        $this->seedStock($branchB, $productB, 10);
        $saleB = $this->completedSale($companyB, $branchB, $userB, $productB->id, 2, $customerB);

        $this->postReturn($userB, $companyB, $branchB, $saleB, [
            'reason' => 'Devolución empresa B',
            'items' => [['sale_item_id' => $saleB->items->first()->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        $noteB = CreditNote::query()->where('company_id', $companyB->id)->sole();
        $this->assertSame('NC-00000001', $noteB->credit_note_number);
    }

    public function test_credit_notes_are_isolated_per_company_and_customer(): void
    {
        [$companyA, $branchA, $userA, $customerA] = $this->scenario();
        $productA = $this->product($companyA);
        $this->seedStock($branchA, $productA, 10);
        $saleA = $this->completedSale($companyA, $branchA, $userA, $productA->id, 2, $customerA);

        $this->postReturn($userA, $companyA, $branchA, $saleA, [
            'reason' => 'NC empresa A',
            'items' => [['sale_item_id' => $saleA->items->first()->id, 'quantity' => 1]],
        ]);

        $availableA = $this->creditNotes()->availableForCustomer((int) $companyA->id, (int) $customerA->id);
        $this->assertCount(1, $availableA);

        $companyB = $this->company('B');
        $customerB = Customer::create([
            'company_id' => $companyB->id,
            'name' => 'Cliente B '.uniqid(),
            'identification' => 'NCIDB-'.uniqid(),
            'is_active' => true,
        ]);

        $this->assertCount(0, $this->creditNotes()->availableForCustomer((int) $companyB->id, (int) $customerB->id));
        $this->assertCount(0, $this->creditNotes()->availableForCustomer((int) $companyA->id, (int) $customerB->id));
    }

    public function test_multiple_returns_of_one_sale_produce_multiple_credit_notes(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $product = $this->product($company);
        $this->seedStock($branch, $product, 10);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 5, $customer);
        $itemId = $sale->items->first()->id;

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Devolución 1',
            'items' => [['sale_item_id' => $itemId, 'quantity' => 2]],
        ])->assertSessionHasNoErrors();

        $this->postReturn($user, $company, $branch, $sale->fresh(), [
            'reason' => 'Devolución 2',
            'items' => [['sale_item_id' => $itemId, 'quantity' => 3]],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('sale_returns', 2);
        $notes = CreditNote::query()->orderBy('id')->get();
        $this->assertCount(2, $notes);
        $this->assertNotSame($notes[0]->sale_return_id, $notes[1]->sale_return_id);
        $this->assertSame('2260.0000', (string) $notes[0]->balance);
        $this->assertSame('3390.0000', (string) $notes[1]->balance);
        $this->assertSame(Sale::STATUS_RETURNED, $sale->fresh()->status);
    }

    public function test_one_return_cannot_produce_two_credit_notes(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $product = $this->product($company);
        $this->seedStock($branch, $product, 10);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 3, $customer);

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Única devolución',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 1]],
        ]);

        $return = SaleReturn::sole();
        $first = $this->creditNotes()->issueFromReturn($return, $user);
        $second = $this->creditNotes()->issueFromReturn($return, $user);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('credit_notes', 1);

        $this->expectException(QueryException::class);
        CreditNote::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sale_id' => $sale->id,
            'sale_return_id' => $return->id,
            'credit_note_number' => 'NC-99999999',
            'currency_code' => 'CRC',
            'issued_amount' => '1130.0000',
            'applied_amount' => '0.0000',
            'balance' => '1130.0000',
            'status' => CreditNote::STATUS_ISSUED,
            'reason' => 'Inserción duplicada',
            'issued_by' => $user->id,
            'issued_at' => now(),
        ]);
    }

    public function test_credit_note_amounts_keep_four_decimal_precision(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $product = $this->product($company);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 2, $customer);
        $itemId = $sale->items->first()->id;

        $return = SaleReturn::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'sale_id' => $sale->id,
            'user_id' => $user->id,
            'return_number' => 'DEV-PREC-'.uniqid(),
            'reason' => 'Devolución de precisión',
            'status' => SaleReturn::STATUS_COMPLETED,
            'returned_at' => now(),
        ]);

        SaleReturnItem::create([
            'sale_return_id' => $return->id,
            'sale_item_id' => $itemId,
            'product_id' => $product->id,
            'quantity' => '0.0001',
            'unit_price' => '1000.0000',
            'gross_total' => '100.0013',
            'discount_total' => '0.0000',
            'subtotal' => '100.0013',
            'tax_rate' => '0.0000',
            'tax_total' => '0.0000',
            'total' => '100.0013',
        ]);

        SaleReturnItem::create([
            'sale_return_id' => $return->id,
            'sale_item_id' => $itemId,
            'product_id' => $product->id,
            'quantity' => '0.0001',
            'unit_price' => '1000.0000',
            'gross_total' => '49.9987',
            'discount_total' => '0.0000',
            'subtotal' => '49.9987',
            'tax_rate' => '0.0000',
            'tax_total' => '0.0000',
            'total' => '49.9987',
        ]);

        $note = $this->creditNotes()->issueFromReturn($return, $user);

        $this->assertSame('150.0000', (string) $note->issued_amount);
        $this->assertSame('150.0000', (string) $note->balance);

        $targetSale = $this->completedSale($company, $branch, $user, $product->id, 1, $customer);

        $this->creditNotes()->applyToSale($note, $targetSale, '50.0001', $user, 'TOKEN-PREC-'.uniqid());

        $note->refresh();
        $this->assertSame('50.0001', (string) $note->applied_amount);
        $this->assertSame('99.9999', (string) $note->balance);
        $this->assertSame(CreditNote::STATUS_PARTIALLY_APPLIED, $note->status);
    }

    public function test_apply_credit_note_partially_and_then_completely(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $product = $this->product($company);
        $this->seedStock($branch, $product, 10);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 5, $customer);

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Devolución para aplicar',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 5]],
        ]);

        $note = CreditNote::sole();
        $this->assertSame('5650.0000', (string) $note->balance);

        $target = $this->completedSale($company, $branch, $user, $product->id, 10, $customer);

        $first = $this->creditNotes()->applyToSale($note, $target, '3000.0000', $user, 'APL-1-'.uniqid());
        $note->refresh();

        $this->assertSame('3000.0000', (string) $first->amount);
        $this->assertSame('3000.0000', (string) $note->applied_amount);
        $this->assertSame('2650.0000', (string) $note->balance);
        $this->assertSame(CreditNote::STATUS_PARTIALLY_APPLIED, $note->status);
        $this->assertSame($user->id, (int) $first->applied_by);
        $this->assertNotNull($first->applied_at);
        $this->assertSame(Sale::STATUS_COMPLETED, $target->fresh()->status);
        $this->assertSame('11300.0000', (string) $target->fresh()->total);

        $second = $this->creditNotes()->applyToSale($note, $target, '2650.0000', $user, 'APL-2-'.uniqid());
        $note->refresh();

        $this->assertSame('2650.0000', (string) $second->amount);
        $this->assertSame('5650.0000', (string) $note->applied_amount);
        $this->assertSame('0.0000', (string) $note->balance);
        $this->assertSame(CreditNote::STATUS_APPLIED, $note->status);
        $this->assertSame($user->id, (int) $second->applied_by);

        $this->assertCount(0, $this->creditNotes()->availableForCustomer((int) $company->id, (int) $customer->id));
        $this->assertCount(2, $target->fresh()->creditNoteApplicationsAsDestination);
    }

    public function test_apply_more_than_available_balance_is_rejected(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $product = $this->product($company);
        $this->seedStock($branch, $product, 10);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 2, $customer);

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Devolución con saldo',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 1]],
        ]);

        $note = CreditNote::sole();
        $target = $this->completedSale($company, $branch, $user, $product->id, 5, $customer);

        try {
            $this->creditNotes()->applyToSale($note, $target, '1130.0001', $user, 'APL-OVER-'.uniqid());
            $this->fail('Se esperaba saldo insuficiente.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }

        $note->refresh();
        $this->assertSame('1130.0000', (string) $note->balance);
        $this->assertSame(CreditNote::STATUS_ISSUED, $note->status);
        $this->assertDatabaseCount('credit_note_applications', 0);

        $this->expectException(ValidationException::class);
        $this->creditNotes()->applyToSale($note, $target, '0.0000', $user, 'APL-ZERO-'.uniqid());
    }

    public function test_credit_note_cannot_be_applied_to_sale_of_another_company(): void
    {
        [$companyA, $branchA, $userA, $customerA] = $this->scenario();
        $productA = $this->product($companyA);
        $this->seedStock($branchA, $productA, 10);
        $saleA = $this->completedSale($companyA, $branchA, $userA, $productA->id, 2, $customerA);

        $this->postReturn($userA, $companyA, $branchA, $saleA, [
            'reason' => 'NC de la empresa A',
            'items' => [['sale_item_id' => $saleA->items->first()->id, 'quantity' => 1]],
        ]);

        $note = CreditNote::sole();

        [$companyB, $branchB, $userB, $customerB] = $this->scenario();
        $productB = $this->product($companyB);
        $saleB = $this->completedSale($companyB, $branchB, $userB, $productB->id, 2, $customerB);

        try {
            $this->creditNotes()->applyToSale($note, $saleB, '500.0000', $userB, 'APL-XCOMPANY-'.uniqid());
            $this->fail('Se esperaba rechazo entre empresas.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('sale', $e->errors());
        }

        $note->refresh();
        $this->assertSame('1130.0000', (string) $note->balance);
        $this->assertDatabaseCount('credit_note_applications', 0);
    }

    public function test_credit_note_cannot_be_applied_to_another_customer_sale(): void
    {
        [$company, $branch, $user, $customerA] = $this->scenario();
        $product = $this->product($company);
        $this->seedStock($branch, $product, 10);
        $saleA = $this->completedSale($company, $branch, $user, $product->id, 2, $customerA);

        $this->postReturn($user, $company, $branch, $saleA, [
            'reason' => 'NC del cliente A',
            'items' => [['sale_item_id' => $saleA->items->first()->id, 'quantity' => 1]],
        ]);

        $note = CreditNote::sole();

        $customerB = $this->customer($company, 'Cliente B');
        $saleB = $this->completedSale($company, $branch, $user, $product->id, 2, $customerB);

        try {
            $this->creditNotes()->applyToSale($note, $saleB, '500.0000', $user, 'APL-XCLIENTE-'.uniqid());
            $this->fail('Se esperaba rechazo por cliente distinto.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('customer', $e->errors());
        }

        $this->assertDatabaseCount('credit_note_applications', 0);

        $consumerSale = $this->completedSale($company, $branch, $user, $product->id, 2, null);

        $this->expectException(ValidationException::class);
        $this->creditNotes()->applyToSale($note, $consumerSale, '500.0000', $user, 'APL-ANON-'.uniqid());
    }

    public function test_application_token_is_idempotent(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $product = $this->product($company);
        $this->seedStock($branch, $product, 10);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 5, $customer);

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'NC para idempotencia',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 2]],
        ]);

        $note = CreditNote::sole();
        $target = $this->completedSale($company, $branch, $user, $product->id, 10, $customer);
        $token = 'APL-IDEMPOTENTE';

        $first = $this->creditNotes()->applyToSale($note, $target, '1000.0000', $user, $token);
        $second = $this->creditNotes()->applyToSale($note->fresh(), $target, '1000.0000', $user, $token);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('credit_note_applications', 1);

        $note->refresh();
        $this->assertSame('1000.0000', (string) $note->applied_amount);
        $this->assertSame('1260.0000', (string) $note->balance);
        $this->assertSame(CreditNote::STATUS_PARTIALLY_APPLIED, $note->status);
    }

    public function test_consumer_final_return_works_without_credit_note(): void
    {
        [$company, $branch, $user] = $this->scenario();
        $product = $this->product($company);
        $this->seedStock($branch, $product, 10);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 4, null);

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Devolución de consumidor final',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 2]],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('sale_returns', 1);
        $this->assertDatabaseCount('credit_notes', 0);
        $this->assertSame(Sale::STATUS_PARTIALLY_RETURNED, $sale->fresh()->status);
        $this->assertSame(12, (int) (float) DB::table('branch_product')->where('product_id', $product->id)->value('stock'));

        $this->expectException(ValidationException::class);
        $this->creditNotes()->issueFromReturn(SaleReturn::sole(), $user);
    }

    public function test_return_on_credit_sale_flags_ar_review_and_keeps_ar_intact(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $product = $this->product($company);
        $this->seedStock($branch, $product, 10);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 5, $customer, withPayment: false);

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

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Devolución con CxC abierta',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        $note = CreditNote::sole();
        $this->assertTrue((bool) $note->requires_ar_review);
        $this->assertSame('1130.0000', (string) $note->balance);

        $account->refresh();
        $this->assertSame('5650.0000', (string) $account->balance_due);
        $this->assertSame(AccountReceivable::STATUS_PENDING, $account->status);
        $this->assertDatabaseCount('accounts_receivable_payments', 0);

        $target = $this->completedSale($company, $branch, $user, $product->id, 5, $customer);

        try {
            $this->creditNotes()->applyToSale($note->fresh(), $target, '500.0000', $user, 'APL-ARBLOCK-'.uniqid());
            $this->fail('Se esperaba bloqueo de aplicación por revisión CxC pendiente.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('credit_note', $e->errors());
            $this->assertStringContainsString('conciliación CxC', $e->errors()['credit_note'][0]);
        }

        $note->refresh();
        $this->assertSame(CreditNote::STATUS_ISSUED, $note->status);
        $this->assertSame('1130.0000', (string) $note->balance);
        $this->assertDatabaseCount('credit_note_applications', 0);
        $account->refresh();
        $this->assertSame('5650.0000', (string) $account->balance_due);
    }

    public function test_void_unused_credit_note_zeroes_balance_and_preserves_issued_amount(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $product = $this->product($company);
        $this->seedStock($branch, $product, 10);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 5, $customer);

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'NC para anular',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 3]],
        ]);

        $note = CreditNote::sole();
        $voided = $this->creditNotes()->void($note, $user, 'NC emitida por error');

        $this->assertSame(CreditNote::STATUS_VOIDED, $voided->status);
        $this->assertSame($user->id, (int) $voided->voided_by);
        $this->assertNotNull($voided->voided_at);
        $this->assertSame('NC emitida por error', $voided->void_reason);
        $this->assertSame('3390.0000', (string) $voided->issued_amount);
        $this->assertSame('0.0000', (string) $voided->balance);
        $this->assertSame('0.0000', (string) $voided->applied_amount);

        $this->assertCount(0, $this->creditNotes()->availableForCustomer((int) $company->id, (int) $customer->id));

        $this->expectException(ValidationException::class);
        $this->creditNotes()->void($voided, $user, 'Segunda anulación');
    }

    public function test_credit_note_with_applications_cannot_be_voided_directly(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $product = $this->product($company);
        $this->seedStock($branch, $product, 10);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 5, $customer);

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'NC parcialmente aplicada',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 3]],
        ]);

        $note = CreditNote::sole();
        $target = $this->completedSale($company, $branch, $user, $product->id, 10, $customer);
        $this->creditNotes()->applyToSale($note, $target, '500.0000', $user, 'APL-BLOCKVOID-'.uniqid());
        $note->refresh();

        $this->assertSame(CreditNote::STATUS_PARTIALLY_APPLIED, $note->status);

        try {
            $this->creditNotes()->void($note, $user, 'Intento con aplicaciones activas');
            $this->fail('Se esperaba exigir la reversión previa de las aplicaciones.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('credit_note', $e->errors());
            $this->assertStringContainsString('revertirse', $e->errors()['credit_note'][0]);
        }

        $note->refresh();
        $this->assertSame(CreditNote::STATUS_PARTIALLY_APPLIED, $note->status);
        $this->assertSame('2890.0000', (string) $note->balance);
        $this->assertNull($note->voided_at);
        $this->assertDatabaseCount('credit_note_applications', 1);
    }

    public function test_fully_applied_credit_note_cannot_be_voided(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $product = $this->product($company);
        $this->seedStock($branch, $product, 10);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 2, $customer);

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'NC totalmente aplicada',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 1]],
        ]);

        $note = CreditNote::sole();
        $target = $this->completedSale($company, $branch, $user, $product->id, 5, $customer);
        $this->creditNotes()->applyToSale($note, $target, '1130.0000', $user, 'APL-FULL-'.uniqid());
        $note->refresh();

        $this->assertSame(CreditNote::STATUS_APPLIED, $note->status);

        try {
            $this->creditNotes()->void($note, $user, 'Intento de anular aplicada');
            $this->fail('Se esperaba prohibición de anulación directa.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('credit_note', $e->errors());
        }

        $note->refresh();
        $this->assertSame(CreditNote::STATUS_APPLIED, $note->status);
        $this->assertNull($note->voided_at);
    }

    public function test_credit_note_does_not_duplicate_point_adjustments(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $product = $this->product($company);
        $this->seedStock($branch, $product, 10);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 5, $customer);

        $loyalty = app(LoyaltyAccountService::class);
        $account = $loyalty->getOrCreateAccount($customer, $company, $user);
        $loyalty->addPoints($account, '100', LoyaltyMovement::TYPE_PURCHASE, [
            'branch' => $branch->id,
            'user' => $user->id,
            'source_type' => Sale::class,
            'source_id' => $sale->id,
            'base_amount' => '5000.0000',
            'event_key' => "test:nc-purchase:{$sale->id}",
            'description' => 'Compra de prueba',
        ]);

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Devolución con NC y puntos',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 2]],
        ])->assertSessionHasNoErrors();

        $reversals = LoyaltyMovement::query()
            ->where('type', LoyaltyMovement::TYPE_RETURN)
            ->where('source_type', SaleReturn::class)
            ->get();
        $this->assertCount(1, $reversals);
        $this->assertSame('-40.0000', (string) $reversals[0]->points);
        $this->assertSame('60.0000', (string) LoyaltyAccount::sole()->balance);

        $beforeMovements = LoyaltyMovement::query()->count();
        $beforeBalance = (string) LoyaltyAccount::sole()->balance;

        $note = CreditNote::sole();
        $target = $this->completedSale($company, $branch, $user, $product->id, 5, $customer);
        $this->creditNotes()->issueFromReturn(SaleReturn::sole(), $user);
        $this->creditNotes()->applyToSale($note, $target, '1000.0000', $user, 'APL-LOTTA-'.uniqid());
        $this->creditNotes()->applyToSale($note->fresh(), $target, '300.0000', $user, 'APL-LOTTA2-'.uniqid());

        $this->assertSame($beforeMovements, LoyaltyMovement::query()->count());
        $this->assertSame($beforeBalance, (string) LoyaltyAccount::sole()->balance);
        $this->assertSame(0, LoyaltyMovement::query()->whereIn('source_type', [
            CreditNote::class,
            CreditNoteApplication::class,
        ])->count());
    }

    public function test_credit_note_does_not_create_additional_inventory_movement(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $product = $this->product($company);
        $this->seedStock($branch, $product, 10);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 4, $customer);

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Devolución con inventario',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 2]],
        ])->assertSessionHasNoErrors();

        $return = SaleReturn::sole();
        $this->assertSame(1, InventoryMovement::query()->where('reference_type', SaleReturn::class)->where('reference_id', $return->id)->count());
        $this->assertSame(12, (int) (float) DB::table('branch_product')->where('product_id', $product->id)->value('stock'));

        $before = InventoryMovement::query()->count();

        $note = CreditNote::sole();
        $target = $this->completedSale($company, $branch, $user, $product->id, 5, $customer);
        $this->creditNotes()->issueFromReturn($return, $user);
        $this->creditNotes()->applyToSale($note, $target, '1130.0000', $user, 'APL-INV-'.uniqid());

        $this->assertSame($before, InventoryMovement::query()->count());
        $this->assertSame(12, (int) (float) DB::table('branch_product')->where('product_id', $product->id)->value('stock'));
        $this->assertSame(0, InventoryMovement::query()->whereIn('reference_type', [
            CreditNote::class,
            CreditNoteApplication::class,
        ])->count());
    }

    public function test_issue_from_return_is_idempotent_by_key(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $product = $this->product($company);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 3, $customer);

        $return = SaleReturn::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'sale_id' => $sale->id,
            'user_id' => $user->id,
            'return_number' => 'DEV-IDEM-'.uniqid(),
            'reason' => 'Devolución para reemisión',
            'status' => SaleReturn::STATUS_COMPLETED,
            'returned_at' => now(),
        ]);

        SaleReturnItem::create([
            'sale_return_id' => $return->id,
            'sale_item_id' => $sale->items->first()->id,
            'product_id' => $product->id,
            'quantity' => '1.0000',
            'unit_price' => '1000.0000',
            'gross_total' => '1000.0000',
            'discount_total' => '0.0000',
            'subtotal' => '1000.0000',
            'tax_rate' => '13.0000',
            'tax_total' => '130.0000',
            'total' => '1130.0000',
        ]);

        $first = $this->creditNotes()->issueFromReturn($return, $user, 'EMISION-UNICA');
        $second = $this->creditNotes()->issueFromReturn($return->fresh(), $user, 'EMISION-UNICA');

        $this->assertSame($first->id, $second->id);
        $this->assertSame('EMISION-UNICA', $first->idempotency_key);
        $this->assertSame('1130.0000', (string) $first->balance);
        $this->assertDatabaseCount('credit_notes', 1);
    }

    /**
     * @return array{0: Company, 1: Branch, 2: User, 3: Customer}
     */
    private function scenario(): array
    {
        $company = $this->company();
        $branch = $this->branch($company, 'Principal');
        $user = $this->userWithPermission($company, $branch, ['devoluciones.crear']);
        $customer = $this->customer($company);

        return [$company, $branch, $user, $customer];
    }
}
