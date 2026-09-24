<?php

namespace Tests\Feature;

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
use App\Services\Sales\CreditNoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CreditNoteConsumerFinalTest extends TestCase
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
            'name' => 'Rol NC CF '.uniqid(),
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
            'identification' => 'NCID-CF-'.uniqid(),
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
            'internal_code' => 'P-CF-'.uniqid(),
            'cost' => 500,
            'sale_price' => 1000,
            'tax_rate' => 13,
            'track_inventory' => true,
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
            ['company_id' => $company->id, 'code' => 'EFECTIVO-CF-'.$company->id],
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
    ): Sale {
        $sale = Sale::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'customer_id' => $customer?->id,
            'checkout_token' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', uniqid('nccf', true)),
            'sale_number' => 'POS-CF-'.uniqid(),
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
            'description' => 'Producto NC CF',
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

    private function returnFor(Sale $sale, User $user, string $total, string $qty = '1.0000'): SaleReturn
    {
        $return = SaleReturn::create([
            'company_id' => $sale->company_id,
            'branch_id' => $sale->branch_id,
            'sale_id' => $sale->id,
            'user_id' => $user->id,
            'return_number' => 'DEV-CF-'.uniqid(),
            'reason' => 'Devolución consumer final',
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

    private function postReturn(User $user, Company $company, Branch $branch, Sale $sale, array $payload)
    {
        return $this->actingAs($user)
            ->withSession([
                'active_company_id' => $company->id,
                'active_branch_id' => $branch->id,
            ])
            ->post(route('ventas.return.store', $sale), $payload);
    }

    private function notes(): CreditNoteService
    {
        return app(CreditNoteService::class);
    }

    public function test_consumer_final_toggle_defaults_off(): void
    {
        $company = $this->company();

        $this->assertFalse($company->fresh()->consumerFinalCreditNotesEnabled());
    }

    public function test_stats_update_via_settings_and_requires_config_permission(): void
    {
        [$company, $branch, $admin] = $this->scenario('admin');
        $cashier = $this->userWithPermission($company, $branch, ['pos.acceder']);

        $this->assertFalse($company->fresh()->credit_note_consumer_final);

        $this->actingAs($cashier)
            ->withSession(['active_company_id' => $company->id])
            ->put(route('configuracion.notas-credito.update'), [
                'credit_note_expiration_policy' => '30',
                'credit_note_consumer_final' => 1,
            ])
            ->assertForbidden();

        $this->assertFalse($company->fresh()->credit_note_consumer_final);

        $this->actingAs($admin)
            ->withSession(['active_company_id' => $company->id])
            ->put(route('configuracion.notas-credito.update'), [
                'credit_note_expiration_policy' => '30',
                'credit_note_consumer_final' => 1,
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue($company->fresh()->consumerFinalCreditNotesEnabled());

        $this->actingAs($admin)
            ->withSession(['active_company_id' => $company->id])
            ->put(route('configuracion.notas-credito.update'), [
                'credit_note_expiration_policy' => '30',
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse($company->fresh()->credit_note_consumer_final);
    }

    public function test_consumer_final_requires_identified_customer_when_toggle_off(): void
    {
        [$company, $branch, $user] = $this->scenario();
        $product = $this->product($company);
        $this->seedStock($branch, $product, 10);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 2, null);
        $return = $this->returnFor($sale, $user, '2260.0000', '2.0000');

        try {
            $this->notes()->issueFromReturnWithResult($return, $user);
            $this->fail('No debe emitirse una NC Consumer Final con toggle desactivado.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('customer', $e->errors());
        }

        $this->assertDatabaseCount('credit_notes', 0);
    }

    public function test_consumer_final_return_with_toggle_on_emits_nc_with_hash_only(): void
    {
        [$company, $branch, $user] = $this->scenario();
        $company->update(['credit_note_consumer_final' => true]);

        $product = $this->product($company);
        $this->seedStock($branch, $product, 10);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 4, null);

        $response = $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Devolución de consumidor final',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 2]],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('sale_returns', 1);
        $this->assertDatabaseCount('credit_notes', 1);

        $note = CreditNote::sole();
        $this->assertNull($note->customer_id);
        $this->assertSame('2260.0000', (string) $note->issued_amount);
        $this->assertSame(CreditNote::STATUS_ISSUED, $note->status);
        $this->assertTrue($note->idempotency_key === null);
        $this->assertNotEmpty($note->application_code_hash);
        // 64 caracteres hex = SHA-256
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $note->application_code_hash);

        $response->assertSessionHas('consumer_final_delivery');

        $flash = $response->getSession()->get('consumer_final_delivery');
        $this->assertSame($note->credit_note_number, $flash['credit_note_number']);
        $this->assertSame($note->application_code_hash, hash('sha256', CreditNoteService::normalizeApplicationCode($flash['application_code'])));

        $response->assertRedirect(route('notas-credito.consumer-final.delivered'));

        $page = $this->actingAs($user)
            ->withSession([
                'active_company_id' => $company->id,
                'active_branch_id' => $branch->id,
            ])
            ->get(route('notas-credito.consumer-final.delivered'))
            ->assertOk();

        $page->assertSee('NOTA DE CRÉDITO GENERADA');
        $page->assertSee($note->credit_note_number);
        $page->assertSee($flash['application_code']);
        $page->assertSee('Copiar código');
        $page->assertSee('Imprimir comprobante');
        $page->assertSee('Cerrar');
        $this->assertStringContainsString('no-store', $page->headers->get('Cache-Control'));

        $this->assertSame(12, (int) (float) DB::table('branch_product')->where('product_id', $product->id)->value('stock'));
    }

    public function test_application_code_format_and_alphabet_are_secure(): void
    {
        [$company, $branch, $user] = $this->scenario();
        $company->update(['credit_note_consumer_final' => true]);

        $product = $this->product($company);
        $this->seedStock($branch, $product, 10);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 4, null);

        $response = $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Consumer final formato',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        $code = $response->getSession()->get('consumer_final_delivery.application_code');
        $this->assertMatchesRegularExpression('/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{4}-[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{4}-[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{4}$/', $code);
        $this->assertDoesNotMatchRegularExpression('/[0O1IL]/', $code);
        $this->assertSame(14, strlen($code));

        $plain = str_replace('-', '', $code);
        $this->assertSame(12, strlen($plain));
    }

    public function test_successive_application_codes_differ(): void
    {
        $service = $this->notes();
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = $service->generateApplicationCode();
        }

        $this->assertCount(8, array_unique($codes));
    }

    public function test_normalize_and_hash_are_consistent(): void
    {
        $this->assertSame('ABCD2345EFGH', CreditNoteService::normalizeApplicationCode('  abcd-2345-efgh  '));
        $this->assertSame(
            hash('sha256', 'ABCD2345EFGH'),
            CreditNoteService::applicationCodeHash('abcd-2345-EFGH'),
        );
    }

    public function test_nominative_credit_note_has_no_application_code_hash(): void
    {
        [$company, $branch, $user, $customer] = $this->scenario('customer');
        $company->update(['credit_note_consumer_final' => true]);

        $product = $this->product($company);
        $this->seedStock($branch, $product, 10);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 2, $customer);

        $response = $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Devolución nominativa',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors()
            ->assertRedirect(route('ventas.show', $sale));

        $note = CreditNote::sole();
        $this->assertNotNull($note->customer_id);
        $this->assertNull($note->application_code_hash);
        $this->assertFalse($response->getSession()->has('consumer_final_delivery'));
    }

    public function test_one_sale_return_yields_at_most_one_credit_note(): void
    {
        [$company, $branch, $user] = $this->scenario();
        $company->update(['credit_note_consumer_final' => true]);

        $product = $this->product($company);
        $this->seedStock($branch, $product, 10);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 4, null);
        $return = $this->returnFor($sale, $user, '2260.0000', '2.0000');

        $first = $this->notes()->issueFromReturnWithResult($return, $user);

        $this->assertNotEmpty($first->applicationCode);

        $second = $this->notes()->issueFromReturnWithResult($return, $user);

        $this->assertSame($first->creditNote->id, $second->creditNote->id);
        $this->assertNull($second->applicationCode);
        $this->assertDatabaseCount('credit_notes', 1);
    }

    public function test_re_emission_never_reveals_or_regenerates_the_code(): void
    {
        [$company, $branch, $user] = $this->scenario();
        $company->update(['credit_note_consumer_final' => true]);

        $product = $this->product($company);
        $this->seedStock($branch, $product, 10);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 4, null);
        $return = $this->returnFor($sale, $user, '2260.0000', '2.0000');

        $first = $this->notes()->issueFromReturnWithResult($return, $user);
        $originalHash = CreditNote::sole()->application_code_hash;

        // Reintento con el mismo idempotency_key
        $retry = $this->notes()->issueFromReturnWithResult($return, $user, 'KEY-CF-'.uniqid());

        $this->assertNull($retry->applicationCode);
        $this->assertSame($originalHash, CreditNote::sole()->application_code_hash);
        $this->assertDatabaseCount('credit_notes', 1);
    }

    public function test_toggle_is_isolated_per_company(): void
    {
        [$companyA, $branchA, $userA] = $this->scenario('A');
        [$companyB, $branchB, $userB] = $this->scenario('B');

        $companyA->update(['credit_note_consumer_final' => true]);

        $productA = $this->product($companyA);
        $this->seedStock($branchA, $productA, 10);
        $saleA = $this->completedSale($companyA, $branchA, $userA, $productA->id, 4, null);

        $productB = $this->product($companyB);
        $this->seedStock($branchB, $productB, 10);
        $saleB = $this->completedSale($companyB, $branchB, $userB, $productB->id, 4, null);

        $this->postReturn($userA, $companyA, $branchA, $saleA, [
            'reason' => 'Consumer final A',
            'items' => [['sale_item_id' => $saleA->items->first()->id, 'quantity' => 2]],
        ])->assertSessionHasNoErrors();

        $noteA = CreditNote::sole();
        $this->assertNull($noteA->customer_id);
        $this->assertNotEmpty($noteA->application_code_hash);

        $this->postReturn($userB, $companyB, $branchB, $saleB, [
            'reason' => 'Consumer final B (toggle off)',
            'items' => [['sale_item_id' => $saleB->items->first()->id, 'quantity' => 2]],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('credit_notes', 1);
        $this->assertDatabaseCount('sale_returns', 2);
        $this->assertSame(12, (int) (float) DB::table('branch_product')->where('product_id', $productB->id)->value('stock'));
    }

    public function test_delivery_surface_appears_once_then_disappears_on_reload(): void
    {
        [$company, $branch, $user] = $this->scenario();
        $company->update(['credit_note_consumer_final' => true]);

        $product = $this->product($company);
        $this->seedStock($branch, $product, 10);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 4, null);

        $this->actingAs($user)
            ->withSession([
                'active_company_id' => $company->id,
                'active_branch_id' => $branch->id,
            ])
            ->post(route('ventas.return.store', $sale), [
                'reason' => 'Entrega única',
                'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 2]],
            ])
            ->assertRedirect(route('notas-credito.consumer-final.delivered'))
            ->assertSessionHas('consumer_final_delivery');

        $code = session()->get('consumer_final_delivery.application_code');
        $this->assertNotEmpty($code);

        $first = $this->actingAs($user)
            ->withSession([
                'active_company_id' => $company->id,
                'active_branch_id' => $branch->id,
            ])
            ->get(route('notas-credito.consumer-final.delivered'))
            ->assertOk();
        $first->assertSee($code);
        $this->assertStringContainsString('no-store', $first->headers->get('Cache-Control'));

        $second = $this->actingAs($user)
            ->withSession([
                'active_company_id' => $company->id,
                'active_branch_id' => $branch->id,
            ])
            ->get(route('notas-credito.consumer-final.delivered'));
        $second->assertRedirect(route('dashboard'));
    }

    public function test_delivery_direct_access_without_flash_redirects_without_secret(): void
    {
        [$company, $branch, $user] = $this->scenario();

        $response = $this->actingAs($user)
            ->withSession([
                'active_company_id' => $company->id,
                'active_branch_id' => $branch->id,
            ])
            ->get(route('notas-credito.consumer-final.delivered'));

        $response->assertRedirect(route('dashboard'));

        $this->assertStringNotContainsString(
            '-',
            $response->headers->get('Location'),
            'El código jamás debe viajar en la URL de redirección.',
        );
    }

    public function test_delivery_print_sheet_contains_number_amount_and_code(): void
    {
        [$company, $branch, $user] = $this->scenario();
        $company->update(['credit_note_consumer_final' => true]);

        $product = $this->product($company);
        $this->seedStock($branch, $product, 10);
        $sale = $this->completedSale($company, $branch, $user, $product->id, 4, null);

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Comprobante de emisión',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 2]],
        ])->assertSessionHasNoErrors();

        $code = session()->get('consumer_final_delivery.application_code');
        $number = session()->get('consumer_final_delivery.credit_note_number');

        $page = $this->actingAs($user)
            ->withSession([
                'active_company_id' => $company->id,
                'active_branch_id' => $branch->id,
            ])
            ->get(route('notas-credito.consumer-final.delivered'))
            ->assertOk();

        $page->assertSee('Código de aplicación');
        $page->assertSee($code);
        $page->assertSee($number);
        $page->assertSee('Conserve este número y este código');

        $this->assertStringContainsString('#delivery-print-sheet', $page->getContent());
        $this->assertStringContainsString('@media print', $page->getContent());
    }

    private function scenario(string $suffix = ''): array
    {
        $company = $this->company($suffix);
        $branch = $this->branch($company, 'Principal');
        $user = $this->userWithPermission($company, $branch, ['devoluciones.crear', 'notas_credito.configurar']);
        $customer = $this->customer($company);

        return [$company, $branch, $user, $customer];
    }
}
