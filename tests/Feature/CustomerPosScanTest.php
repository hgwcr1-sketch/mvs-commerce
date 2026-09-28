<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerPosScanTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_customers_finds_by_public_code(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Escaneable', 'phone' => '88880001', 'is_active' => true]);
        $customer->refresh();
        $code = $customer->public_code;

        $response = $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->getJson(route('pos.customers.search', ['q' => $code]));
        $response->assertOk();
        $this->assertTrue(collect($response->json())->contains(fn ($c) => $c['public_code'] === $code && $c['id'] === $customer->id));
        // búsqueda manual LIKE sigue funcionando
        $response2 = $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->getJson(route('pos.customers.search', ['q' => substr($code, 0, 4)]));
        $response2->assertOk();
        $this->assertTrue(collect($response2->json())->contains(fn ($c) => $c['id'] === $customer->id));
    }

    public function test_search_customers_public_code_isolated_per_company(): void
    {
        [$companyA, $branchA, $userA] = $this->context();
        [$companyB, $branchB] = $this->pair('Empresa B');
        $userB = $this->userFor($companyB, $branchB);
        $ca = Customer::create(['company_id' => $companyA->id, 'customer_type' => 'individual', 'name' => 'A', 'is_active' => true]);
        $cb = Customer::create(['company_id' => $companyB->id, 'customer_type' => 'individual', 'name' => 'B', 'is_active' => true]);
        $ca->refresh(); $cb->refresh();

        $respA = $this->actingAs($userA)->withSession(['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id])
            ->getJson(route('pos.customers.search', ['q' => $ca->public_code]));
        $respA->assertOk();
        $this->assertTrue(collect($respA->json())->contains(fn ($c) => $c['id'] === $ca->id));
        $this->assertFalse(collect($respA->json())->contains(fn ($c) => $c['id'] === $cb->id));

        $respB = $this->actingAs($userB)->withSession(['active_company_id' => $companyB->id, 'active_branch_id' => $branchB->id])
            ->getJson(route('pos.customers.search', ['q' => $ca->public_code]));
        $respB->assertOk();
        // B no debe ver código de A aunque coincida el texto
        $this->assertFalse(collect($respB->json())->contains(fn ($c) => $c['id'] === $ca->id));
    }

    public function test_search_customers_still_finds_by_name_and_phone_after_public_code(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'NombreUnico123', 'phone' => '88889999', 'is_active' => true]);
        foreach (['NombreUnico123', '88889999'] as $q) {
            $resp = $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
                ->getJson(route('pos.customers.search', ['q' => $q]));
            $resp->assertOk();
            $this->assertTrue(collect($resp->json())->contains(fn ($c) => $c['id'] === $customer->id));
        }
    }

    private function context(): array
    {
        $company = Company::create(['trade_name' => 'Empresa ' . uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true, 'default_phone_country_code' => '+506']);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P' . uniqid(), 'is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol ' . uniqid(), 'is_active' => true]);
        foreach (['pos.acceder', 'clientes.ver'] as $name) {
            $perm = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Test', 'is_active' => true]);
            $role->permissions()->attach($perm);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        return [$company, $branch, $user];
    }

    private function pair(string $name): array
    {
        $company = Company::create(['trade_name' => $name . ' ' . uniqid(), 'legal_name' => $name, 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P' . uniqid(), 'is_active' => true]);
        return [$company, $branch];
    }

    private function userFor(Company $company, Branch $branch): User
    {
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol ' . uniqid(), 'is_active' => true]);
        foreach (['pos.acceder'] as $name) {
            $perm = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Test', 'is_active' => true]);
            $role->permissions()->attach($perm);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        return $user;
    }
}
