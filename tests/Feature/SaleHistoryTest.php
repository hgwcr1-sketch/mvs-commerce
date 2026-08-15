<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
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
    ): Sale {
        return Sale::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'customer_id' => null,
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