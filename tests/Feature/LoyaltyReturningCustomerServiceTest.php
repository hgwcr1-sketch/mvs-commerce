<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltySetting;
use App\Services\Loyalty\LoyaltyReturningCustomerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyReturningCustomerServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_exactly_required_days_and_more_are_eligible_but_one_day_less_is_not(): void
    {
        [$company, $setting] = $this->context(30, '100.0000');
        $service = app(LoyaltyReturningCustomerService::class);
        $current = '2026-07-01 12:00:00';

        $exact = $this->customerWithAccount($company, '2026-06-01 12:00:00');
        $more = $this->customerWithAccount($company, '2026-05-31 12:00:00');
        $less = $this->customerWithAccount($company, '2026-06-02 12:00:00');

        $exactMovement = $service->awardIfEligible($exact, $company, 101, $current);
        $moreMovement = $service->awardIfEligible($more, $company, 102, $current);

        $this->assertNotNull($exactMovement);
        $this->assertNotNull($moreMovement);
        $this->assertNull($service->awardIfEligible($less, $company, 103, $current));
        $this->assertSame(30, $exactMovement->metadata['inactivity_days']);
        $this->assertSame(31, $moreMovement->metadata['inactivity_days']);
        $this->assertSame('100.0000', $exactMovement->points);
        $this->assertSame(LoyaltyMovement::TYPE_RETURN_CUSTOMER, $exactMovement->type);
        $this->assertDatabaseCount('loyalty_movements', 2);
    }

    public function test_first_purchase_disabled_rule_and_foreign_customer_are_not_eligible(): void
    {
        [$company, $setting] = $this->context();
        $service = app(LoyaltyReturningCustomerService::class);
        $firstPurchase = $this->customerWithAccount($company, null);

        $this->assertNull($service->awardIfEligible($firstPurchase, $company, 201, '2026-07-01 12:00:00'));
        $setting->update(['returning_customer_enabled' => false]);
        $returning = $this->customerWithAccount($company, '2026-05-01 12:00:00');
        $this->assertNull($service->awardIfEligible($returning, $company, 202, '2026-07-01 12:00:00'));

        [$otherCompany] = $this->context();
        $this->assertNull($service->awardIfEligible($returning, $otherCompany, 203, '2026-07-01 12:00:00'));
        $this->assertDatabaseCount('loyalty_movements', 0);
    }

    public function test_configuration_changes_days_and_points_dynamically(): void
    {
        [$company, $setting] = $this->context(30, '100.0000');
        $service = app(LoyaltyReturningCustomerService::class);
        $customer = $this->customerWithAccount($company, '2026-06-11 12:00:00');

        $this->assertNull($service->awardIfEligible($customer, $company, 301, '2026-07-01 12:00:00'));
        $setting->update(['returning_customer_days' => 20, 'returning_customer_points' => '175.5000']);
        $movement = $service->awardIfEligible($customer, $company, 301, '2026-07-01 12:00:00');

        $this->assertNotNull($movement);
        $this->assertSame('175.5000', $movement->points);
        $this->assertSame(20, $movement->metadata['required_days']);
        $this->assertSame($company->id, $movement->company_id);
    }

    public function test_sale_event_is_idempotent_and_future_inactivity_can_earn_again(): void
    {
        [$company] = $this->context(30, '80.0000');
        $service = app(LoyaltyReturningCustomerService::class);
        $customer = $this->customerWithAccount($company, '2026-06-01 12:00:00');

        $first = $service->awardIfEligible($customer, $company, 401, '2026-07-01 12:00:00');
        $duplicate = $service->awardIfEligible($customer, $company, 401, '2026-07-01 12:00:00');
        $this->assertTrue($first->is($duplicate));

        $account = $first->loyaltyAccount;
        $account->update(['last_qualifying_purchase_at' => '2026-07-01 12:00:00']);
        $this->assertNull($service->awardIfEligible($customer, $company, 402, '2026-07-02 12:00:00'));
        $future = $service->awardIfEligible($customer, $company, 403, '2026-07-31 12:00:00');

        $this->assertNotNull($future);
        $this->assertSame('160.0000', $account->fresh()->balance);
        $this->assertDatabaseCount('loyalty_movements', 2);
    }

    public function test_branch_is_traced_while_recent_activity_is_global_for_company_account(): void
    {
        [$company] = $this->context(30, '100.0000');
        $firstBranch = $this->branch($company, 'Primera');
        $secondBranch = $this->branch($company, 'Segunda');
        $eligible = $this->customerWithAccount($company, '2026-05-31 12:00:00');
        $recent = $this->customerWithAccount($company, '2026-06-21 12:00:00');

        $movement = app(LoyaltyReturningCustomerService::class)->awardIfEligible(
            $eligible,
            $company,
            501,
            '2026-07-01 12:00:00',
            ['branch' => $secondBranch],
        );

        $this->assertNotNull($movement);
        $this->assertSame($secondBranch->id, $movement->branch_id);
        $this->assertNull(app(LoyaltyReturningCustomerService::class)->awardIfEligible(
            $recent,
            $company,
            502,
            '2026-07-01 12:00:00',
            ['branch' => $firstBranch],
        ));
        $this->assertNotSame($firstBranch->id, $secondBranch->id);
        $this->assertDatabaseCount('loyalty_accounts', 2);
    }

    private function context(int $days = 30, string $points = '100.0000'): array
    {
        $company = Company::create(['trade_name' => 'Empresa '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $setting = LoyaltySetting::create([
            'company_id' => $company->id,
            'is_active' => true,
            'earning_percentage' => '5.0000',
            'point_value' => '1.0000',
            'returning_customer_enabled' => true,
            'returning_customer_days' => $days,
            'returning_customer_points' => $points,
        ]);

        return [$company, $setting];
    }

    private function customerWithAccount(Company $company, ?string $lastPurchase): Customer
    {
        $customer = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente '.uniqid(), 'is_active' => true]);
        LoyaltyAccount::create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'last_qualifying_purchase_at' => $lastPurchase,
        ]);

        return $customer;
    }

    private function branch(Company $company, string $name): Branch
    {
        return Branch::create(['company_id' => $company->id, 'name' => $name, 'code' => strtoupper(substr(uniqid(), -6)), 'is_active' => true]);
    }
}
