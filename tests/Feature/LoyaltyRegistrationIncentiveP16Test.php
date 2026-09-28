<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyRegistrationIncentive;
use App\Models\LoyaltyRegistrationIncentiveClaim;
use App\Models\LoyaltySetting;
use App\Models\Sale;
use App\Models\User;
use App\Services\Loyalty\LoyaltyRedemptionService;
use App\Services\Loyalty\LoyaltyRegistrationIncentiveService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LoyaltyRegistrationIncentiveP16Test extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_minimum_disabled_and_exact_boundary_are_eligible_but_one_ten_thousandth_below_is_not(): void
    {
        [$company, , $customer] = $this->context();
        $service = app(LoyaltyRegistrationIncentiveService::class);
        $service->configure($company, true, 'percentage', '10', ['minimum_purchase_enabled' => false]);
        $service->tryAwardForRegistration($customer, $company);

        $this->assertTrue($service->evaluateForPurchase($customer, $company, '0.0001')['eligible']);

        $claim = LoyaltyRegistrationIncentiveClaim::query()->where('customer_id', $customer->id)->firstOrFail();
        $claim->update(['minimum_purchase_amount' => '100.0000']);
        $exact = $service->evaluateForPurchase($customer, $company, '100.0000');
        $below = $service->evaluateForPurchase($customer, $company, '99.9999');

        $this->assertTrue($exact['eligible']);
        $this->assertSame('10.0000', $exact['discount_amount']);
        $this->assertFalse($below['eligible']);
        $this->assertSame('minimum_purchase_not_reached', $below['reason']);
    }

    public function test_incentive_can_be_awarded_at_registration(): void
    {
        [$company, , $customer] = $this->context();
        $service = app(LoyaltyRegistrationIncentiveService::class);
        $service->configure($company, true, 'fixed', '500', [
            'award_timing' => LoyaltyRegistrationIncentive::TIMING_REGISTRATION,
        ]);

        $claim = $service->tryAwardForRegistration($customer, $company);

        $this->assertNotNull($claim);
        $this->assertSame(LoyaltyRegistrationIncentive::TIMING_REGISTRATION, $claim->award_timing);
        $this->assertNull($claim->qualification_sale_id);
    }

    public function test_incentive_after_first_valid_purchase_respects_minimum_and_is_idempotent(): void
    {
        [$company, $branch, $customer, $user] = $this->context();
        $service = app(LoyaltyRegistrationIncentiveService::class);
        $service->configure($company, true, 'points', '25', [
            'award_timing' => LoyaltyRegistrationIncentive::TIMING_AFTER_FIRST_VALID_PURCHASE,
            'minimum_purchase_enabled' => true,
            'minimum_purchase_amount' => '100.0000',
        ]);

        $this->assertNull($service->tryAwardForRegistration($customer, $company));
        $this->assertNull($service->tryAwardAfterPurchase($this->sale($company, $branch, $customer, $user, '99.9999')));

        $qualifying = $this->sale($company, $branch, $customer, $user, '100.0000');
        $claim = $service->tryAwardAfterPurchase($qualifying);

        $this->assertNotNull($claim);
        $this->assertSame($qualifying->id, $claim->qualification_sale_id);
        $this->assertNull($service->tryAwardAfterPurchase($qualifying));
        $this->assertDatabaseCount('loyalty_registration_incentive_claims', 1);
        $this->assertSame('25.0000', LoyaltyAccount::query()->where('customer_id', $customer->id)->value('balance'));
    }

    public function test_first_purchase_can_be_allowed_or_deferred_until_a_later_purchase(): void
    {
        [$company, $branch, $customer, $user] = $this->context();
        $service = app(LoyaltyRegistrationIncentiveService::class);
        $service->configure($company, true, 'fixed', '5', ['allow_on_first_purchase' => false]);
        $service->tryAwardForRegistration($customer, $company);

        $blocked = $service->evaluateForPurchase($customer, $company, '20.0000');
        $this->assertSame('first_purchase_not_allowed', $blocked['reason']);

        $this->sale($company, $branch, $customer, $user, '20.0000');
        $this->assertTrue($service->evaluateForPurchase($customer, $company, '20.0000')['eligible']);

        [$otherCompany, , $otherCustomer] = $this->context();
        $service->configure($otherCompany, true, 'fixed', '5', ['allow_on_first_purchase' => true]);
        $service->tryAwardForRegistration($otherCustomer, $otherCompany);
        $this->assertTrue($service->evaluateForPurchase($otherCustomer, $otherCompany, '20.0000')['eligible']);
    }

    public function test_expiration_uses_company_timezone_and_records_expired_audit_once(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-29 10:00:00', 'America/Costa_Rica'));
        try {
            [$company, , $customer] = $this->context();
            $service = app(LoyaltyRegistrationIncentiveService::class);
            $service->configure($company, true, 'fixed', '5', [
                'expiration_enabled' => true,
                'expiration_days' => 2,
            ]);
            $claim = $service->tryAwardForRegistration($customer, $company);

            $this->assertSame('2026-09-01 05:59:59', $claim->expires_at->utc()->format('Y-m-d H:i:s'));
            $this->assertTrue($service->evaluateForPurchase($customer, $company, '20', '2026-08-31 23:59:59')['eligible']);

            $expired = $service->evaluateForPurchase($customer, $company, '20', '2026-09-01 00:00:00');
            $this->assertSame('expired', $expired['reason']);
            $expiredAt = $claim->fresh()->expired_at;
            $this->assertNotNull($expiredAt);

            $service->evaluateForPurchase($customer, $company, '20', '2026-09-02 00:00:00');
            $this->assertTrue($expiredAt->equalTo($claim->fresh()->expired_at));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_redemption_minimum_can_only_be_bypassed_by_an_eligible_points_incentive(): void
    {
        [$company, , $customer] = $this->context();
        LoyaltySetting::create([
            'company_id' => $company->id,
            'is_active' => true,
            'earning_percentage' => '5.0000',
            'point_value' => '1.0000',
            'redemption_minimum_enabled' => true,
            'redemption_minimum_amount' => '100.0000',
            'maximum_redemption_percent' => '100.0000',
        ]);
        $incentives = app(LoyaltyRegistrationIncentiveService::class);
        $incentives->configure($company, true, 'points', '10', ['bypass_redemption_minimum' => true]);
        $claim = $incentives->tryAwardForRegistration($customer, $company);

        $result = app(LoyaltyRedemptionService::class)->redeem($customer, $company, '5', '50', ['event_key' => 'p16-bypass']);

        $this->assertSame('5.0000', $result['redeemed_points']);
        $this->assertNotNull($claim->fresh()->used_at);

        $this->expectException(ValidationException::class);
        app(LoyaltyRedemptionService::class)->redeem($customer, $company, '1', '50', ['event_key' => 'p16-no-second-bypass']);
    }

    public function test_claim_evaluation_is_strictly_isolated_by_company(): void
    {
        [$companyA, , $customerA] = $this->context();
        [$companyB] = $this->context();
        $service = app(LoyaltyRegistrationIncentiveService::class);
        $service->configure($companyA, true, 'fixed', '5');
        $service->configure($companyB, true, 'fixed', '50');
        $service->tryAwardForRegistration($customerA, $companyA);

        $this->assertSame('5.0000', $service->evaluateForPurchase($customerA, $companyA, '100')['discount_amount']);
        $this->expectException(ValidationException::class);
        $service->evaluateForPurchase($customerA, $companyB, '100');
    }

    private function context(): array
    {
        $company = Company::create(['trade_name' => 'Empresa '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => strtoupper(substr(uniqid(), -6)), 'is_active' => true]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Cliente '.uniqid(), 'customer_type' => 'individual', 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);

        return [$company, $branch, $customer, $user];
    }

    private function sale(Company $company, Branch $branch, Customer $customer, User $user, string $total): Sale
    {
        return Sale::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'checkout_token' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', Str::random()),
            'sale_number' => 'P16-'.Str::upper(Str::random(8)),
            'document_type' => Sale::DOCUMENT_ELECTRONIC_TICKET,
            'sale_condition' => Sale::CONDITION_CASH,
            'status' => Sale::STATUS_COMPLETED,
            'currency_code' => 'CRC',
            'exchange_rate' => '1.0000',
            'subtotal' => $total,
            'discount_total' => '0.0000',
            'tax_total' => '0.0000',
            'rounding_total' => '0.0000',
            'total' => $total,
            'paid_total' => $total,
            'balance_due' => '0.0000',
            'completed_at' => now(),
        ]);
    }
}
