<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DevolucionesIndexTest extends TestCase
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
            'name' => 'Rol Devoluciones '.uniqid(),
            'is_active' => true,
        ]);

        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                [
                    'label' => $name,
                    'module' => 'Ventas',
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

        return $user;
    }

    private function customer(Company $company, string $name = 'Cliente', ?string $identification = null): Customer
    {
        return Customer::create([
            'company_id' => $company->id,
            'name' => $name.' '.uniqid(),
            'identification' => $identification ?? 'ID-'.uniqid(),
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

    private function paymentMethodId(Company $company): int
    {
        return \App\Models\PaymentMethod::firstOrCreate(
            ['company_id' => $company->id, 'code' => 'EFECTIVO-'.$company->id],
            [
                'name' => 'Efectivo',
                'type' => 'cash',
                'allows_change' => true,
                'affects_cash' => true,
                'is_active' => true,
            ]
        )->id;
    }

    /**
     * @param  array<int, int>  $quantities  Producto => cantidad vendida
     */
    private function completedSale(
        Company $company,
        Branch $branch,
        User $user,
        array $quantities,
        ?Customer $customer = null,
        string $status = Sale::STATUS_COMPLETED,
    ): Sale {
        $sale = Sale::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'customer_id' => $customer?->id,
            'checkout_token' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', uniqid('sale', true)),
            'sale_number' => 'POS-RET-'.uniqid(),
            'document_type' => Sale::DOCUMENT_ELECTRONIC_TICKET,
            'sale_condition' => Sale::CONDITION_CASH,
            'status' => $status,
            'currency_code' => 'CRC',
            'exchange_rate' => 1,
            'subtotal' => 1000,
            'discount_total' => 0,
            'tax_total' => 130,
            'rounding_total' => 0,
            'total' => 1130,
            'paid_total' => 1130,
            'balance_due' => 0,
            'completed_at' => now(),
        ]);

        foreach ($quantities as $productId => $qty) {
            $sale->items()->create([
                'product_id' => $productId,
                'product_code' => 'P-CODE',
                'barcode' => null,
                'cabys_code' => null,
                'description' => 'Producto test',
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
        }

        if ($status === Sale::STATUS_COMPLETED || $status === Sale::STATUS_PARTIALLY_RETURNED) {
            $sale->payments()->create([
                'payment_method_id' => $this->paymentMethodId($company),
                'affects_cash_snapshot' => true,
                'created_by' => $user->id,
                'amount' => 1130 * array_sum($quantities),
                'received_amount' => 1130 * array_sum($quantities),
                'change_amount' => 0,
                'cash_effect_amount' => 1130 * array_sum($quantities),
                'reference' => null,
                'status' => SalePayment::STATUS_COMPLETED,
            ]);
        }

        return $sale;
    }

    private function getDevolucionesIndex(array $params = [], ?User $user = null, ?Company $company = null, ?Branch $branch = null)
    {
        $user = $user ?? auth()->user();
        $company = $company ?? $user->companies()->first();
        $branch = $branch ?? $user->branches()->first();

        return $this->actingAs($user)
            ->withSession([
                'active_company_id' => $company->id,
                'active_branch_id' => $branch->id,
            ])
            ->get(route('devoluciones.index', $params));
    }

    public function test_user_without_permission_cannot_access(): void
    {
        $company = $this->company();
        $branch = $this->branch($company, 'Principal');
        $user = $this->userWithPermission($company, $branch, []);

        $this->getDevolucionesIndex([], $user, $company, $branch)->assertForbidden();
    }

public function test_user_with_permission_can_access(): void
        {
            $company = $this->company();
            $branch = $this->branch($company, 'Principal');
            $user = $this->userWithPermission($company, $branch, ['devoluciones.crear', 'notas_credito.crear']);

            $this->getDevolucionesIndex([], $user, $company, $branch)->assertOk();
        }

public function test_sale_from_other_company_is_isolated(): void
    {
        $companyA = $this->company('A');
        $branchA = $this->branch($companyA, 'Sucursal A');

        $companyB = $this->company('B');
        $branchB = $this->branch($companyB, 'Sucursal B');

        $seller = $this->userWithPermission($companyA, $branchA, []);
        $productA = $this->product($companyA);
        $sale = $this->completedSale($companyA, $branchA, $seller, [$productA->id => 2]);

        $returner = $this->userWithPermission($companyB, $branchB, ['devoluciones.crear', 'notas_credito.crear']);

        $response = $this->getDevolucionesIndex([], $returner, $companyB, $branchB);
        $response->assertOk();
        $response->assertDontSee($sale->sale_number);
    }

    public function test_sale_from_other_branch_is_isolated(): void
    {
        $company = $this->company();
        $branchSale = $this->branch($company, 'Sucursal venta');
        $branchActive = $this->branch($company, 'Sucursal activa');

        $seller = $this->userWithPermission($company, $branchSale, []);
        $productForSale = $this->product($company);
        $sale = $this->completedSale($company, $branchSale, $seller, [$productForSale->id => 2]);

        $returner = $this->userWithPermission($company, $branchActive, ['devoluciones.crear', 'notas_credito.crear']);

        $response = $this->getDevolucionesIndex([], $returner, $company, $branchActive);
        $response->assertOk();
        $response->assertDontSee($sale->sale_number);
    }

    public function test_search_by_sale_number(): void
    {
        $company = $this->company();
        $branch = $this->branch($company, 'Principal');
        $user = $this->userWithPermission($company, $branch, ['devoluciones.crear', 'notas_credito.crear']);

        $product = $this->product($company);
        $sale = $this->completedSale($company, $branch, $user, [$product->id => 2]);
        $otherSale = $this->completedSale($company, $branch, $user, [$product->id => 1]);

        $response = $this->getDevolucionesIndex(['search' => $sale->sale_number], $user, $company, $branch);
        $response->assertOk();
        $response->assertSee($sale->sale_number);
        $response->assertDontSee($otherSale->sale_number);
    }

    public function test_search_by_customer_name(): void
    {
        $company = $this->company();
        $branch = $this->branch($company, 'Principal');
        $user = $this->userWithPermission($company, $branch, ['devoluciones.crear', 'notas_credito.crear']);

        $customer = $this->customer($company, 'Juan Pérez');
        $product = $this->product($company);
        $sale = $this->completedSale($company, $branch, $user, [$product->id => 2], $customer);

        $otherCustomer = $this->customer($company, 'María García');
        $otherSale = $this->completedSale($company, $branch, $user, [$product->id => 1], $otherCustomer);

        $response = $this->getDevolucionesIndex(['search' => 'Juan Pérez'], $user, $company, $branch);
        $response->assertOk();
        $response->assertSee($sale->sale_number);
        $response->assertDontSee($otherSale->sale_number);
    }

    public function test_search_by_customer_identification(): void
    {
        $company = $this->company();
        $branch = $this->branch($company, 'Principal');
        $user = $this->userWithPermission($company, $branch, ['devoluciones.crear', 'notas_credito.crear']);

        $customer = $this->customer($company, 'Cliente Test', '123456789');
        $product = $this->product($company);
        $sale = $this->completedSale($company, $branch, $user, [$product->id => 2], $customer);

        $otherCustomer = $this->customer($company, 'Otro Cliente', '987654321');
        $otherSale = $this->completedSale($company, $branch, $user, [$product->id => 1], $otherCustomer);

        $response = $this->getDevolucionesIndex(['search' => '123456789'], $user, $company, $branch);
        $response->assertOk();
        $response->assertSee($sale->sale_number);
        $response->assertDontSee($otherSale->sale_number);
    }

    public function test_filter_by_status_completed(): void
    {
        $company = $this->company();
        $branch = $this->branch($company, 'Principal');
        $user = $this->userWithPermission($company, $branch, ['devoluciones.crear', 'notas_credito.crear']);

        $product = $this->product($company);
        $completedSale = $this->completedSale($company, $branch, $user, [$product->id => 2]);
        $partialSale = $this->completedSale($company, $branch, $user, [$product->id => 2], null, Sale::STATUS_PARTIALLY_RETURNED);

        $response = $this->getDevolucionesIndex(['status' => Sale::STATUS_COMPLETED], $user, $company, $branch);
        $response->assertOk();
        $response->assertSee($completedSale->sale_number);
        $response->assertDontSee($partialSale->sale_number);
    }

    public function test_filter_by_status_partially_returned(): void
    {
        $company = $this->company();
        $branch = $this->branch($company, 'Principal');
        $user = $this->userWithPermission($company, $branch, ['devoluciones.crear', 'notas_credito.crear']);

        $product = $this->product($company);
        $completedSale = $this->completedSale($company, $branch, $user, [$product->id => 2]);
        $partialSale = $this->completedSale($company, $branch, $user, [$product->id => 2], null, Sale::STATUS_PARTIALLY_RETURNED);

        $response = $this->getDevolucionesIndex(['status' => Sale::STATUS_PARTIALLY_RETURNED], $user, $company, $branch);
        $response->assertOk();
        $response->assertSee($partialSale->sale_number);
        $response->assertDontSee($completedSale->sale_number);
    }

    public function test_filter_by_date_from(): void
    {
        $company = $this->company();
        $branch = $this->branch($company, 'Principal');
        $user = $this->userWithPermission($company, $branch, ['devoluciones.crear', 'notas_credito.crear']);

        $product = $this->product($company);

        $oldSale = Sale::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'customer_id' => null,
            'checkout_token' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', uniqid('sale', true)),
            'sale_number' => 'POS-OLD-'.uniqid(),
            'document_type' => Sale::DOCUMENT_ELECTRONIC_TICKET,
            'sale_condition' => Sale::CONDITION_CASH,
            'status' => Sale::STATUS_COMPLETED,
            'currency_code' => 'CRC',
            'exchange_rate' => 1,
            'subtotal' => 1000,
            'discount_total' => 0,
            'tax_total' => 130,
            'rounding_total' => 0,
            'total' => 1130,
            'paid_total' => 1130,
            'balance_due' => 0,
            'completed_at' => now()->subDays(10),
        ]);
        $oldSale->items()->create([
            'product_id' => $product->id,
            'product_code' => 'P-CODE',
            'barcode' => null,
            'cabys_code' => null,
            'description' => 'Producto test',
            'unit_code' => 'U',
            'quantity' => 1,
            'unit_price' => 1000,
            'gross_total' => 1000,
            'discount_total' => 0,
            'subtotal' => 1000,
            'tax_rate' => 13,
            'tax_total' => 130,
            'total' => 1130,
            'unit_cost' => 600,
        ]);
        $oldSale->payments()->create([
            'payment_method_id' => $this->paymentMethodId($company),
            'affects_cash_snapshot' => true,
            'created_by' => $user->id,
            'amount' => 1130,
            'received_amount' => 1130,
            'change_amount' => 0,
            'cash_effect_amount' => 1130,
            'reference' => null,
            'status' => SalePayment::STATUS_COMPLETED,
        ]);

        $newSale = $this->completedSale($company, $branch, $user, [$product->id => 1]);

        $response = $this->getDevolucionesIndex(['date_from' => now()->subDays(5)->toDateString()], $user, $company, $branch);
        $response->assertOk();
        $response->assertSee($newSale->sale_number);
        $response->assertDontSee($oldSale->sale_number);
    }

    public function test_filter_by_date_to(): void
    {
        $company = $this->company();
        $branch = $this->branch($company, 'Principal');
        $user = $this->userWithPermission($company, $branch, ['devoluciones.crear', 'notas_credito.crear']);

        $product = $this->product($company);

        $oldSale = Sale::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'customer_id' => null,
            'checkout_token' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', uniqid('sale', true)),
            'sale_number' => 'POS-OLD-'.uniqid(),
            'document_type' => Sale::DOCUMENT_ELECTRONIC_TICKET,
            'sale_condition' => Sale::CONDITION_CASH,
            'status' => Sale::STATUS_COMPLETED,
            'currency_code' => 'CRC',
            'exchange_rate' => 1,
            'subtotal' => 1000,
            'discount_total' => 0,
            'tax_total' => 130,
            'rounding_total' => 0,
            'total' => 1130,
            'paid_total' => 1130,
            'balance_due' => 0,
            'completed_at' => now()->subDays(10),
        ]);
        $oldSale->items()->create([
            'product_id' => $product->id,
            'product_code' => 'P-CODE',
            'barcode' => null,
            'cabys_code' => null,
            'description' => 'Producto test',
            'unit_code' => 'U',
            'quantity' => 1,
            'unit_price' => 1000,
            'gross_total' => 1000,
            'discount_total' => 0,
            'subtotal' => 1000,
            'tax_rate' => 13,
            'tax_total' => 130,
            'total' => 1130,
            'unit_cost' => 600,
        ]);
        $oldSale->payments()->create([
            'payment_method_id' => $this->paymentMethodId($company),
            'affects_cash_snapshot' => true,
            'created_by' => $user->id,
            'amount' => 1130,
            'received_amount' => 1130,
            'change_amount' => 0,
            'cash_effect_amount' => 1130,
            'reference' => null,
            'status' => SalePayment::STATUS_COMPLETED,
        ]);

        $newSale = $this->completedSale($company, $branch, $user, [$product->id => 1]);

        $response = $this->getDevolucionesIndex(['date_to' => now()->subDays(5)->toDateString()], $user, $company, $branch);
        $response->assertOk();
        $response->assertSee($oldSale->sale_number);
        $response->assertDontSee($newSale->sale_number);
    }

    public function test_only_eligible_sales_are_shown(): void
    {
        $company = $this->company();
        $branch = $this->branch($company, 'Principal');
        $user = $this->userWithPermission($company, $branch, ['devoluciones.crear', 'notas_credito.crear']);

        $product = $this->product($company);

        // Eligible: completed
        $completedSale = $this->completedSale($company, $branch, $user, [$product->id => 2]);

        // Eligible: partially returned
        $partialSale = $this->completedSale($company, $branch, $user, [$product->id => 2], null, Sale::STATUS_PARTIALLY_RETURNED);

        // NOT eligible: voided
        $voidedSale = $this->completedSale($company, $branch, $user, [$product->id => 2], null, Sale::STATUS_VOIDED);

        // NOT eligible: returned
        $returnedSale = $this->completedSale($company, $branch, $user, [$product->id => 2], null, Sale::STATUS_RETURNED);

        // NOT eligible: historical
        $historicalSale = $this->completedSale($company, $branch, $user, [$product->id => 2]);
        $historicalSale->update(['is_historical' => true]);

        $response = $this->getDevolucionesIndex([], $user, $company, $branch);
        $response->assertOk();
        $response->assertSee($completedSale->sale_number);
        $response->assertSee($partialSale->sale_number);
        $response->assertDontSee($voidedSale->sale_number);
        $response->assertDontSee($returnedSale->sale_number);
        $response->assertDontSee($historicalSale->sale_number);
    }

    public function test_select_button_points_to_ventas_return_create(): void
    {
        $company = $this->company();
        $branch = $this->branch($company, 'Principal');
        $user = $this->userWithPermission($company, $branch, ['devoluciones.crear', 'notas_credito.crear']);

        $product = $this->product($company);
        $sale = $this->completedSale($company, $branch, $user, [$product->id => 2]);

        $response = $this->getDevolucionesIndex([], $user, $company, $branch);
        $response->assertOk();
        $response->assertSee(route('ventas.return.create', $sale));
    }

    public function test_consumer_final_sale_is_shown(): void
    {
        $company = $this->company();
        $branch = $this->branch($company, 'Principal');
        $user = $this->userWithPermission($company, $branch, ['devoluciones.crear', 'notas_credito.crear']);

        $product = $this->product($company);
        $sale = $this->completedSale($company, $branch, $user, [$product->id => 2], null);

        $response = $this->getDevolucionesIndex([], $user, $company, $branch);
        $response->assertOk();
        $response->assertSee($sale->sale_number);
        $response->assertSee('Consumidor Final');
    }

    public function test_customer_identification_is_displayed(): void
    {
        $company = $this->company();
        $branch = $this->branch($company, 'Principal');
        $user = $this->userWithPermission($company, $branch, ['devoluciones.crear', 'notas_credito.crear']);

        $customer = $this->customer($company, 'Cliente Con ID', '999-888-777');
        $product = $this->product($company);
        $sale = $this->completedSale($company, $branch, $user, [$product->id => 2], $customer);

        $response = $this->getDevolucionesIndex([], $user, $company, $branch);
        $response->assertOk();
        $response->assertSee('999-888-777');
    }

    public function test_pagination_links_preserve_query_string(): void
    {
        $company = $this->company();
        $branch = $this->branch($company, 'Principal');
        $user = $this->userWithPermission($company, $branch, ['devoluciones.crear', 'notas_credito.crear']);

        $product = $this->product($company);

        // Create 25 sales to force pagination
        for ($i = 0; $i < 25; $i++) {
            $this->completedSale($company, $branch, $user, [$product->id => 1]);
        }

        $response = $this->getDevolucionesIndex(['search' => 'POS'], $user, $company, $branch);
        $response->assertOk();
        $response->assertSee('search=POS');
    }
}