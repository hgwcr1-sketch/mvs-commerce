<?php

namespace Tests\Feature;

use App\Http\Requests\UpdateCreditNoteExpirationRequest;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\CreditNoteApplication;
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
use App\Services\Sales\CreditNoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CreditNoteExpirationTest extends TestCase
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
            'name' => 'Rol NC EXP '.uniqid(),
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
            'identification' => 'NCID-EXP-'.uniqid(),
            'is_active' => true,
        ]);
    }

    private function product(Company $company): Product
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
            'internal_code' => 'P-EXP-'.uniqid(),
            'cost' => 500,
            'sale_price' => 1000,
            'tax_rate' => 13,
            'track_inventory' => true,
            'is_active' => true,
        ]);
    }

    private function paymentMethodId(Company $company): int
    {
        return PaymentMethod::firstOrCreate(
            ['company_id' => $company->id, 'code' => 'EFECTIVO-EXP-'.$company->id],
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
            'request_fingerprint' => hash('sha256', uniqid('ncexp', true)),
            'sale_number' => 'POS-EXP-'.uniqid(),
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
            'paid_total' => 1130 * $qty,
            'balance_due' => 0,
            'completed_at' => now(),
        ]);

        $sale->items()->create([
            'product_id' => $productId,
            'product_code' => 'P-CODE',
            'barcode' => null,
            'cabys_code' => null,
            'description' => 'Producto NC EXP',
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

        return $sale;
    }

    /**
     * Construye una devolución completada sobre la venta con el total definido.
     */
    private function returnFor(Sale $sale, User $user, string $total, string $qty = '1.0000'): SaleReturn
    {
        $return = SaleReturn::create([
            'company_id' => $sale->company_id,
            'branch_id' => $sale->branch_id,
            'sale_id' => $sale->id,
            'user_id' => $user->id,
            'return_number' => 'DEV-EXP-'.uniqid(),
            'reason' => 'Devolución expiración',
            'status' => SaleReturn::STATUS_COMPLETED,
            'returned_at' => now(),
        ]);

        SaleReturnItem::create([
            'sale_return_id' => $return->id,
            'sale_item_id' => $sale->items->first()->id,
            'product_id' => $sale->items->first()->product_id,
            'quantity' => $qty,
            'unit_price' => '1000.0000',
            'gross_total' => $total,
            'discount_total' => '0.0000',
            'subtotal' => $total,
            'tax_rate' => '0.0000',
            'tax_total' => '0.0000',
            'total' => $total,
        ]);

        return $return;
    }

    private function paintAsExpired(CreditNote $note): CreditNote
    {
        $note->forceFill(['expires_at' => now()->subDay()])->save();

        return $note->fresh();
    }

    private function notes(): CreditNoteService
    {
        return app(CreditNoteService::class);
    }

    public function test_default_company_policy_is_none(): void
    {
        $company = $this->company();

        $this->assertSame('none', $company->fresh()->credit_note_expiration_policy);
        $this->assertNull($company->fresh()->ncExpirationDays());
    }

    public function test_existing_credit_note_with_null_expires_at_stays_available(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $product = $this->product($company);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 2, $customer);
        $return = $this->returnFor($sale, $user, '1130.0000');

        $note = $this->notes()->issueFromReturn($return, $user);

        $this->assertNull($note->expires_at);
        $this->assertFalse($note->isExpired());
        $this->assertTrue($this->notes()->availableForCustomer((int) $company->id, (int) $customer->id)->contains('id', $note->id));
    }

    public function test_policy_none_sets_expires_at_null_when_issuing(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $company->update(['credit_note_expiration_policy' => 'none', 'credit_note_custom_expiration_days' => null]);

        $product = $this->product($company);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 2, $customer);
        $return = $this->returnFor($sale, $user, '1130.0000');

        $note = $this->notes()->issueFromReturn($return, $user);

        $this->assertNull($note->expires_at);
        $this->assertFalse($note->isExpired());
    }

    /**
     * @dataProvider expirationPolicies
     */
    #[DataProvider('expirationPolicies')]
    public function test_policy_sets_expires_at_accordingly(string $policy, int $expectedDays): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $company->update(['credit_note_expiration_policy' => $policy, 'credit_note_custom_expiration_days' => null]);

        $product = $this->product($company);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 2, $customer);
        $return = $this->returnFor($sale, $user, '1130.0000');

        $note = $this->notes()->issueFromReturn($return, $user);
        $note->refresh();

        $this->assertNotNull($note->expires_at);
        $this->assertTrue($note->expires_at->greaterThan(now()));
        $this->assertEquals($note->issued_at->copy()->addDays($expectedDays), $note->expires_at);
        $this->assertFalse($note->isExpired());
    }

    public static function expirationPolicies(): array
    {
        return [
            '30 días' => ['30', 30],
            '60 días' => ['60', 60],
            '90 días' => ['90', 90],
        ];
    }

    public function test_custom_policy_sets_expires_at_based_on_custom_days(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $company->update(['credit_note_expiration_policy' => 'custom', 'credit_note_custom_expiration_days' => 45]);

        $product = $this->product($company);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 2, $customer);
        $return = $this->returnFor($sale, $user, '1130.0000');

        $note = $this->notes()->issueFromReturn($return, $user);
        $note->refresh();

        $this->assertSame(45, $company->ncExpirationDays());
        $this->assertEquals($note->issued_at->copy()->addDays(45), $note->expires_at);
    }

    public function test_custom_policy_without_days_is_rejected_by_validation(): void
    {
        [$company, $branch, $user] = $this->scenarioUserOnly();

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id])
            ->put(route('configuracion.notas-credito.update'), [
                'credit_note_expiration_policy' => 'custom',
            ])
            ->assertSessionHasErrors('credit_note_custom_expiration_days');

        $this->assertSame('none', $company->fresh()->credit_note_expiration_policy);
        $this->assertNull($company->fresh()->credit_note_custom_expiration_days);
    }

    public function test_custom_policy_with_invalid_day_range_is_rejected(): void
    {
        [$company, $branch, $user] = $this->scenarioUserOnly();

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id])
            ->put(route('configuracion.notas-credito.update'), [
                'credit_note_expiration_policy' => 'custom',
                'credit_note_custom_expiration_days' => 0,
            ])
            ->assertSessionHasErrors('credit_note_custom_expiration_days');
    }

    public function test_valid_custom_policy_is_saved_and_non_custom_clears_days(): void
    {
        [$company, $branch, $user] = $this->scenarioUserOnly();

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id])
            ->put(route('configuracion.notas-credito.update'), [
                'credit_note_expiration_policy' => 'custom',
                'credit_note_custom_expiration_days' => 45,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('custom', $company->fresh()->credit_note_expiration_policy);
        $this->assertSame(45, (int) $company->fresh()->credit_note_custom_expiration_days);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id])
            ->put(route('configuracion.notas-credito.update'), [
                'credit_note_expiration_policy' => '30',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('30', $company->fresh()->credit_note_expiration_policy);
        $this->assertNull($company->fresh()->credit_note_custom_expiration_days);
    }

    public function test_later_config_change_does_not_alter_existing_credit_note(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $company->update(['credit_note_expiration_policy' => '30', 'credit_note_custom_expiration_days' => null]);

        $product = $this->product($company);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 2, $customer);
        $return = $this->returnFor($sale, $user, '1130.0000');

        $note = $this->notes()->issueFromReturn($return, $user);
        $this->assertNotNull($note->expires_at);
        $originalExpiresAt = $note->fresh()->expires_at->format('Y-m-d H:i:s');

        $company->update(['credit_note_expiration_policy' => 'none', 'credit_note_custom_expiration_days' => null]);

        $this->assertSame($originalExpiresAt, $note->fresh()->expires_at->format('Y-m-d H:i:s'));
        $this->assertNotSame('none', $note->fresh()->expires_at === null ? 'none' : $note->fresh()->expires_at->format('Y-m-d H:i:s'));
    }

    public function test_valid_credit_note_is_available(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $product = $this->product($company);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 2, $customer);
        $return = $this->returnFor($sale, $user, '1130.0000');

        $note = $this->notes()->issueFromReturn($return, $user);

        $available = $this->notes()->availableForCustomer((int) $company->id, (int) $customer->id);
        $this->assertTrue($available->contains('id', $note->id));
        $this->assertCount(1, $available);
    }

    public function test_expired_credit_note_is_not_available(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $company->update(['credit_note_expiration_policy' => '30', 'credit_note_custom_expiration_days' => null]);

        $product = $this->product($company);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 2, $customer);
        $return = $this->returnFor($sale, $user, '1130.0000');

        $note = $this->paintAsExpired($this->notes()->issueFromReturn($return, $user));

        $this->assertTrue($note->isExpired());
        $this->assertCount(0, $this->notes()->availableForCustomer((int) $company->id, (int) $customer->id));
    }

    public function test_backend_rejects_expired_credit_note_on_apply(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $product = $this->product($company);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 2, $customer);
        $return = $this->returnFor($sale, $user, '1130.0000');

        $note = $this->paintAsExpired($this->notes()->issueFromReturn($return, $user));
        $target = $this->completedSale($company, $branch, $user, $product->id, 2, $customer);

        try {
            $this->notes()->applyToSale($note, $target, '500.0000', $user, 'APL-EXP-'.uniqid());
            $this->fail('Se esperaba rechazo por vencimiento.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('credit_note', $e->errors());
        }

        $note->refresh();
        $this->assertSame('0.0000', (string) $note->applied_amount);
        $this->assertSame('1130.0000', (string) $note->balance);
        $this->assertDatabaseCount('credit_note_applications', 0);
    }

    public function test_consulted_before_expiration_but_checkout_after_is_rejected(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $product = $this->product($company);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 2, $customer);
        $return = $this->returnFor($sale, $user, '1130.0000');

        $note = $this->notes()->issueFromReturn($return, $user);

        // Consulta: la NC aparece disponible.
        $available = $this->notes()->availableForCustomer((int) $company->id, (int) $customer->id);
        $this->assertTrue($available->contains('id', $note->id));

        // La NC vence antes del checkout.
        $this->paintAsExpired($note);

        // El checkout batch (POS) debe rechazar la aplicación.
        $target = $this->completedSale($company, $branch, $user, $product->id, 2, $customer);

        try {
            $this->notes()->applyBatchToSale(
                $target,
                [['credit_note_id' => $note->id, 'amount' => '500.0000']],
                $user,
                'CHECKOUT-EXP-'.uniqid(),
            );
            $this->fail('Se esperaba rechazo por vencimiento en el checkout.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('credit_note_applications', $e->errors());
        }

        $this->assertDatabaseCount('credit_note_applications', 0);
    }

    public function test_partially_applied_expired_keeps_status_but_is_not_available(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $product = $this->product($company);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 2, $customer);
        $return = $this->returnFor($sale, $user, '1130.0000');

        $note = $this->notes()->issueFromReturn($return, $user);
        $target = $this->completedSale($company, $branch, $user, $product->id, 2, $customer);

        $this->notes()->applyToSale($note, $target, '500.0000', $user, 'APL-PART-'.uniqid());
        $note->refresh();

        $this->assertSame(CreditNote::STATUS_PARTIALLY_APPLIED, $note->status);

        $this->paintAsExpired($note);

        $this->assertSame(CreditNote::STATUS_PARTIALLY_APPLIED, $note->fresh()->status);
        $this->assertTrue($note->fresh()->isExpired());
        $this->assertCount(0, $this->notes()->availableForCustomer((int) $company->id, (int) $customer->id));

        $target2 = $this->completedSale($company, $branch, $user, $product->id, 2, $customer);
        try {
            $this->notes()->applyToSale($note->fresh(), $target2, '630.0000', $user, 'APL-PART2-'.uniqid());
            $this->fail('Se esperaba rechazo por vencimiento.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('credit_note', $e->errors());
        }
    }

    public function test_reversal_after_expiration_restores_balance_but_not_availability(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $product = $this->product($company);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 10, $customer);
        $return = $this->returnFor($sale, $user, '10000.0000', '10.0000');

        $note = $this->notes()->issueFromReturn($return, $user);
        $target = $this->completedSale($company, $branch, $user, $product->id, 10, $customer);

        $application = $this->notes()->applyToSale($note, $target, '10000.0000', $user, 'APL-FULL-'.uniqid());
        $note->refresh();

        $this->assertSame(CreditNote::STATUS_APPLIED, $note->status);
        $this->assertSame('0.0000', (string) $note->balance);

        // La NC se aplicó por completo y luego venció.
        $this->paintAsExpired($note);

        // La venta destino se anula: la aplicación se revierte tras el vencimiento.
        $reversed = $this->notes()->reverseApplication($application, $user, 'Anulación de venta tras vencimiento');
        $note->refresh();

        $this->assertSame(CreditNoteApplication::STATUS_VOIDED, $reversed->status);
        $this->assertSame('10000.0000', (string) $note->balance);
        $this->assertSame('0.0000', (string) $note->applied_amount);
        $this->assertSame(CreditNote::STATUS_ISSUED, $note->status);
        $this->assertTrue($note->isExpired());
        $this->assertCount(0, $this->notes()->availableForCustomer((int) $company->id, (int) $customer->id));
    }

    public function test_nominative_credit_note_still_works_without_policy(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario();
        $product = $this->product($company);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 2, $customer);
        $return = $this->returnFor($sale, $user, '1130.0000');

        $note = $this->notes()->issueFromReturn($return, $user);
        $target = $this->completedSale($company, $branch, $user, $product->id, 2, $customer);

        $this->notes()->applyToSale($note, $target, '1130.0000', $user, 'APL-NOM-'.uniqid());

        $note->refresh();
        $this->assertSame(CreditNote::STATUS_APPLIED, $note->status);
        $this->assertSame('0.0000', (string) $note->balance);
        $this->assertDatabaseCount('credit_note_applications', 1);
    }

    public function test_nullable_customer_id_does_not_enable_consumer_final(): void
    {
        [$company, $branch, $user] = $this->scenarioUserOnly();
        $product = $this->product($company);

        // Venta sin cliente: la defensa nominativa debe seguir activa.
        $sale = $this->completedSale($company, $branch, $user, $product->id, 2, null);
        $return = $this->returnFor($sale, $user, '1130.0000');

        try {
            $this->notes()->issueFromReturn($return, $user);
            $this->fail('No debe emitirse una NC Consumer Final hasta Fase 4B.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('customer', $e->errors());
        }

        $this->assertDatabaseCount('credit_notes', 0);
    }

    public function test_company_a_policy_does_not_affect_company_b(): void
    {
        [$companyA, $branchA, $userA, $customerA] = $this->scenario('A');
        [$companyB, $branchB, $userB, $customerB] = $this->scenario('B');

        $companyA->update(['credit_note_expiration_policy' => '30', 'credit_note_custom_expiration_days' => null]);
        $companyB->update(['credit_note_expiration_policy' => 'none', 'credit_note_custom_expiration_days' => null]);

        $productA = $this->product($companyA);
        $saleA = $this->completedSale($companyA, $branchA, $userA, $productA->id, 2, $customerA);
        $noteA = $this->notes()->issueFromReturn($this->returnFor($saleA, $userA, '1130.0000'), $userA);

        $productB = $this->product($companyB);
        $saleB = $this->completedSale($companyB, $branchB, $userB, $productB->id, 2, $customerB);
        $noteB = $this->notes()->issueFromReturn($this->returnFor($saleB, $userB, '1130.0000'), $userB);

        $this->assertNotNull($noteA->fresh()->expires_at);
        $this->assertNull($noteB->fresh()->expires_at);
    }

    public function test_config_permission_is_required_for_credit_note_expiration_settings(): void
    {
        [$company, $branch, $user] = $this->scenarioUserOnly('admin');
        $cashier = $this->userWithPermission($company, $branch, ['pos.acceder']);

        $this->actingAs($cashier)
            ->withSession(['active_company_id' => $company->id])
            ->put(route('configuracion.notas-credito.update'), [
                'credit_note_expiration_policy' => '30',
            ])
            ->assertForbidden();

        $this->assertSame('none', $company->fresh()->credit_note_expiration_policy);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id])
            ->put(route('configuracion.notas-credito.update'), [
                'credit_note_expiration_policy' => '90',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('90', $company->fresh()->credit_note_expiration_policy);
    }

    private function scenarioUserOnly(string $roleSuffix = ''): array
    {
        $company = $this->company($roleSuffix);
        $branch = $this->branch($company, 'Principal');

        return [$company, $branch, $this->userWithPermission($company, $branch, ['notas_credito.configurar'])];
    }

    public function test_update_request_rules_are_strict(): void
    {
        $request = new UpdateCreditNoteExpirationRequest();

        $this->assertArrayHasKey('credit_note_expiration_policy', $request->rules());
        $this->assertArrayHasKey('credit_note_custom_expiration_days', $request->rules());
    }

    /**
     * @return array{0: Company, 1: Branch, 2: User, 3: Customer}
     */
    private function scenario(string $suffix = ''): array
    {
        $company = $this->company($suffix);
        $branch = $this->branch($company, 'Principal');
        $user = $this->userWithPermission($company, $branch, ['devoluciones.crear']);
        $customer = $this->customer($company);

        return [$company, $branch, $user, $customer];
    }
}