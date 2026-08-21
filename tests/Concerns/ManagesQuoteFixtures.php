<?php

namespace Tests\Concerns;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

trait ManagesQuoteFixtures
{
    public function context(string $name = 'Empresa', array $permissions = ['pos.acceder', 'ventas.crear', 'cotizaciones.ver']): array
    {
        $company = $this->company($name);
        $branch = $this->branch($company, 'Principal');
        $user = $this->user($company, $branch, $permissions);

        return [$company, $branch, $user, $this->payment($company)];
    }

    public function company(string $name): Company
    {
        return Company::create(['trade_name' => $name.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
    }

    public function branch(Company $company, string $name): Branch
    {
        return Branch::create(['company_id' => $company->id, 'name' => $name, 'code' => $name.'-'.$company->id, 'is_active' => true]);
    }

    public function user(Company $company, Branch $branch, array $permissions): User
    {
        $user = User::factory()->create();
        $role = \App\Models\Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $permissionName) {
            $permission = \App\Models\Permission::firstOrCreate(['name' => $permissionName], ['label' => $permissionName, 'module' => 'POS', 'is_active' => true]);
            $role->permissions()->syncWithoutDetaching($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return $user;
    }

    public function payment(Company $company, array $attributes = []): PaymentMethod
    {
        return PaymentMethod::create(array_merge(['company_id' => $company->id, 'code' => 'cash-'.uniqid(), 'name' => 'Efectivo', 'type' => 'cash', 'is_active' => true, 'allows_change' => true], $attributes));
    }

    public function product(Company $company, bool $tracked, bool $decimals = false, array $attributes = []): Product
    {
        $suffix = uniqid();
        $category = \App\Models\ProductCategory::create(['company_id' => $company->id, 'name' => 'Cat '.$suffix, 'slug' => 'cat-'.$suffix, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad', 'abbreviation' => 'U', 'slug' => 'u-'.$suffix, 'allows_decimals' => $decimals, 'is_active' => true]);

        return Product::create(array_merge(['company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id, 'name' => 'Producto '.$suffix, 'internal_code' => 'P-'.$suffix, 'cost' => 500, 'sale_price' => 1000, 'stock' => 123, 'tax_rate' => 13, 'track_inventory' => $tracked, 'is_active' => true], $attributes));
    }

    public function customer(Company $company, array $attributes = []): Customer
    {
        return Customer::create(array_merge(['company_id' => $company->id, 'name' => 'Cliente '.uniqid(), 'customer_type' => 'individual', 'is_active' => true], $attributes));
    }

    public function stock(Branch $branch, Product $product, float $stock): void
    {
        DB::table('branch_product')->insert(['branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => $stock, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }

    /**
     * Create a quote from the browser cart. Returns the persisted Quote (with items loaded).
     */
    public function makeQuote(User $user, Company $company, Branch $branch, array $items, array $extra = [], ?int $customer = null): Quote
    {
        $payload = array_merge([
            'customer_id' => $customer,
            'items' => $items,
        ], $extra);

        $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->postJson(route('cotizaciones.store'), $payload)
            ->assertSuccessful();

        return Quote::where('company_id', $company->id)->with('items')->latest('id')->firstOrFail();
    }

    /**
     * Cancel a quote via the edit-view reuse (cotizaciones.update).
     */
    public function cancelQuote(User $user, Company $company, Branch $branch, Quote $quote, string $reason): TestResponse
    {
        return $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->putJson(route('cotizaciones.update', $quote), ['cancellation_reason' => $reason]);
    }

    /**
     * POST a checkout payload (reuses the same shape as PosCheckoutTest::checkout).
     */
    public function checkout(User $user, Company $company, Branch $branch, PaymentMethod $method, array $items, int $received, array $extra = [], ?int $customer = null, ?string $token = null): TestResponse
    {
        $total = round(collect($items)->sum(function ($item) {
            $product = Product::findOrFail($item['product_id']);

            return (float) $product->sale_price * (float) $item['quantity'] * (1 + ((float) ($product->tax_rate ?? 0) / 100));
        }), 0, PHP_ROUND_HALF_UP);

        return $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.checkout'), array_merge([
            'checkout_token' => $token ?? (string) Str::uuid(),
            'customer_id' => $customer,
            'payments' => [['payment_method_id' => $method->id, 'amount' => $total, 'received_amount' => $received, 'reference' => null]],
            'items' => $items,
        ], $extra));
    }
}
