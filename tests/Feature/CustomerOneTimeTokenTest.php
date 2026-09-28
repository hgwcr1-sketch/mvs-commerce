<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CustomerOneTimeTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerOneTimeTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_creates_pin_and_qr_single_use(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'PIN Test', 'is_active' => true]);
        $customer->refresh();

        $response = $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->postJson(route('clientes.pin.generate', $customer));
        $response->assertOk()->assertJsonStructure(['public_code', 'pin', 'expires_at', 'qrSvg']);
        $pin = $response->json('pin');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $pin);
        $this->assertStringContainsString('<svg', $response->json('qrSvg'));
        $this->assertDatabaseHas('customer_one_time_tokens', ['customer_id' => $customer->id, 'company_id' => $company->id]);

        // Verify correct PIN succeeds
        $verify = $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->postJson(route('clientes.pin.verify', $customer), ['pin' => $pin]);
        $verify->assertOk()->assertJson(['verified' => true]);

        // Second use fails (single-use)
        $verify2 = $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->postJson(route('clientes.pin.verify', $customer), ['pin' => $pin]);
        $verify2->assertStatus(422);
    }

    public function test_verify_fails_with_wrong_or_expired(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'PIN Exp', 'is_active' => true]);
        $customer->refresh();
        $service = app(CustomerOneTimeTokenService::class);
        $result = $service->generate($customer, $company, 'redeem', 5);
        $pin = $result['plain'];

        // Wrong PIN
        $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->postJson(route('clientes.pin.verify', $customer), ['pin' => '000000'])
            ->assertStatus(422);

        // Expired
        $token = $result['token'];
        $token->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->postJson(route('clientes.pin.verify', $customer), ['pin' => $pin])
            ->assertStatus(422);
    }

    public function test_isolation_between_companies(): void
    {
        [$companyA, $branchA, $userA] = $this->context();
        [$companyB, $branchB] = $this->pair('Empresa B');
        $userB = $this->userFor($companyB, $branchB);
        $ca = Customer::create(['company_id' => $companyA->id, 'customer_type' => 'individual', 'name' => 'A', 'is_active' => true]);
        $cb = Customer::create(['company_id' => $companyB->id, 'customer_type' => 'individual', 'name' => 'B', 'is_active' => true]);
        $ca->refresh(); $cb->refresh();
        $pinA = $this->actingAs($userA)->withSession(['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id])
            ->postJson(route('clientes.pin.generate', $ca))->json('pin');

        // B cannot verify PIN of A
        $this->actingAs($userB)->withSession(['active_company_id' => $companyB->id, 'active_branch_id' => $branchB->id])
            ->postJson(route('clientes.pin.verify', $cb), ['pin' => $pinA])
            ->assertStatus(422);

        // B's own PIN works
        $pinB = $this->actingAs($userB)->withSession(['active_company_id' => $companyB->id, 'active_branch_id' => $branchB->id])
            ->postJson(route('clientes.pin.generate', $cb))->json('pin');
        $this->actingAs($userB)->withSession(['active_company_id' => $companyB->id, 'active_branch_id' => $branchB->id])
            ->postJson(route('clientes.pin.verify', $cb), ['pin' => $pinB])->assertOk();
    }

    public function test_static_qr_not_trusted_for_redeem(): void
    {
        $service = app(CustomerOneTimeTokenService::class);
        $this->assertFalse($service->isStaticQrTrustedForRedeem());
        // Ensure public_code alone cannot verify
        [$company, $branch, $user] = $this->context();
        $customer = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Static', 'is_active' => true]);
        $customer->refresh();
        $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->postJson(route('clientes.pin.verify', $customer), ['pin' => $customer->public_code])
            ->assertStatus(422);
    }

    private function context(): array
    {
        $company = Company::create(['trade_name' => 'Empresa ' . uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true, 'default_phone_country_code' => '+506']);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P' . uniqid(), 'is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol ' . uniqid(), 'is_active' => true]);
        foreach (['clientes.ver', 'clientes.crear'] as $name) {
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
        foreach (['clientes.ver'] as $name) {
            $perm = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Test', 'is_active' => true]);
            $role->permissions()->attach($perm);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        return $user;
    }
}
