<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltySetting;
use App\Models\User;
use App\Services\Loyalty\LoyaltyRedemptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LoyaltyRedemptionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_redemption_updates_balance_total_and_kardex_with_snapshots(): void
    {
        [$company, , $customer, $account, $branch] = $this->context('1.0000', '50.0000', '5000.0000');
        $user = User::factory()->create();

        $result = $this->service()->redeem($customer, $company, '4000.0000', '10000.0000', [
            'branch' => $branch,
            'user' => $user,
            'source_type' => 'sale',
            'source_id' => 123,
            'event_key' => 'sale:123:loyalty:redemption',
            'description' => 'Canje de prueba',
            'metadata' => ['channel' => 'test'],
        ]);

        $movement = $result['movement'];
        $this->assertSame('redemption', $movement->type);
        $this->assertSame('-4000.0000', $movement->points);
        $this->assertSame('5000.0000', $movement->balance_before);
        $this->assertSame('1000.0000', $movement->balance_after);
        $this->assertSame('4000.0000', $movement->base_amount);
        $this->assertSame('1.0000', $movement->point_value);
        $this->assertSame($company->id, $movement->company_id);
        $this->assertSame($branch->id, $movement->branch_id);
        $this->assertSame($customer->id, $movement->customer_id);
        $this->assertSame($user->id, $movement->user_id);
        $this->assertSame('sale', $movement->source_type);
        $this->assertSame(123, $movement->source_id);
        $this->assertSame('Canje de prueba', $movement->description);
        $this->assertSame('4000.0000', $result['requested_points']);
        $this->assertSame('4000.0000', $result['redeemed_points']);
        $this->assertSame('4000.0000', $result['redeemed_amount']);
        $this->assertSame('1000.0000', $result['balance_after']);
        $this->assertSame('1.0000', $result['point_value']);
        $this->assertSame('1000.0000', $account->fresh()->balance);
        $this->assertSame('4000.0000', $account->fresh()->total_redeemed);
        $this->assertSame('test', $movement->metadata['channel']);
    }

    public function test_point_value_half_converts_money_and_respects_fifty_percent_limit(): void
    {
        [$company, , $customer, $account] = $this->context('0.5000', '50.0000', '20000.0000');

        $result = $this->service()->redeem($customer, $company, '10000.0000', '10000.0000');

        $this->assertSame('5000.0000', $result['redeemed_amount']);
        $this->assertSame('0.5000', $result['point_value']);
        $this->assertSame('10000.0000', $account->fresh()->balance);
    }

    public function test_hundred_percent_allows_eligible_amount_subject_to_balance(): void
    {
        [$company, , $customer, $account] = $this->context('1.0000', '100.0000', '12000.0000');

        $result = $this->service()->redeem($customer, $company, '10000.0000', '10000.0000');

        $this->assertSame('10000.0000', $result['redeemed_amount']);
        $this->assertSame('2000.0000', $account->fresh()->balance);
    }

    public function test_requests_over_balance_or_percentage_limit_do_not_mutate_anything(): void
    {
        [$company, , $customer, $account] = $this->context('1.0000', '50.0000', '5000.0000');

        foreach ([['6000.0000', '10000.0000'], ['4000.0000', '6000.0000']] as [$points, $amount]) {
            try {
                $this->service()->redeem($customer, $company, $points, $amount);
                $this->fail('El canje debía rechazarse.');
            } catch (ValidationException) {
                $this->assertSame('5000.0000', $account->fresh()->balance);
                $this->assertSame('0.0000', $account->fresh()->total_redeemed);
                $this->assertDatabaseCount('loyalty_movements', 0);
            }
        }
    }

    public function test_invalid_points_and_eligible_amount_are_rejected_without_movement(): void
    {
        [$company, , $customer, $account] = $this->context();

        foreach ([['0', '1000'], ['-1', '1000'], ['1.00001', '1000'], ['1', '0'], ['1', '-1']] as [$points, $amount]) {
            try {
                $this->service()->redeem($customer, $company, $points, $amount);
                $this->fail('Los valores inválidos debían rechazarse.');
            } catch (ValidationException) {
                $this->assertSame('5000.0000', $account->fresh()->balance);
                $this->assertDatabaseCount('loyalty_movements', 0);
            }
        }
    }

    public function test_f15_minimum_and_disabled_loyalty_block_without_mutation(): void
    {
        [$company, $setting, $customer, $account] = $this->context('1.0000', '100.0000', '2500.0000');
        $setting->update(['redemption_minimum_enabled' => true, 'redemption_minimum_amount' => '3000.0000']);

        $this->assertRejected(fn () => $this->service()->redeem($customer, $company, 1000, 5000), $account);

        $setting->update(['redemption_minimum_enabled' => false, 'is_active' => false]);
        $this->assertRejected(fn () => $this->service()->redeem($customer, $company, 1000, 5000), $account);
    }

    public function test_company_customer_and_branch_must_be_compatible_and_failure_is_atomic(): void
    {
        [$company, , $customer, $account] = $this->context();
        [$otherCompany, , $otherCustomer, , $otherBranch] = $this->context();

        $this->assertRejected(fn () => $this->service()->redeem($otherCustomer, $company, 1000, 5000), $account);
        $this->assertRejected(fn () => $this->service()->redeem($customer, $company, 1000, 5000, ['branch' => $otherBranch]), $account);
        $this->assertNotSame($company->id, $otherCompany->id);
    }

    public function test_offer_setting_blocks_or_allows_contextually(): void
    {
        [$company, $setting, $customer, $account] = $this->context();

        $this->assertRejected(fn () => $this->service()->redeem($customer, $company, 1000, 5000, ['is_offer' => true]), $account);

        $setting->update(['redeem_on_offers' => true]);
        $result = $this->service()->redeem($customer, $company, 1000, 5000, ['is_offer' => true]);
        $this->assertSame('1000.0000', $result['redeemed_points']);
        $this->assertTrue($result['movement']->metadata['is_offer']);
    }

    public function test_non_offer_continues_when_offer_redemption_is_disabled(): void
    {
        [$company, , $customer] = $this->context();

        $result = $this->service()->redeem($customer, $company, 1000, 5000, ['is_offer' => false]);

        $this->assertSame('1000.0000', $result['redeemed_points']);
    }

    public function test_event_key_is_idempotent_and_does_not_discount_twice(): void
    {
        [$company, , $customer, $account] = $this->context();
        $context = ['event_key' => 'sale:123:loyalty:redemption'];

        $first = $this->service()->redeem($customer, $company, 2000, 5000, $context);
        $second = $this->service()->redeem($customer, $company, 4000, 5000, $context);

        $this->assertSame($first['movement']->id, $second['movement']->id);
        $this->assertSame('2000.0000', $second['requested_points']);
        $this->assertSame('3000.0000', $account->fresh()->balance);
        $this->assertSame('2000.0000', $account->fresh()->total_redeemed);
        $this->assertDatabaseCount('loyalty_movements', 1);
    }

    private function service(): LoyaltyRedemptionService
    {
        return app(LoyaltyRedemptionService::class);
    }

    private function context(string $pointValue = '1.0000', string $percentage = '100.0000', string $balance = '5000.0000'): array
    {
        $company = Company::create(['trade_name' => 'Empresa '.uniqid(), 'timezone' => 'UTC', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => strtoupper(substr(uniqid(), -6)), 'is_active' => true]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Cliente', 'customer_type' => 'individual', 'is_active' => true]);
        $setting = LoyaltySetting::create(['company_id' => $company->id, 'is_active' => true, 'earning_percentage' => 5, 'point_value' => $pointValue, 'maximum_redemption_percent' => $percentage, 'redeem_on_offers' => false]);
        $account = LoyaltyAccount::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'balance' => $balance]);

        return [$company, $setting, $customer, $account, $branch];
    }

    private function assertRejected(callable $operation, LoyaltyAccount $account): void
    {
        $balanceBefore = $account->fresh()->balance;

        try {
            $operation();
            $this->fail('El canje debía rechazarse.');
        } catch (ValidationException) {
            $this->assertSame($balanceBefore, $account->fresh()->balance);
            $this->assertSame('0.0000', $account->fresh()->total_redeemed);
            $this->assertDatabaseCount('loyalty_movements', 0);
        }
    }
}
