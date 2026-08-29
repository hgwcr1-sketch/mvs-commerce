<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyRegistrationIncentiveClaim;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Loyalty\LoyaltyRegistrationIncentiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LoyaltyRegistrationIncentiveP15Test extends TestCase
{
    use RefreshDatabase;

    public function test_points_type_uses_configured_decimal_value_and_kardex(): void
    {
        [$company, $branch, $user] = $this->staffContext();

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->put(route('loyalty.registration-incentive.update'), [
                'is_enabled' => '1',
                'benefit_type' => 'points',
                'benefit_value' => '25.1250',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $customer = $this->register($company, '71000001');
        $claim = LoyaltyRegistrationIncentiveClaim::query()->where('customer_id', $customer->id)->firstOrFail();
        $account = LoyaltyAccount::query()->where('company_id', $company->id)->where('customer_id', $customer->id)->firstOrFail();

        $this->assertSame('points', $claim->benefit_type);
        $this->assertSame('25.1250', (string) $claim->benefit_value);
        $this->assertSame('25.1250', (string) $claim->awarded_points);
        $this->assertSame('25.1250', (string) $account->balance);
        $this->assertNotNull($claim->loyalty_movement_id);
    }

    public function test_percentage_type_creates_pending_discount_claim_without_points(): void
    {
        $company = $this->company('Empresa porcentaje');
        app(LoyaltyRegistrationIncentiveService::class)->configure($company, true, 'percentage', '12.5000');

        $customer = $this->register($company, '71000002');
        $claim = LoyaltyRegistrationIncentiveClaim::query()->where('customer_id', $customer->id)->firstOrFail();

        $this->assertSame('percentage', $claim->benefit_type);
        $this->assertSame('12.5000', (string) $claim->benefit_value);
        $this->assertNull($claim->awarded_points);
        $this->assertNull($claim->discount_amount);
        $this->assertNull($claim->loyalty_movement_id);
        $account = LoyaltyAccount::query()->where('company_id', $company->id)->where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('0.0000', (string) $account->balance);
    }

    public function test_fixed_type_creates_pending_money_claim_without_points(): void
    {
        $company = $this->company('Empresa fijo');
        app(LoyaltyRegistrationIncentiveService::class)->configure($company, true, 'fixed', '1500.7500');

        $customer = $this->register($company, '71000003');
        $claim = LoyaltyRegistrationIncentiveClaim::query()->where('customer_id', $customer->id)->firstOrFail();

        $this->assertSame('fixed', $claim->benefit_type);
        $this->assertSame('1500.7500', (string) $claim->benefit_value);
        $this->assertNull($claim->awarded_points);
        $this->assertNull($claim->discount_amount);
        $this->assertNull($claim->loyalty_movement_id);
    }

    #[DataProvider('invalidConfigurations')]
    public function test_http_rejects_invalid_type_or_value(string $type, string $value, string $field): void
    {
        [$company, $branch, $user] = $this->staffContext();

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->put(route('loyalty.registration-incentive.update'), [
                'is_enabled' => '1',
                'benefit_type' => $type,
                'benefit_value' => $value,
            ])
            ->assertSessionHasErrors($field);

        $this->assertDatabaseMissing('loyalty_registration_incentives', [
            'company_id' => $company->id,
            'is_enabled' => true,
        ]);
    }

    public static function invalidConfigurations(): array
    {
        return [
            'tipo desconocido' => ['coupon', '10', 'benefit_type'],
            'puntos cero' => ['points', '0', 'benefit_value'],
            'puntos negativos' => ['points', '-1', 'benefit_value'],
            'porcentaje mayor a cien' => ['percentage', '100.0001', 'benefit_value'],
            'más de cuatro decimales' => ['fixed', '1.00001', 'benefit_value'],
            'monto fuera de DECIMAL 19,4' => ['fixed', '1000000000000000', 'benefit_value'],
        ];
    }

    public function test_percentage_boundary_of_one_hundred_is_valid(): void
    {
        $company = $this->company();
        $setting = app(LoyaltyRegistrationIncentiveService::class)->configure($company, true, 'percentage', '100');

        $this->assertSame('100.0000', (string) $setting->benefit_value);
    }

    public function test_configuration_and_claims_are_isolated_per_company(): void
    {
        $companyA = $this->company('Empresa A');
        $companyB = $this->company('Empresa B');
        $service = app(LoyaltyRegistrationIncentiveService::class);
        $service->configure($companyA, true, 'percentage', '7.2500');
        $service->configure($companyB, true, 'fixed', '2500.0000');

        $customerA = $this->register($companyA, '71000004');
        $customerB = $this->register($companyB, '71000004');
        $claimA = LoyaltyRegistrationIncentiveClaim::query()->where('company_id', $companyA->id)->where('customer_id', $customerA->id)->firstOrFail();
        $claimB = LoyaltyRegistrationIncentiveClaim::query()->where('company_id', $companyB->id)->where('customer_id', $customerB->id)->firstOrFail();

        $this->assertSame('percentage', $claimA->benefit_type);
        $this->assertSame('7.2500', (string) $claimA->benefit_value);
        $this->assertSame('fixed', $claimB->benefit_type);
        $this->assertSame('2500.0000', (string) $claimB->benefit_value);
    }

    public function test_discount_claim_is_not_duplicated_after_configuration_changes(): void
    {
        $company = $this->company();
        $service = app(LoyaltyRegistrationIncentiveService::class);
        $service->configure($company, true, 'fixed', '500.0000');
        $customer = $this->register($company, '71000005');

        $service->configure($company, true, 'percentage', '20.0000');
        $this->assertNull($service->tryAwardForRegistration($customer, $company));

        $claim = LoyaltyRegistrationIncentiveClaim::query()->where('company_id', $company->id)->where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame(1, LoyaltyRegistrationIncentiveClaim::query()->where('company_id', $company->id)->where('customer_id', $customer->id)->count());
        $this->assertSame('fixed', $claim->benefit_type);
        $this->assertSame('500.0000', (string) $claim->benefit_value);
    }

    public function test_service_rejects_invalid_configuration_without_http(): void
    {
        $this->expectException(ValidationException::class);
        app(LoyaltyRegistrationIncentiveService::class)->configure($this->company(), true, 'percentage', '100.0001');
    }

    private function register(Company $company, string $phone): Customer
    {
        $this->post(route('loyalty.customer.register.store', $company), [
            'name' => 'Cliente '.uniqid(),
            'phone' => $phone,
            'email' => 'p15'.uniqid().'@example.com',
            'username' => 'p15'.uniqid(),
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
        ])->assertRedirect();

        return Customer::query()->where('company_id', $company->id)->where('phone', $phone)->firstOrFail();
    }

    private function company(?string $name = null): Company
    {
        return Company::create(['trade_name' => $name ?? 'Empresa '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
    }

    private function staffContext(): array
    {
        $company = $this->company();
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => strtoupper(substr(uniqid(), -6)), 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        $permission = Permission::firstOrCreate(['name' => 'fidelidad.configuracion'], ['label' => 'fidelidad.configuracion', 'module' => 'Test', 'is_active' => true]);
        $role->permissions()->attach($permission->id);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return [$company, $branch, $user];
    }
}
