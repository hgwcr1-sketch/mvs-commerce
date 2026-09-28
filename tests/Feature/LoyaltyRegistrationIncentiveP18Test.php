<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Services\Loyalty\LoyaltyRegistrationIncentiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyRegistrationIncentiveP18Test extends TestCase
{
    use RefreshDatabase;

    public function test_claim_remains_once_per_customer_under_retries(): void
    {
        [$company, $customer] = $this->context();
        $service = app(LoyaltyRegistrationIncentiveService::class);
        $service->configure($company, true, 'fixed', '10');

        $this->assertNotNull($service->tryAwardForRegistration($customer, $company));
        $this->assertNull($service->tryAwardForRegistration($customer, $company));
        $this->assertDatabaseCount('loyalty_registration_incentive_claims', 1);
    }

    public function test_verified_phone_requirement_blocks_until_phone_is_verified(): void
    {
        [$company, $customer] = $this->context(['phone' => '88887777']);
        $service = app(LoyaltyRegistrationIncentiveService::class);
        $service->configure($company, true, 'fixed', '10', ['require_verified_phone' => true]);

        $this->assertNull($service->tryAwardForRegistration($customer, $company));
        $customer->update(['phone_verified_at' => now()]);
        $claim = $service->tryAwardForRegistration($customer->fresh(), $company);
        $this->assertNotNull($claim);
        $this->assertTrue($claim->required_verified_phone);
    }

    public function test_verified_email_requirement_blocks_until_email_is_verified(): void
    {
        [$company, $customer] = $this->context(['email' => 'cliente@example.test']);
        $service = app(LoyaltyRegistrationIncentiveService::class);
        $service->configure($company, true, 'fixed', '10', ['require_verified_email' => true]);

        $this->assertNull($service->tryAwardForRegistration($customer, $company));
        $customer->update(['email_verified_at' => now()]);
        $claim = $service->tryAwardForRegistration($customer->fresh(), $company);
        $this->assertNotNull($claim);
        $this->assertTrue($claim->required_verified_email);
    }

    public function test_both_requirements_must_be_met_and_snapshot_survives_reconfiguration(): void
    {
        [$company, $customer] = $this->context(['phone' => '88887777', 'email' => 'both@example.test', 'phone_verified_at' => now()]);
        $service = app(LoyaltyRegistrationIncentiveService::class);
        $service->configure($company, true, 'fixed', '10', ['require_verified_phone' => true, 'require_verified_email' => true]);

        $this->assertNull($service->tryAwardForRegistration($customer, $company));
        $customer->update(['email_verified_at' => now()]);
        $claim = $service->tryAwardForRegistration($customer->fresh(), $company);
        $service->configure($company, true, 'fixed', '10', ['require_verified_phone' => false, 'require_verified_email' => false]);

        $this->assertTrue($claim->fresh()->required_verified_phone);
        $this->assertTrue($claim->fresh()->required_verified_email);
    }

    public function test_verification_and_claims_are_isolated_by_company(): void
    {
        [$companyA, $customerA] = $this->context(['email' => 'same@example.test', 'email_verified_at' => now()]);
        [$companyB, $customerB] = $this->context(['email' => 'same@example.test']);
        $service = app(LoyaltyRegistrationIncentiveService::class);
        $service->configure($companyA, true, 'fixed', '10', ['require_verified_email' => true]);
        $service->configure($companyB, true, 'fixed', '10', ['require_verified_email' => true]);

        $this->assertNotNull($service->tryAwardForRegistration($customerA, $companyA));
        $this->assertNull($service->tryAwardForRegistration($customerB, $companyB));
        $this->assertDatabaseHas('loyalty_registration_incentive_claims', ['company_id' => $companyA->id, 'customer_id' => $customerA->id]);
        $this->assertDatabaseMissing('loyalty_registration_incentive_claims', ['company_id' => $companyB->id, 'customer_id' => $customerB->id]);
    }

    private function context(array $customerData = []): array
    {
        $company = Company::create(['trade_name' => 'Empresa '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $customer = Customer::create($customerData + ['company_id' => $company->id, 'name' => 'Cliente '.uniqid(), 'customer_type' => 'individual', 'is_active' => true]);

        return [$company, $customer];
    }
}
