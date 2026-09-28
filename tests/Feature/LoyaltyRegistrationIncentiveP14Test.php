<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyRegistrationIncentive;
use App\Models\LoyaltyRegistrationIncentiveClaim;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Loyalty\LoyaltyRegistrationIncentiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LoyaltyRegistrationIncentiveP14Test extends TestCase
{
    use RefreshDatabase;

    public function test_incentive_disabled_by_default_no_points(): void
    {
        $company = $this->company();
        $this->post(route('loyalty.customer.register.store', $company), $this->registerPayload(['phone' => '88880001']))->assertRedirect();
        $customer = Customer::where('company_id', $company->id)->where('phone', '88880001')->firstOrFail();
        $account = LoyaltyAccount::where('company_id', $company->id)->where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('0.0000', $account->balance);
        $this->assertDatabaseMissing('loyalty_registration_incentive_claims', ['customer_id' => $customer->id]);
    }

    public function test_enabled_awards_once_and_never_duplicates(): void
    {
        $company = $this->company();
        app(LoyaltyRegistrationIncentiveService::class)->toggle($company, true);

        // First registration
        $this->post(route('loyalty.customer.register.store', $company), $this->registerPayload(['phone' => '88880002', 'identification' => 'ID-ONE']))->assertRedirect();
        $customer = Customer::where('company_id', $company->id)->where('phone', '88880002')->firstOrFail();
        $account = LoyaltyAccount::where('company_id', $company->id)->where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('10.0000', $account->balance);
        $this->assertDatabaseHas('loyalty_registration_incentive_claims', ['company_id' => $company->id, 'customer_id' => $customer->id]);

        // Intentar registrar de nuevo mismo cliente (dedup P03 reutiliza cliente existente, no debe duplicar incentivo)
        $this->post(route('loyalty.customer.register.store', $company), $this->registerPayload(['phone' => '88880002', 'identification' => 'ID-ONE', 'username' => 'otrouser', 'email' => 'otro@example.com']))->assertSessionHasErrors('username'); // ya tiene credencial, bloquea
        // Forzar intento directo via service (simula reintento)
        $service = app(LoyaltyRegistrationIncentiveService::class);
        $second = $service->tryAwardForRegistration($customer, $company);
        $this->assertNull($second);
        $account->refresh();
        $this->assertSame('10.0000', $account->balance);
        $this->assertSame(1, LoyaltyRegistrationIncentiveClaim::where('customer_id', $customer->id)->count());
    }

    public function test_toggle_off_stops_award(): void
    {
        $company = $this->company();
        $service = app(LoyaltyRegistrationIncentiveService::class);
        $service->toggle($company, true);
        $this->post(route('loyalty.customer.register.store', $company), $this->registerPayload(['phone' => '88880003']))->assertRedirect();
        $customer1 = Customer::where('company_id', $company->id)->where('phone', '88880003')->firstOrFail();
        $this->assertDatabaseHas('loyalty_registration_incentive_claims', ['customer_id' => $customer1->id]);

        $service->toggle($company, false);
        $this->post(route('loyalty.customer.register.store', $company), $this->registerPayload(['phone' => '88880004']))->assertRedirect();
        $customer2 = Customer::where('company_id', $company->id)->where('phone', '88880004')->firstOrFail();
        $account2 = LoyaltyAccount::where('company_id', $company->id)->where('customer_id', $customer2->id)->firstOrFail();
        $this->assertSame('0.0000', $account2->balance);
        $this->assertDatabaseMissing('loyalty_registration_incentive_claims', ['customer_id' => $customer2->id]);
    }

    public function test_isolation_per_company(): void
    {
        $companyA = $this->company('Empresa A');
        $companyB = $this->company('Empresa B');
        app(LoyaltyRegistrationIncentiveService::class)->toggle($companyA, true);
        // B permanece deshabilitado

        $this->post(route('loyalty.customer.register.store', $companyA), $this->registerPayload(['phone' => '44440001']))->assertRedirect();
        $ca = Customer::where('company_id', $companyA->id)->where('phone', '44440001')->firstOrFail();
        $this->assertDatabaseHas('loyalty_registration_incentive_claims', ['company_id' => $companyA->id, 'customer_id' => $ca->id]);

        $this->post(route('loyalty.customer.register.store', $companyB), $this->registerPayload(['phone' => '44440001']))->assertRedirect();
        $cb = Customer::where('company_id', $companyB->id)->where('phone', '44440001')->firstOrFail();
        $this->assertDatabaseMissing('loyalty_registration_incentive_claims', ['company_id' => $companyB->id, 'customer_id' => $cb->id]);
    }

    public function test_reuses_f09_new_customer_motor(): void
    {
        $company = $this->company();
        app(LoyaltyRegistrationIncentiveService::class)->toggle($company, true);
        $this->post(route('loyalty.customer.register.store', $company), $this->registerPayload(['phone' => '88880005']))->assertRedirect();
        $customer = Customer::where('company_id', $company->id)->where('phone', '88880005')->firstOrFail();
        $this->assertDatabaseHas('loyalty_movements', ['company_id' => $company->id, 'customer_id' => $customer->id, 'type' => 'new_customer']);

        $claim = LoyaltyRegistrationIncentiveClaim::query()->where('customer_id', $customer->id)->firstOrFail();
        $this->assertNotNull($claim->loyalty_movement_id);
        $this->assertSame($claim->loyalty_movement_id, $claim->loyaltyMovement->id);
        $this->assertSame('10.0000', (string) $claim->awarded_points);
        $this->assertSame('P14', $claim->loyaltyMovement->metadata['incentive']);
    }

    public function test_authorized_user_can_toggle_only_the_active_company_setting(): void
    {
        [$companyA, $branchA, $userA] = $this->staffContext(['fidelidad.configuracion']);
        $companyB = $this->company('Empresa B');
        LoyaltyRegistrationIncentive::create(['company_id' => $companyB->id, 'is_enabled' => false]);

        $this->actingAs($userA)
            ->withSession(['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id])
            ->put(route('loyalty.registration-incentive.update'), ['is_enabled' => '1', 'benefit_type' => 'points', 'benefit_value' => '10'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertTrue(LoyaltyRegistrationIncentive::query()->where('company_id', $companyA->id)->firstOrFail()->is_enabled);
        $this->assertFalse(LoyaltyRegistrationIncentive::query()->where('company_id', $companyB->id)->firstOrFail()->is_enabled);
    }

    public function test_user_without_permission_cannot_toggle_incentive(): void
    {
        [$company, $branch, $user] = $this->staffContext(['fidelidad.ver']);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->put(route('loyalty.registration-incentive.update'), ['is_enabled' => '1', 'benefit_type' => 'points', 'benefit_value' => '10'])
            ->assertForbidden();

        $this->assertDatabaseMissing('loyalty_registration_incentives', [
            'company_id' => $company->id,
            'is_enabled' => true,
        ]);
    }

    public function test_service_rejects_customer_from_another_company(): void
    {
        $companyA = $this->company('Empresa A');
        $companyB = $this->company('Empresa B');
        $customer = Customer::create(['company_id' => $companyB->id, 'customer_type' => 'individual', 'name' => 'Cliente ajeno', 'is_active' => true]);
        app(LoyaltyRegistrationIncentiveService::class)->toggle($companyA, true);

        $this->expectException(ValidationException::class);
        app(LoyaltyRegistrationIncentiveService::class)->tryAwardForRegistration($customer, $companyA);
    }

    private function company(?string $name = null): Company
    {
        return Company::create(['trade_name' => $name ?? 'Empresa '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
    }

    private function registerPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Cliente '.uniqid(),
            'phone' => '88888888',
            'email' => 'test'.uniqid().'@example.com',
            'username' => 'user'.uniqid(),
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
        ], $overrides);
    }

    private function staffContext(array $permissions): array
    {
        $company = $this->company();
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => strtoupper(substr(uniqid(), -6)), 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Test', 'is_active' => true]);
            $role->permissions()->attach($permission->id);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return [$company, $branch, $user];
    }
}
