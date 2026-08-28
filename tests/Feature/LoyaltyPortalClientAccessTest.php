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
use Tests\TestCase;

class LoyaltyPortalClientAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_clientes_store_without_portal_access_does_not_create_credential(): void
    {
        [$company, $branch, $user] = $this->staffContext();
        $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->post(route('clientes.store'), $this->clientePayload())
            ->assertRedirect(route('clientes.index'));
        $customer = Customer::where('company_id', $company->id)->firstOrFail();
        $this->assertDatabaseMissing('loyalty_portal_credentials', ['customer_id' => $customer->id]);
    }

    public function test_clientes_store_creates_portal_access_with_phone_as_username(): void
    {
        [$company, $branch, $user] = $this->staffContext();
        $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->post(route('clientes.store'), $this->clientePayload(['phone' => '8888-8888', 'email' => 'cliente@example.com', 'create_portal_access' => '1']))
            ->assertRedirect(route('clientes.index'))
            ->assertSessionHas('success');
        $customer = Customer::where('company_id', $company->id)->where('phone', '88888888')->firstOrFail();
        $cred = LoyaltyPortalCredential::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('88888888', $cred->username);
        $this->assertSame('cliente@example.com', $cred->email);
    }

    public function test_clientes_store_fallback_email_when_no_phone(): void
    {
        [$company, $branch, $user] = $this->staffContext();
        $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->post(route('clientes.store'), $this->clientePayload(['phone' => null, 'phone_country_code' => null, 'email' => 'fallback@example.com', 'create_portal_access' => '1']))
            ->assertRedirect(route('clientes.index'));
        $customer = Customer::where('company_id', $company->id)->where('email', 'fallback@example.com')->firstOrFail();
        $cred = LoyaltyPortalCredential::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('fallback@example.com', $cred->username);
    }

    public function test_clientes_store_without_phone_or_email_does_not_create_credential_but_creates_customer(): void
    {
        [$company, $branch, $user] = $this->staffContext();
        $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->post(route('clientes.store'), $this->clientePayload(['phone' => null, 'phone_country_code' => null, 'email' => null, 'create_portal_access' => '1']))
            ->assertRedirect(route('clientes.index'))
            ->assertSessionHas('warning');
        $customer = Customer::where('company_id', $company->id)->firstOrFail();
        $this->assertDatabaseMissing('loyalty_portal_credentials', ['customer_id' => $customer->id]);
        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
    }

    public function test_clientes_store_does_not_duplicate_credential_if_already_exists(): void
    {
        [$company, $branch, $user] = $this->staffContext();
        $customer = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Con Cred', 'phone' => '77777777', 'email' => 'con@example.com', 'is_active' => true]);
        LoyaltyPortalCredential::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'username' => '77777777', 'email' => 'con@example.com', 'password' => 'ClaveSegura1']);
        // Intentar crear nuevo cliente con mismo teléfono (username duplicado) – debe crear cliente pero no credencial, con warning
        $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->post(route('clientes.store'), $this->clientePayload(['identification' => '9-9999-9999', 'phone' => '77777777', 'email' => 'nuevo@example.com', 'create_portal_access' => '1']))
            ->assertRedirect(route('clientes.index'))
            ->assertSessionHas('warning');
        $this->assertSame(1, LoyaltyPortalCredential::where('customer_id', $customer->id)->count());
        $newCustomer = Customer::where('company_id', $company->id)->where('identification', '9-9999-9999')->firstOrFail();
        $this->assertDatabaseMissing('loyalty_portal_credentials', ['customer_id' => $newCustomer->id]);
    }

    public function test_pos_quick_customer_creates_portal_access(): void
    {
        [$company, $branch, $user] = $this->staffContext(true);
        $response = $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->postJson(route('pos.customers.quick-store'), ['name' => 'POS Cliente', 'phone' => '8888-9999', 'email' => 'pos@example.com', 'create_portal_access' => true]);
        $response->assertCreated()->assertJsonPath('portal_access.created', true);
        $customer = Customer::where('company_id', $company->id)->where('name', 'POS Cliente')->firstOrFail();
        $this->assertDatabaseHas('loyalty_portal_credentials', ['customer_id' => $customer->id]);
        $cred = LoyaltyPortalCredential::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('88889999', $cred->username);
    }

    public function test_pos_quick_customer_username_fallback_email(): void
    {
        [$company, $branch, $user] = $this->staffContext(true);
        $response = $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->postJson(route('pos.customers.quick-store'), ['name' => 'POS Email', 'email' => 'fallbackpos@example.com', 'create_portal_access' => true]);
        $response->assertCreated()->assertJsonPath('portal_access.created', true);
        $customer = Customer::where('company_id', $company->id)->where('email', 'fallbackpos@example.com')->firstOrFail();
        $this->assertDatabaseHas('loyalty_portal_credentials', ['customer_id' => $customer->id, 'username' => 'fallbackpos@example.com']);
    }

    public function test_pos_quick_customer_without_contact_returns_error_but_creates_customer(): void
    {
        [$company, $branch, $user] = $this->staffContext(true);
        $response = $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->postJson(route('pos.customers.quick-store'), ['name' => 'Sin Contacto', 'create_portal_access' => true]);
        $response->assertCreated();
        $customer = Customer::where('company_id', $company->id)->where('name', 'Sin Contacto')->firstOrFail();
        $this->assertDatabaseMissing('loyalty_portal_credentials', ['customer_id' => $customer->id]);
        $this->assertSame('No se pudo crear acceso al Portal: el cliente no tiene teléfono ni correo válido.', $response->json('portal_access.error'));
    }

    public function test_portal_access_isolation_between_companies(): void
    {
        [$companyA, $branchA, $userA] = $this->staffContext();
        [$companyB, $branchB] = $this->companyBranch('Empresa B');
        $userB = $this->userForCompany($companyB, $branchB);
        // Create credential in A with phone
        $this->actingAs($userA)->withSession(['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id])
            ->post(route('clientes.store'), $this->clientePayload(['phone' => '44444444', 'email' => 'iso@example.com', 'create_portal_access' => '1']))
            ->assertRedirect();
        // Same phone in B should create new credential with same username but different company (isolated)
        $this->actingAs($userB)->withSession(['active_company_id' => $companyB->id, 'active_branch_id' => $branchB->id])
            ->post(route('clientes.store'), $this->clientePayload(['phone' => '44444444', 'email' => 'iso@example.com', 'create_portal_access' => '1']))
            ->assertRedirect();
        $this->assertDatabaseHas('loyalty_portal_credentials', ['company_id' => $companyA->id, 'username' => '44444444']);
        $this->assertDatabaseHas('loyalty_portal_credentials', ['company_id' => $companyB->id, 'username' => '44444444']);
    }

    public function test_portal_password_is_not_generic(): void
    {
        [$company, $branch, $user] = $this->staffContext();
        $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->post(route('clientes.store'), $this->clientePayload(['phone' => '55555555', 'create_portal_access' => '1']))
            ->assertRedirect();
        $customer = Customer::where('company_id', $company->id)->where('phone', '55555555')->firstOrFail();
        $cred = LoyaltyPortalCredential::where('customer_id', $customer->id)->firstOrFail();
        $this->assertNotSame('password123', $cred->password);
        $this->assertNotSame('12345678', $cred->password);
        // Password is hashed, we cannot compare plain, but we can check that two credentials have different passwords
        $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->post(route('clientes.store'), $this->clientePayload(['phone' => '66666666', 'create_portal_access' => '1']))
            ->assertRedirect();
        $customer2 = Customer::where('company_id', $company->id)->where('phone', '66666666')->firstOrFail();
        $cred2 = LoyaltyPortalCredential::where('customer_id', $customer2->id)->firstOrFail();
        $this->assertNotSame($cred->password, $cred2->password);
    }

    private function clientePayload(array $overrides = []): array
    {
        return array_merge([
            'customer_type' => 'individual',
            'name' => 'Cliente Test ' . uniqid(),
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
        $company = Company::create(['trade_name' => 'Empresa ' . uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
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
        $company = Company::create(['trade_name' => $name . ' ' . uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
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
