<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyPortalAccess;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BeautyOSPortalAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_token_resolves_to_beautyos_portal_when_customer_has_access(): void
    {
        [$company, $branch] = $this->companyContext('BeautyOS Portal');
        $staff = $this->user($company, $branch, true, 'fidelidad.portal');
        $customer = $this->customer($company, 'CLIENTE-BEAUTYOS', [
            'identification' => '1122334455',
            'email' => 'cliente.beautyos@correo.com',
            'phone' => '88887777',
        ]);

        $result = $this->generateSharedAccess($staff, $company, $branch, $customer);

        $this->app['auth']->forgetGuards();
        $this->get(route('beauty.portal.access', ['token' => $result['token']]))
            ->assertOk()
            ->assertSee($customer->name)
            ->assertSee($company->trade_name)
            ->assertSee('BeautyOS Portal - Acceso compartido con Fidelidad');
    }

    public function test_invalid_shared_token_cannot_access_beautyos(): void
    {
        [$company, $branch] = $this->companyContext('BeautyOS Portal');
        $staff = $this->user($company, $branch, true, 'fidelidad.portal');
        $customer = $this->customer($company, 'CLIENTE-TEST');

        $this->generateSharedAccess($staff, $company, $branch, $customer);

        $this->app['auth']->forgetGuards();
        $this->get(route('beauty.portal.access', ['token' => str_repeat('x', 60)]))
            ->assertNotFound();
        $this->get(route('beauty.portal.access', ['token' => 'invalid']))
            ->assertNotFound();
    }

    public function test_revoked_shared_token_cannot_access_beautyos(): void
    {
        [$company, $branch] = $this->companyContext('BeautyOS Portal');
        $staff = $this->user($company, $branch, true, 'fidelidad.portal');
        $customer = $this->customer($company, 'CLIENTE-REVOCAR');

        $result = $this->generateSharedAccess($staff, $company, $branch, $customer);

        $this->app['auth']->forgetGuards();
        $this->get(route('beauty.portal.access', ['token' => $result['token']]))
            ->assertOk();

        $this->actingAs($staff)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->patch(route('loyalty.accesses.revoke', $customer))
            ->assertRedirect();

        $this->get(route('beauty.portal.access', ['token' => $result['token']]))
            ->assertNotFound();
    }

    public function test_regenerated_shared_token_invalidates_old_one_for_beautyos(): void
    {
        [$company, $branch] = $this->companyContext('BeautyOS Portal');
        $staff = $this->user($company, $branch, true, 'fidelidad.portal');
        $customer = $this->customer($company, 'CLIENTE-REGENERAR');

        $first = $this->generateSharedAccess($staff, $company, $branch, $customer);

        $this->app['auth']->forgetGuards();
        $this->get(route('beauty.portal.access', ['token' => $first['token']]))
            ->assertOk();

        $second = $this->generateSharedAccess($staff, $company, $branch, $customer);

        $this->get(route('beauty.portal.access', ['token' => $first['token']]))
            ->assertNotFound();
        $this->get(route('beauty.portal.access', ['token' => $second['token']]))
            ->assertOk();
    }

    public function test_cross_company_shared_token_cannot_access_beautyos(): void
    {
        [$companyA, $branchA] = $this->companyContext('Empresa A');
        [$companyB, $branchB] = $this->companyContext('Empresa B');
        $staffB = $this->user($companyB, $branchB, true, 'fidelidad.portal');
        $customerB = $this->customer($companyB, 'CLIENTE-B');

        $result = $this->generateSharedAccess($staffB, $companyB, $branchB, $customerB);

        $this->app['auth']->forgetGuards();
        $this->get(route('beauty.portal.access', ['token' => $result['token']]))
            ->assertOk()
            ->assertSee($companyB->trade_name)
            ->assertDontSee($companyA->trade_name);
    }

    public function test_loyalty_portal_still_works_independently(): void
    {
        [$company, $branch] = $this->companyContext('Fidelidad Independiente');
        $staff = $this->user($company, $branch, true, 'fidelidad.portal');
        $customer = $this->customer($company, 'CLIENTE-FIDELIDAD');

        $result = $this->generateSharedAccess($staff, $company, $branch, $customer);

        $this->app['auth']->forgetGuards();
        $this->get(route('loyalty.portal.access', ['token' => $result['token']]))
            ->assertOk()
            ->assertSee('Hecho con MVS Commerce');
    }

    public function test_shared_token_only_stored_once_as_hash(): void
    {
        [$company, $branch] = $this->companyContext('Hash Only');
        $staff = $this->user($company, $branch, true, 'fidelidad.portal');
        $customer = $this->customer($company, 'CLIENTE-HASH');

        $result = $this->generateSharedAccess($staff, $company, $branch, $customer);

        $access = LoyaltyPortalAccess::query()->where('company_id', $company->id)->whereNull('revoked_at')->sole();
        $this->assertSame(hash('sha256', $result['token']), $access->token_hash);
        $this->assertNotSame($result['token'], $access->token_hash);
        $this->assertDatabaseMissing('loyalty_portal_accesses', ['token_hash' => $result['token']]);
    }

    private function generateSharedAccess(User $user, Company $company, Branch $branch, Customer $customer): array
    {
        $response = $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->post(route('loyalty.accesses.store'), ['customer_id' => $customer->id]);

        $response->assertRedirect();
        $url = $response->getSession()->get('portal_url');
        $this->assertIsString($url);

        return ['url' => $url, 'token' => substr($url, (int) strrpos($url, '/') + 1)];
    }

    private function customer(Company $company, string $name, array $extra = []): Customer
    {
        return Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => $name, 'credit_limit' => 0, 'is_active' => true] + $extra);
    }

    private function companyContext(string $name): array
    {
        $company = Company::create(['trade_name' => $name.' '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);

        return [$company, $this->branch($company, 'Principal')];
    }

    private function branch(Company $company, string $name): Branch
    {
        return Branch::create(['company_id' => $company->id, 'name' => $name, 'code' => strtoupper(substr(uniqid(), -6)), 'is_active' => true]);
    }

    private function user(Company $company, Branch $branch, bool $authorized, string $permissionName = 'fidelidad.portal'): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        if ($authorized) {
            $permission = Permission::firstOrCreate(['name' => $permissionName], ['label' => $permissionName, 'module' => 'Fidelidad', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return $user;
    }
}
