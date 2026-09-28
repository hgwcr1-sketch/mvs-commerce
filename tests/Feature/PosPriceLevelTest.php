<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PosPriceLevelTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_customer_uses_sale_price(): void
    {
        [$company, $branch, $user, $cash] = $this->context([
            'pos.acceder',
            'ventas.crear',
        ]);

        $product = $this->product($company);

        $customer = Customer::create([
            'company_id' => $company->id,
            'customer_type' => 'individual',
            'name' => 'Cliente Normal',
            'credit_limit' => 0,
            'credit_days' => 0,
            'price_level' => 'normal',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)
            ->withSession([
                'active_company_id' => $company->id,
                'active_branch_id' => $branch->id,
            ])
            ->postJson(route('pos.checkout'), [
                'checkout_token' => (string) Str::uuid(),
                'customer_id' => $customer->id,

                'payments' => [[
                    'payment_method_id' => $cash->id,
                    'amount' => 20000,
                    'received_amount' => 20000,
                    'reference' => null,
                ]],

                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 1,
                ]],
            ]);

        $response->assertOk();

        $sale = Sale::with('items')->firstOrFail();

        $this->assertSame(
            '20000.0000',
            $sale->items->first()->unit_price,
        );
    }

public function test_wholesale_customer_uses_wholesale_price(): void
{
    [$company, $branch, $user, $cash] = $this->context([
        'pos.acceder',
        'ventas.crear',
    ]);

    $product = $this->product($company);

    $customer = Customer::create([
        'company_id' => $company->id,
        'customer_type' => 'individual',
        'name' => 'Cliente Mayorista',
        'credit_limit' => 0,
        'credit_days' => 0,
        'price_level' => 'wholesale',
        'is_active' => true,
    ]);

    $response = $this->actingAs($user)
        ->withSession([
            'active_company_id' => $company->id,
            'active_branch_id' => $branch->id,
        ])
        ->postJson(route('pos.checkout'), [
            'checkout_token' => (string) Str::uuid(),
            'customer_id' => $customer->id,

            'payments' => [[
                'payment_method_id' => $cash->id,
                'amount' => 15000,
                'received_amount' => 15000,
                'reference' => null,
            ]],

            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
            ]],
        ]);

    $response->assertOk();

    $sale = Sale::with('items')->firstOrFail();

    $this->assertSame(
        '15000.0000',
        $sale->items->first()->unit_price,
    );
}

public function test_price_a_customer_uses_price_a(): void
{
    [$company, $branch, $user, $cash] = $this->context([
        'pos.acceder',
        'ventas.crear',
    ]);

    $product = $this->product($company);

    $customer = Customer::create([
        'company_id' => $company->id,
        'customer_type' => 'individual',
        'name' => 'Cliente Precio A',
        'credit_limit' => 0,
        'credit_days' => 0,
        'price_level' => 'a',
        'is_active' => true,
    ]);

    $response = $this->actingAs($user)
        ->withSession([
            'active_company_id' => $company->id,
            'active_branch_id' => $branch->id,
        ])
        ->postJson(route('pos.checkout'), [
            'checkout_token' => (string) Str::uuid(),
            'customer_id' => $customer->id,

            'payments' => [[
                'payment_method_id' => $cash->id,
                'amount' => 12000,
                'received_amount' => 12000,
                'reference' => null,
            ]],

            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
            ]],
        ]);

    $response->assertOk();

    $sale = Sale::with('items')
        ->latest('id')
        ->firstOrFail();

    $this->assertSame(
        '12000.0000',
        $sale->items->first()->unit_price,
    );
}

public function test_price_b_customer_uses_price_b(): void
{
    [$company, $branch, $user, $cash] = $this->context([
        'pos.acceder',
        'ventas.crear',
    ]);

    $product = $this->product($company);

    $customer = Customer::create([
        'company_id' => $company->id,
        'customer_type' => 'individual',
        'name' => 'Cliente Precio B',
        'credit_limit' => 0,
        'credit_days' => 0,
        'price_level' => 'b',
        'is_active' => true,
    ]);

    $response = $this->actingAs($user)
        ->withSession([
            'active_company_id' => $company->id,
            'active_branch_id' => $branch->id,
        ])
        ->postJson(route('pos.checkout'), [
            'checkout_token' => (string) Str::uuid(),
            'customer_id' => $customer->id,

            'payments' => [[
                'payment_method_id' => $cash->id,
                'amount' => 10000,
                'received_amount' => 10000,
                'reference' => null,
            ]],

            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
            ]],
        ]);

    $response->assertOk();

    $sale = Sale::with('items')
        ->latest('id')
        ->firstOrFail();

    $this->assertSame(
        '10000.0000',
        $sale->items->first()->unit_price,
    );
}

public function test_price_c_customer_uses_price_c(): void
{
    [$company, $branch, $user, $cash] = $this->context([
        'pos.acceder',
        'ventas.crear',
    ]);

    $product = $this->product($company);

    $customer = Customer::create([
        'company_id' => $company->id,
        'customer_type' => 'individual',
        'name' => 'Cliente Precio C',
        'credit_limit' => 0,
        'credit_days' => 0,
        'price_level' => 'c',
        'is_active' => true,
    ]);

    $response = $this->actingAs($user)
        ->withSession([
            'active_company_id' => $company->id,
            'active_branch_id' => $branch->id,
        ])
        ->postJson(route('pos.checkout'), [
            'checkout_token' => (string) Str::uuid(),
            'customer_id' => $customer->id,

            'payments' => [[
                'payment_method_id' => $cash->id,
                'amount' => 9000,
                'received_amount' => 9000,
                'reference' => null,
            ]],

            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
            ]],
        ]);

    $response->assertOk();

    $sale = Sale::with('items')
        ->latest('id')
        ->firstOrFail();

    $this->assertSame(
        '9000.0000',
        $sale->items->first()->unit_price,
    );
}

public function test_price_level_falls_back_to_sale_price_when_level_price_is_null(): void
{
    [$company, $branch, $user, $cash] = $this->context([
        'pos.acceder',
        'ventas.crear',
    ]);

    $product = $this->product($company);

    $product->update([
        'price_a' => null,
    ]);

    $customer = Customer::create([
        'company_id' => $company->id,
        'customer_type' => 'individual',
        'name' => 'Cliente Precio A Sin Precio',
        'credit_limit' => 0,
        'credit_days' => 0,
        'price_level' => 'a',
        'is_active' => true,
    ]);

    $response = $this->actingAs($user)
        ->withSession([
            'active_company_id' => $company->id,
            'active_branch_id' => $branch->id,
        ])
        ->postJson(route('pos.checkout'), [
            'checkout_token' => (string) Str::uuid(),
            'customer_id' => $customer->id,

            'payments' => [[
                'payment_method_id' => $cash->id,
                'amount' => 20000,
                'received_amount' => 20000,
                'reference' => null,
            ]],

            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
            ]],
        ]);

    $response->assertOk();

    $sale = Sale::with('items')
        ->latest('id')
        ->firstOrFail();

    $this->assertSame(
        '20000.0000',
        $sale->items->first()->unit_price,
    );
}

public function test_authorized_manual_price_overrides_customer_price_level(): void
{
    [$company, $branch, $user, $cash] = $this->context([
        'pos.acceder',
        'ventas.crear',
        'pos.cambiar_precio',
    ]);

    $product = $this->product($company);

    $customer = Customer::create([
        'company_id' => $company->id,
        'customer_type' => 'individual',
        'name' => 'Cliente Mayorista Precio Manual',
        'credit_limit' => 0,
        'credit_days' => 0,
        'price_level' => 'wholesale',
        'is_active' => true,
    ]);

    $response = $this->actingAs($user)
        ->withSession([
            'active_company_id' => $company->id,
            'active_branch_id' => $branch->id,
        ])
        ->postJson(route('pos.checkout'), [
            'checkout_token' => (string) Str::uuid(),
            'customer_id' => $customer->id,

            'payments' => [[
                'payment_method_id' => $cash->id,
                'amount' => 13000,
                'received_amount' => 13000,
                'reference' => null,
            ]],

            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => 13000,
            ]],
        ]);

    $response->assertOk();

    $sale = Sale::with('items')
        ->latest('id')
        ->firstOrFail();

    $this->assertSame(
        '13000.0000',
        $sale->items->first()->unit_price,
    );

    $product->refresh();

    $this->assertSame('20000.00', $product->sale_price);
    $this->assertSame('15000.00', $product->wholesale_price);
}

    private function context(array $permissions): array
    {
        $company = Company::create([
            'trade_name' => 'Empresa '.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);

        $branch = Branch::create([
            'company_id' => $company->id,
            'name' => 'Principal',
            'code' => 'PR-'.$company->id.'-'.uniqid(),
            'is_active' => true,
        ]);

        $user = User::factory()->create();

        $role = Role::create([
            'company_id' => $company->id,
            'name' => 'Rol POS '.uniqid(),
            'is_active' => true,
        ]);

        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                [
                    'label' => $name,
                    'module' => 'POS',
                    'is_active' => true,
                ],
            );

            $role->permissions()->syncWithoutDetaching($permission);
        }

        $user->companies()->attach(
            $company->id,
            ['role_id' => $role->id],
        );

        $user->branches()->attach($branch->id);

        $cash = PaymentMethod::create([
            'company_id' => $company->id,
            'code' => 'cash-'.uniqid(),
            'name' => 'Efectivo',
            'type' => 'cash',
            'is_active' => true,
            'allows_change' => true,
        ]);

        $register = CashRegister::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'CAJA-'.uniqid(), 'name' => 'Caja', 'is_active' => true]);
        CashSession::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'cash_register_id' => $register->id, 'session_number' => 'CAJA-'.uniqid(), 'opened_by' => $user->id, 'status' => CashSession::STATUS_OPEN, 'open_guard' => CashSession::OPEN_GUARD, 'opening_amount' => 0, 'opened_at' => now()]);

        return [$company, $branch, $user, $cash];
    }

    private function product(Company $company): Product
    {
        $suffix = uniqid();

        $category = ProductCategory::create([
            'company_id' => $company->id,
            'name' => 'Categoría '.$suffix,
            'slug' => 'categoria-'.$suffix,
            'is_active' => true,
        ]);

        $unit = Unit::create([
            'company_id' => $company->id,
            'name' => 'Unidad',
            'abbreviation' => 'U',
            'slug' => 'u-'.$suffix,
            'allows_decimals' => false,
            'is_active' => true,
        ]);

        return Product::create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'name' => 'Producto '.$suffix,
            'internal_code' => 'P-'.$suffix,
            'cost' => 5000,
            'sale_price' => 20000,
            'wholesale_price' => 15000,
            'price_a' => 12000,
            'price_b' => 10000,
            'price_c' => 9000,
            'stock' => 123,
            'tax_rate' => 0,
            'track_inventory' => false,
            'is_active' => true,
        ]);
    }
}
