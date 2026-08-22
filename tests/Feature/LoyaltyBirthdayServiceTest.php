<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltySetting;
use App\Services\Loyalty\LoyaltyBirthdayService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyBirthdayServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_birthday_bonus_updates_account_and_kardex_with_context(): void
    {
        [$company, $customer, $setting] = $this->context('04-18');
        $branch = $this->branch($company);

        $movement = app(LoyaltyBirthdayService::class)->awardIfEligible(
            $customer,
            $company,
            CarbonImmutable::parse('2026-04-18 10:00:00', $company->timezone),
            ['branch' => $branch, 'source_type' => 'test-purchase', 'source_id' => 81],
        );

        $this->assertNotNull($movement);
        $this->assertSame(LoyaltyMovement::TYPE_BIRTHDAY, $movement->type);
        $this->assertSame('125.5000', $movement->points);
        $this->assertSame('125.5000', $movement->loyaltyAccount->fresh()->balance);
        $this->assertSame($company->id, $movement->company_id);
        $this->assertSame($customer->id, $movement->customer_id);
        $this->assertSame($branch->id, $movement->branch_id);
        $this->assertSame("birthday:{$customer->id}:2026", $movement->event_key);
        $this->assertSame('2026', (string) $movement->metadata['birthday_year']);
        $this->assertDatabaseHas('loyalty_movements', ['id' => $movement->id, 'type' => 'birthday']);
    }

    public function test_same_customer_receives_only_one_bonus_per_year_and_is_eligible_next_year(): void
    {
        [$company, $customer] = $this->context('06-10');
        $service = app(LoyaltyBirthdayService::class);

        $first = $service->awardIfEligible($customer, $company, '2026-06-10 08:00:00');
        $duplicate = $service->awardIfEligible($customer, $company, '2026-06-10 18:00:00');
        $nextYear = $service->awardIfEligible($customer, $company, '2027-06-10 08:00:00');

        $this->assertTrue($first->is($duplicate));
        $this->assertFalse($first->is($nextYear));
        $this->assertSame('251.0000', $first->loyaltyAccount->fresh()->balance);
        $this->assertDatabaseCount('loyalty_movements', 2);
    }

    public function test_customers_have_independent_annual_birthday_events(): void
    {
        [$company, $firstCustomer] = $this->context('08-22');
        $secondCustomer = $this->customer($company, '08-22');
        $service = app(LoyaltyBirthdayService::class);

        $first = $service->awardIfEligible($firstCustomer, $company, '2026-08-22 09:00:00');
        $second = $service->awardIfEligible($secondCustomer, $company, '2026-08-22 09:00:00');

        $this->assertNotSame($first->event_key, $second->event_key);
        $this->assertSame($firstCustomer->id, $first->customer_id);
        $this->assertSame($secondCustomer->id, $second->customer_id);
        $this->assertDatabaseCount('loyalty_accounts', 2);
    }

    public function test_non_birthday_disabled_or_foreign_customer_does_not_receive_bonus(): void
    {
        [$company, $customer, $setting] = $this->context('01-15');
        $service = app(LoyaltyBirthdayService::class);

        $this->assertNull($service->awardIfEligible($customer, $company, '2026-01-16 10:00:00'));
        $setting->update(['birthday_enabled' => false]);
        $this->assertNull($service->awardIfEligible($customer, $company, '2026-01-15 10:00:00'));

        [$foreignCompany, $foreignCustomer] = $this->context('01-15');
        $this->assertNull($service->awardIfEligible($foreignCustomer, $company, '2026-01-15 10:00:00'));
        $this->assertNotSame($company->id, $foreignCompany->id);
        $this->assertDatabaseCount('loyalty_movements', 0);
    }

    public function test_february_29_requires_exact_day_in_a_leap_year(): void
    {
        [$company, $customer] = $this->context('02-29');
        $service = app(LoyaltyBirthdayService::class);

        $this->assertNull($service->awardIfEligible($customer, $company, '2025-02-28 10:00:00'));
        $movement = $service->awardIfEligible($customer, $company, '2024-02-29 10:00:00');

        $this->assertNotNull($movement);
        $this->assertSame("birthday:{$customer->id}:2024", $movement->event_key);
    }

    private function context(string $monthDay): array
    {
        $company = Company::create(['trade_name' => 'Empresa '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $customer = $this->customer($company, $monthDay);
        $setting = LoyaltySetting::create([
            'company_id' => $company->id,
            'is_active' => true,
            'earning_percentage' => '5.0000',
            'point_value' => '1.0000',
            'birthday_enabled' => true,
            'birthday_points' => '125.5000',
        ]);

        return [$company, $customer, $setting];
    }

    private function customer(Company $company, string $monthDay): Customer
    {
        return Customer::create([
            'company_id' => $company->id,
            'customer_type' => 'individual',
            'name' => 'Cliente '.uniqid(),
            'birth_date' => ($monthDay === '02-29' ? '1992-' : '1990-').$monthDay,
            'is_active' => true,
        ]);
    }

    private function branch(Company $company): Branch
    {
        return Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => strtoupper(substr(uniqid(), -6)), 'is_active' => true]);
    }
}
