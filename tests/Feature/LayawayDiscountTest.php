<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\PaymentMethodProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LayawayDiscountTest extends TestCase
{
    use RefreshDatabase;

    public function test_layaway_applies_product_and_general_discounts_and_propagates_on_delivery(): void
    {
        [$company, $branch, $user, $cash, $session] = $this->context();
        $product = $this->product($company, taxRate: 13);
        $this->stock($branch, $product, 5);
        $customer = $this->customer($company);

        // Bruto 2000 - dto línea 100 = 1900; dto general 10% = 190; neto 1710; impuesto 13% = 222.30; total 1932.30
        $created = $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.apartados.store'), [
            'customer_id' => $customer->id,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 2,
                'discount' => 100,
                'discount_type' => 'fixed',
            ]],
            'discount_total' => 10,
            'discount_total_type' => 'percentage',
            'initial_amount' => 100,
            'payment_method_id' => $cash->id,
            'cash_session_id' => $session->id,
        ])->assertCreated();

        $layaway = Layaway::with('items')->findOrFail($created->json('layaway_id'));
        $this->assertSame('1932.3000', (string) $layaway->total);
        $this->assertSame('290.0000', (string) $layaway->discount_total);
        $this->assertSame('1832.3000', (string) $layaway->balance_due);
        $this->assertSame('1710.0000', (string) $layaway->items->first()->subtotal);
        $this->assertSame('290.0000', (string) $layaway->items->first()->discount_total);
        $this->assertSame('222.3000', (string) $layaway->items->first()->tax_total);
        $this->assertSame('1932.3000', (string) $layaway->items->first()->total);
        $this->assertSame(3, (int) DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));

        $this->withoutVite();
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('apartados.payments.store', $layaway), [
                'amount' => 1832.30,
                'payment_method_id' => $cash->id,
                'cash_session_id' => $session->id,
            ])->assertRedirect();
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('apartados.deliver', $layaway))->assertRedirect();

        $layaway->refresh();
        $sale = $layaway->sale()->with('items')->firstOrFail();
        $this->assertSame(Layaway::STATUS_DELIVERED, $layaway->status);
        $this->assertSame('1710.0000', (string) $sale->subtotal);
        $this->assertSame('290.0000', (string) $sale->discount_total);
        $this->assertSame('222.3000', (string) $sale->tax_total);
        $this->assertSame('1932.3000', (string) $sale->total);
        $this->assertSame('2000.0000', (string) $sale->items->first()->gross_total);
        $this->assertSame('290.0000', (string) $sale->items->first()->discount_total);
        $this->assertSame('1710.0000', (string) $sale->items->first()->subtotal);

        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('sale_items', 1);
        $this->assertSame(1, DB::table('inventory_movements')->where('reference_type', Layaway::class)->where('reference_id', $layaway->id)->count());
        $this->assertSame(3, (int) DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
    }

    public function test_layaway_rejects_discounts_without_permission_and_creates_clean_without_discounts(): void
    {
        [$company, $branch, $user, $cash, $session] = $this->context('Empresa', ['pos.acceder', 'ventas.crear', 'apartados.ver', 'apartados.crear']);
        $product = $this->product($company);
        $this->stock($branch, $product, 5);
        $customer = $this->customer($company);
        $base = [
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'discount' => 100, 'discount_type' => 'fixed']],
            'initial_amount' => 100,
            'payment_method_id' => $cash->id,
            'cash_session_id' => $session->id,
        ];

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->postJson(route('pos.apartados.store'), $base)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.discount');

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->postJson(route('pos.apartados.store'), array_merge($base, [
                'items' => [['product_id' => $product->id, 'quantity' => 2]],
                'discount_total' => 10,
                'discount_total_type' => 'fixed',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('discount_total');

        $this->assertDatabaseCount('layaways', 0);

        // Creación sin descuentos sigue funcionando con el mismo payload base sin ajustes.
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->postJson(route('pos.apartados.store'), array_merge($base, [
                'items' => [['product_id' => $product->id, 'quantity' => 2]],
            ]))
            ->assertCreated()
            ->assertJsonPath('total', '2000.0000');

        $layaway = Layaway::with('items')->firstOrFail();
        $this->assertSame('0.0000', (string) $layaway->discount_total);
        $this->assertSame('0.0000', (string) $layaway->items->first()->discount_total);
        $this->assertSame('2000.0000', (string) $layaway->items->first()->subtotal);
        $this->assertSame(3, (int) DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
    }

    private function context(string $name = 'Empresa', array $permissions = ['pos.acceder', 'ventas.crear', 'apartados.ver', 'apartados.crear', 'apartados.abonar', 'apartados.entregar', 'pos.aplicar_descuento']): array
    {
        $company = Company::create(['trade_name' => $name.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'layaway_validity_days' => 30, 'layaway_alert_days' => 5, 'is_active' => true]);
        $branch = $this->branch($company, 'Principal');
        $user = $this->user($company, $branch, $permissions);
        app(PaymentMethodProvisioner::class)->provision($company);
        $cash = PaymentMethod::forCompany($company->id)->where('type', PaymentMethod::TYPE_CASH)->firstOrFail();
        $session = $this->openSession($company, $branch, $user);

        return [$company, $branch, $user, $cash, $session];
    }

    private function openSession(Company $company, Branch $branch, User $user): CashSession
    {
        $register = CashRegister::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'C'.uniqid(), 'name' => 'Caja', 'is_active' => true]);

        return CashSession::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'cash_register_id' => $register->id, 'session_number' => 'CAJA-'.uniqid(), 'opened_by' => $user->id, 'status' => CashSession::STATUS_OPEN, 'open_guard' => CashSession::OPEN_GUARD, 'currency_code' => 'CRC', 'opening_amount' => '0', 'opened_at' => now()]);
    }

    private function branch(Company $company, string $name): Branch
    {
        return Branch::create(['company_id' => $company->id, 'name' => $name, 'code' => $name.'-'.$company->id, 'is_active' => true]);
    }

    private function user(Company $company, Branch $branch, array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'POS', 'is_active' => true]);
            $role->permissions()->syncWithoutDetaching($permission);
        }
        $user->companies()->attach($company, ['role_id' => $role->id]);
        $user->branches()->attach($branch);

        return $user;
    }

    private function product(Company $company, float $taxRate = 0): Product
    {
        $id = uniqid();
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Cat '.$id, 'slug' => 'cat-'.$id, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad', 'abbreviation' => 'U', 'slug' => 'u-'.$id, 'allows_decimals' => false, 'is_active' => true]);

        return Product::create(['company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id, 'name' => 'Producto '.$id, 'internal_code' => 'P-'.$id, 'cost' => 500, 'sale_price' => 1000, 'tax_rate' => $taxRate, 'track_inventory' => true, 'is_active' => true]);
    }

    private function stock(Branch $branch, Product $product, float $stock): void
    {
        DB::table('branch_product')->insert(['branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => $stock, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function customer(Company $company): Customer
    {
        return Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente '.uniqid(), 'is_active' => true]);
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
