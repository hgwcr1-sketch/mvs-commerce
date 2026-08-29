<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CustomerPublicCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerPublicCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_code_generated_on_customer_creation_via_clientes(): void
    {
        [$company, $branch, $user] = $this->staffContext();
        $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->post(route('clientes.store'), $this->payload(['identification' => '1-2345-6789', 'phone' => '88881111', 'email' => 'code1@example.com']))
            ->assertRedirect();

        $customer = Customer::where('company_id', $company->id)->where('phone', '88881111')->firstOrFail();
        $this->assertNotNull($customer->public_code);
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', $customer->public_code);
        $this->assertNotSame((string) $customer->id, $customer->public_code);
        $this->assertStringNotContainsString('88881111', $customer->public_code);
        $this->assertStringNotContainsString('23456789', $customer->public_code);
    }

    public function test_public_code_generated_on_pos_quick(): void
    {
        [$company, $branch, $user] = $this->staffContext(true);
        $response = $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->postJson(route('pos.customers.quick-store'), ['name' => 'P09A Quick', 'phone' => '88882222']);
        $response->assertCreated();
        $customer = Customer::where('company_id', $company->id)->where('name', 'P09A Quick')->firstOrFail();
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', $customer->public_code);
    }

    public function test_public_code_isolated_per_company_and_unique(): void
    {
        [$companyA, $branchA, $userA] = $this->staffContext();
        [$companyB, $branchB] = $this->pair('Empresa B');
        $userB = $this->userFor($companyB, $branchB);

        $this->actingAs($userA)->withSession(['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id])
            ->post(route('clientes.store'), $this->payload(['phone' => '44440001', 'identification' => 'ID-A1']))
            ->assertRedirect();
        $this->actingAs($userB)->withSession(['active_company_id' => $companyB->id, 'active_branch_id' => $branchB->id])
            ->post(route('clientes.store'), $this->payload(['phone' => '44440001', 'identification' => 'ID-A1']))
            ->assertRedirect();

        $ca = Customer::where('company_id', $companyA->id)->where('phone', '44440001')->firstOrFail();
        $cb = Customer::where('company_id', $companyB->id)->where('phone', '44440001')->firstOrFail();
        $this->assertNotSame($ca->public_code, $cb->public_code);
        $this->assertDatabaseHas('customers', ['id' => $ca->id, 'company_id' => $companyA->id]);
        $this->assertDatabaseHas('customers', ['id' => $cb->id, 'company_id' => $companyB->id]);
        // Uniqueness within same company
        $this->actingAs($userA)->withSession(['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id])
            ->post(route('clientes.store'), $this->payload(['phone' => '44440002', 'identification' => 'ID-A2']))
            ->assertRedirect();
        $ca2 = Customer::where('company_id', $companyA->id)->where('phone', '44440002')->firstOrFail();
        $this->assertNotSame($ca->public_code, $ca2->public_code);
    }

    public function test_public_code_does_not_expose_sensitive_data(): void
    {
        [$company, $branch, $user] = $this->staffContext();
        $payload = $this->payload(['identification' => '9-8765-4321', 'phone' => '88883333', 'email' => 'sensitive@example.com']);
        $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->post(route('clientes.store'), $payload)->assertRedirect();
        $customer = Customer::where('company_id', $company->id)->where('phone', '88883333')->firstOrFail();
        $code = $customer->public_code;
        $this->assertStringNotContainsString('8765', $code);
        $this->assertStringNotContainsString('88883333', $code);
        $this->assertStringNotContainsString('SENSITIVE', strtoupper($code));
        $this->assertFalse(app(CustomerPublicCodeService::class)->isSensitiveLeak($customer, $code));
    }

    public function test_ensure_generates_for_existing_without_code(): void
    {
        $company = Company::create(['trade_name' => 'Empresa ' . uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true, 'default_phone_country_code' => '+506']);
        $customer = new Customer(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Legacy', 'is_active' => true]);
        $customer->public_code = null;
        $customer->saveQuietly(); // bypass booted? saveQuietly still triggers but we forced null, booted will fill, so we need to simulate legacy without code by direct DB insert
        // So create via query builder to bypass model event
        \Illuminate\Support\Facades\DB::table('customers')->where('id', $customer->id)->update(['public_code' => null]);
        $customer->refresh();
        $this->assertNull($customer->public_code);
        $code = app(CustomerPublicCodeService::class)->ensure($customer);
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', $code);
        $this->assertNotNull($customer->fresh()->public_code);
    }

    private function payload(array $overrides = []): array
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
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P' . uniqid(), 'is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol ' . uniqid(), 'is_active' => true]);
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

    private function pair(string $name): array
    {
        $company = Company::create(['trade_name' => $name . ' ' . uniqid(), 'legal_name' => $name, 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true, 'default_phone_country_code' => '+506']);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P' . uniqid(), 'is_active' => true]);
        return [$company, $branch];
    }

    private function userFor(Company $company, Branch $branch): User
    {
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol ' . uniqid(), 'is_active' => true]);
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
