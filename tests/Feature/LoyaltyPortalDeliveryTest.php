<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyPortalCredential;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoyaltyPortalDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_clientes_store_returns_delivery_with_url_username_and_temporary_password(): void
    {
        [$company, $branch, $user] = $this->staffContext();
        $response = $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->post(route('clientes.store'), $this->clientePayload(['phone' => '88881234', 'email' => 'entrega@example.com', 'create_portal_access' => '1']));
        $response->assertRedirect(route('clientes.index'));
        $pa = null;
        $response->assertSessionHas('portal_access', function ($val) use (&$pa) { $pa = $val; return true; });
        $this->assertTrue($pa['created']);
        $this->assertSame('88881234', $pa['username']);
        $this->assertNotEmpty($pa['password']);
        $this->assertStringContainsString((string) $company->id, $pa['portal_url']);
        $this->assertStringContainsString('portal-clientes', $pa['portal_url']);
        $this->assertNotEmpty($pa['copy_text']);
        $this->assertStringContainsString($pa['username'], $pa['copy_text']);
        $this->assertStringContainsString($pa['password'], $pa['copy_text']);
        $this->assertStringContainsString($pa['portal_url'], $pa['copy_text']);
        $this->assertNotEmpty($pa['whatsapp_url']);
        $this->assertStringContainsString('wa.me', $pa['whatsapp_url']);
        $this->assertStringContainsString($pa['portal_url'], $pa['message']);
    }

    public function test_pos_quick_returns_delivery_with_url_and_whatsapp(): void
    {
        [$company, $branch, $user] = $this->staffContext(true);
        $response = $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->postJson(route('pos.customers.quick-store'), ['name' => 'Entrega POS', 'phone' => '8888-7777', 'create_portal_access' => true]);
        $response->assertCreated()->assertJsonPath('portal_access.created', true);
        $pa = $response->json('portal_access');
        $this->assertSame('88887777', $pa['username']);
        $this->assertNotEmpty($pa['password']);
        $this->assertStringContainsString((string) $company->id, $pa['portal_url']);
        $this->assertStringContainsString('wa.me', $pa['whatsapp_url']);
        $this->assertStringContainsString($pa['username'], $pa['copy_text']);
        $this->assertStringContainsString($pa['password'], $pa['copy_text']);
        $this->assertSame('88887777', substr($pa['whatsapp_phone'], -8));
    }

    public function test_without_create_portal_access_no_credentials(): void
    {
        [$company, $branch, $user] = $this->staffContext();
        $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->post(route('clientes.store'), $this->clientePayload(['phone' => '88880001']))
            ->assertRedirect(route('clientes.index'))
            ->assertSessionMissing('portal_access');

        [$company2, $branch2, $user2] = $this->staffContext(true);
        $response = $this->actingAs($user2)->withSession(['active_company_id' => $company2->id, 'active_branch_id' => $branch2->id])
            ->postJson(route('pos.customers.quick-store'), ['name' => 'Sin Acceso', 'phone' => '88880002']);
        $response->assertCreated();
        $this->assertTrue($response->json('portal_access') === null || ($response->json('portal_access')['created'] ?? false) === false);
    }

    public function test_temporary_password_only_in_initial_response(): void
    {
        [$company, $branch, $user] = $this->staffContext();
        $response = $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->post(route('clientes.store'), $this->clientePayload(['phone' => '88880003', 'create_portal_access' => '1']));
        $pa = null;
        $response->assertSessionHas('portal_access', function ($val) use (&$pa) { $pa = $val; return true; });
        $this->assertNotEmpty($pa['password']);
        // La siguiente petición (redirect target) sí muestra la entrega una vez
        $next = $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('clientes.index'));
        $next->assertOk()->assertSee($pa['password']);
        // Pero la subsiguiente ya no debe mostrarla (flash solo una vez)
        $next2 = $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('clientes.index'));
        $next2->assertOk()->assertDontSee($pa['password']);
        // Y la contraseña en BD está hasheada, no en texto plano
        $customer = Customer::where('company_id', $company->id)->where('phone', '88880003')->firstOrFail();
        $cred = LoyaltyPortalCredential::where('customer_id', $customer->id)->firstOrFail();
        $this->assertTrue(Hash::check($pa['password'], $cred->password));
        $this->assertNotSame($pa['password'], $cred->password);
    }

    public function test_whatsapp_uses_normalized_phone(): void
    {
        [$company, $branch, $user] = $this->staffContext();
        $response = $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->post(route('clientes.store'), $this->clientePayload(['phone' => ' 8888-9999 ', 'phone_country_code' => '+506', 'create_portal_access' => '1']));
        $pa = null;
        $response->assertSessionHas('portal_access', function ($val) use (&$pa) { $pa = $val; return true; });
        $this->assertStringContainsString('50688889999', $pa['whatsapp_url']);
        $this->assertStringContainsString('50688889999', $pa['whatsapp_phone']);
        $this->assertStringNotContainsString(' ', $pa['whatsapp_phone']);

        // POS con teléfono con guiones
        [$company2, $branch2, $user2] = $this->staffContext(true);
        $res2 = $this->actingAs($user2)->withSession(['active_company_id' => $company2->id, 'active_branch_id' => $branch2->id])
            ->postJson(route('pos.customers.quick-store'), ['name' => 'Wpp Norm', 'phone' => '88-88 1234', 'create_portal_access' => true]);
        $pa2 = $res2->json('portal_access');
        $this->assertStringContainsString('88881234', $pa2['whatsapp_phone']);
    }

    public function test_isolation_between_companies_portal_url(): void
    {
        [$companyA, $branchA, $userA] = $this->staffContext();
        [$companyB, $branchB] = $this->companyBranch('Empresa B');
        $userB = $this->userForCompany($companyB, $branchB);

        $respA = $this->actingAs($userA)->withSession(['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id])
            ->post(route('clientes.store'), $this->clientePayload(['phone' => '44441111', 'create_portal_access' => '1']));
        $paA = null;
        $respA->assertSessionHas('portal_access', function ($val) use (&$paA) { $paA = $val; return true; });
        $respB = $this->actingAs($userB)->withSession(['active_company_id' => $companyB->id, 'active_branch_id' => $branchB->id])
            ->post(route('clientes.store'), $this->clientePayload(['phone' => '44441111', 'create_portal_access' => '1']));
        $paB = null;
        $respB->assertSessionHas('portal_access', function ($val) use (&$paB) { $paB = $val; return true; });

        $this->assertNotSame($paA['portal_url'], $paB['portal_url']);
        $this->assertStringContainsString((string) $companyA->id, $paA['portal_url']);
        $this->assertStringContainsString((string) $companyB->id, $paB['portal_url']);
        $this->assertNotSame($paA['password'], $paB['password']);
    }

    public function test_password_not_persisted_plain(): void
    {
        [$company, $branch, $user] = $this->staffContext(true);
        $resp = $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->postJson(route('pos.customers.quick-store'), ['name' => 'No Plain', 'phone' => '77770001', 'create_portal_access' => true]);
        $plain = $resp->json('portal_access.password');
        $customer = Customer::where('company_id', $company->id)->where('name', 'No Plain')->firstOrFail();
        $cred = LoyaltyPortalCredential::where('customer_id', $customer->id)->firstOrFail();
        $this->assertNotSame($plain, $cred->password);
        $this->assertTrue(Hash::check($plain, $cred->password));
        $this->assertDatabaseMissing('loyalty_portal_credentials', ['customer_id' => $customer->id, 'password' => $plain]);
    }

    private function clientePayload(array $overrides = []): array
    {
        return array_merge([
            'customer_type' => 'individual',
            'name' => 'Cliente ' . uniqid(),
            'identification' => uniqid('ID-'),
            'phone' => '88888888',
            'phone_country_code' => '+506',
            'email' => 'test' . uniqid() . '@example.com',
            'credit_limit' => 0,
            'price_level' => 'normal',
            'is_active' => '1',
        ], $overrides);
    }

    private function staffContext(bool $withPos = false): array
    {
        $company = Company::create(['trade_name' => 'Empresa ' . uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true, 'default_phone_country_code' => '+506']);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'PRI' . uniqid(), 'is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Admin ' . uniqid(), 'is_active' => true]);
        $perms = ['clientes.crear', 'clientes.ver'];
        if ($withPos) $perms[] = 'pos.acceder';
        foreach ($perms as $name) {
            $perm = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Test', 'is_active' => true]);
            $role->permissions()->attach($perm);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        return [$company, $branch, $user];
    }

    private function companyBranch(string $name): array
    {
        $company = Company::create(['trade_name' => $name . ' ' . uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true, 'default_phone_country_code' => '+506']);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'PRI' . uniqid(), 'is_active' => true]);
        return [$company, $branch];
    }

    private function userForCompany(Company $company, Branch $branch): User
    {
        $role = Role::create(['company_id' => $company->id, 'name' => 'Admin ' . uniqid(), 'is_active' => true]);
        foreach (['clientes.crear', 'clientes.ver'] as $name) {
            $perm = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Test', 'is_active' => true]);
            $role->permissions()->attach($perm);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        return $user;
    }
}
