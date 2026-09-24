<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SaleHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_history_only_shows_sales_from_active_company_and_branch(): void
    {
        $company = $this->company('Empresa Uno ');
        $branch = $this->branch($company, 'Liberia');

        $otherBranch = $this->branch($company, 'San Ramon');

        $user = $this->user($company, $branch, [
            'ventas.ver',
        ]);

        $visibleSale = $this->sale(
            $company,
            $branch,
            $user,
            'POS-VISIBLE-001',
        );

        $hiddenSale = $this->sale(
            $company,
            $otherBranch,
            $user,
            'POS-HIDDEN-001',
        );

        $response = $this->actingAs($user)
            ->withSession([
                'active_company_id' => $company->id,
                'active_branch_id' => $branch->id,
            ])
            ->get(route('ventas.index'));

        $response->assertOk();

        $response->assertSee($visibleSale->sale_number);
        $response->assertDontSee($hiddenSale->sale_number);

        $response->assertSee('Historial de ventas');
        $response->assertSee('Reimprimir');
    }

    public function test_sale_detail_is_visible_only_in_active_company_and_branch(): void
    {
        $company = $this->company('Empresa Uno ');
        $branch = $this->branch($company, 'Liberia');
        $otherBranch = $this->branch($company, 'San Ramon');

        $user = $this->user($company, $branch, [
            'ventas.ver',
        ]);

        $visibleSale = $this->sale(
            $company,
            $branch,
            $user,
            'POS-VISIBLE-002',
        );

        $hiddenSale = $this->sale(
            $company,
            $otherBranch,
            $user,
            'POS-HIDDEN-002',
        );

        $this->actingAs($user)
            ->withSession([
                'active_company_id' => $company->id,
                'active_branch_id' => $branch->id,
            ])
            ->get(route('ventas.show', $visibleSale))
            ->assertOk()
            ->assertSee($visibleSale->sale_number)
            ->assertSee('Reimprimir comprobante');

        $this->actingAs($user)
            ->withSession([
                'active_company_id' => $company->id,
                'active_branch_id' => $branch->id,
            ])
            ->get(route('ventas.show', $hiddenSale))
            ->assertNotFound();
    }

    public function test_voided_sale_shows_completed_and_voided_dates_in_history(): void
    {
        $company = $this->company('Empresa Uno ');
        $branch = $this->branch($company, 'Liberia');

        $user = $this->user($company, $branch, [
            'ventas.ver',
        ]);

        $sale = $this->sale(
            $company,
            $branch,
            $user,
            'POS-VOID-001',
        );

        $sale->update([
            'status' => Sale::STATUS_VOIDED,
            'completed_at' => Carbon::create(2026, 9, 1, 10, 30, 0),
            'voided_at' => Carbon::create(2026, 9, 2, 15, 45, 0),
        ]);

        $response = $this->actingAs($user)
            ->withSession([
                'active_company_id' => $company->id,
                'active_branch_id' => $branch->id,
            ])
            ->get(route('ventas.index'));

        $response->assertOk();

        $response->assertSee('01/09/2026 10:30');
        $response->assertSee('Anulada el 02/09/2026 15:45');
    }

    public function test_voided_sale_shows_both_dates_in_detail(): void
    {
        $company = $this->company('Empresa Uno ');
        $branch = $this->branch($company, 'Liberia');

        $user = $this->user($company, $branch, [
            'ventas.ver',
        ]);

        $sale = $this->sale(
            $company,
            $branch,
            $user,
            'POS-VOID-002',
        );

        $sale->update([
            'status' => Sale::STATUS_VOIDED,
            'completed_at' => Carbon::create(2026, 9, 1, 10, 30, 0),
            'voided_at' => Carbon::create(2026, 9, 2, 15, 45, 0),
        ]);

        $response = $this->actingAs($user)
            ->withSession([
                'active_company_id' => $company->id,
                'active_branch_id' => $branch->id,
            ])
            ->get(route('ventas.show', $sale));

        $response->assertOk();

        $response->assertSee('01/09/2026 10:30');
        $response->assertSee('Anulada el 02/09/2026 15:45');
    }

    public function test_history_filters_by_customer_id_with_similar_names(): void
    {
        $company = $this->company('Empresa Uno ');
        $branch = $this->branch($company, 'Liberia');

        $user = $this->user($company, $branch, [
            'ventas.ver',
        ]);

        $customerA = Customer::create([
            'company_id' => $company->id,
            'customer_type' => 'individual',
            'name' => 'María Pérez Vargas',
            'is_active' => true,
        ]);

        $customerB = Customer::create([
            'company_id' => $company->id,
            'customer_type' => 'individual',
            'name' => 'María Pérez Rojas',
            'is_active' => true,
        ]);

        $saleA = $this->sale($company, $branch, $user, 'POS-CUST-A-001', $customerA);
        $saleB = $this->sale($company, $branch, $user, 'POS-CUST-B-001', $customerB);

        $response = $this->actingAs($user)
            ->withSession([
                'active_company_id' => $company->id,
                'active_branch_id' => $branch->id,
            ])
            ->get(route('ventas.index', ['customer_id' => $customerA->id]));

        $response->assertOk();

        $response->assertSee($saleA->sale_number);
        $response->assertDontSee($saleB->sale_number);
    }

    public function test_history_without_customer_filter_returns_all(): void
    {
        $company = $this->company('Empresa Uno ');
        $branch = $this->branch($company, 'Liberia');

        $user = $this->user($company, $branch, [
            'ventas.ver',
        ]);

        $customerA = Customer::create([
            'company_id' => $company->id,
            'customer_type' => 'individual',
            'name' => 'Cliente A',
            'is_active' => true,
        ]);

        $customerB = Customer::create([
            'company_id' => $company->id,
            'customer_type' => 'individual',
            'name' => 'Cliente B',
            'is_active' => true,
        ]);

        $saleA = $this->sale($company, $branch, $user, 'POS-CLEAR-A-001', $customerA);
        $saleB = $this->sale($company, $branch, $user, 'POS-CLEAR-B-001', $customerB);

        $response = $this->actingAs($user)
            ->withSession([
                'active_company_id' => $company->id,
                'active_branch_id' => $branch->id,
            ])
            ->get(route('ventas.index'));

        $response->assertOk();

        $response->assertSee($saleA->sale_number);
        $response->assertSee($saleB->sale_number);
        $response->assertSee('Completada');
        $response->assertSee($saleA->completed_at->format('d/m/Y H:i'));
    }

    public function test_history_rejects_customer_id_from_other_company(): void
    {
        $company = $this->company('Empresa Uno ');
        $branch = $this->branch($company, 'Liberia');
        $otherCompany = $this->company('Empresa Ajena ');

        $user = $this->user($company, $branch, [
            'ventas.ver',
        ]);

        $foreignCustomer = Customer::create([
            'company_id' => $otherCompany->id,
            'customer_type' => 'individual',
            'name' => 'Cliente Ajeno',
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'company_id' => $company->id,
            'customer_type' => 'individual',
            'name' => 'Cliente Local',
            'is_active' => true,
        ]);

        $sale = $this->sale($company, $branch, $user, 'POS-FOREIGN-001', $customer);

        $response = $this->actingAs($user)
            ->withSession([
                'active_company_id' => $company->id,
                'active_branch_id' => $branch->id,
            ])
            ->get(route('ventas.index', ['customer_id' => $foreignCustomer->id]));

        $response->assertOk();

        $response->assertSee($sale->sale_number);
        $response->assertDontSee($foreignCustomer->name);
    }

    private function company(string $name): Company
    {
        return Company::create([
            'trade_name' => $name.uniqid(),
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
            'code' => strtoupper(substr($name, 0, 3)).'-'.$company->id.'-'.uniqid(),
            'is_active' => true,
        ]);
    }

    private function user(
        Company $company,
        Branch $branch,
        array $permissions,
    ): User {
        $user = User::factory()->create();

        $role = Role::create([
            'company_id' => $company->id,
            'name' => 'Rol Ventas '.uniqid(),
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

    private function sale(
        Company $company,
        Branch $branch,
        User $user,
        string $number,
        ?Customer $customer = null,
    ): Sale {
        return Sale::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'customer_id' => $customer?->id,
            'checkout_token' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', $number),
            'sale_number' => $number,
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
            'completed_at' => now(),
        ]);
    }
}