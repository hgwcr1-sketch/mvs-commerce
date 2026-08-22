<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltySetting;
use App\Services\Loyalty\LoyaltyAccountService;
use App\Services\Loyalty\LoyaltyEarningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LoyaltyEarningServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_five_percent_calculates_the_approved_examples(): void
    {
        [$company, $customer] = $this->context('5.0000');
        $service = app(LoyaltyEarningService::class);

        $cases = [
            '1000.0000' => '50.0000',
            '10000.0000' => '500.0000',
            '8000.0000' => '400.0000',
            '20000.0000' => '1000.0000',
            '27500.0000' => '1375.0000',
        ];

        foreach ($cases as $amount => $expected) {
            $movement = $service->earnFromEligibleAmount($customer, $company, $amount, ['event_key' => 'earning:'.$amount]);
            $this->assertSame($expected, $movement->points);
        }

        $this->assertSame('3325.0000', $movement->loyaltyAccount->fresh()->balance);
    }

    public function test_movement_preserves_the_configuration_snapshot_and_context(): void
    {
        [$company, $customer] = $this->context('7.2500', ['point_value' => '1.5000']);
        $branch = $this->branch($company, 'Liberia');

        $movement = app(LoyaltyEarningService::class)->earnFromEligibleAmount($customer, $company, '1234.5000', [
            'branch' => $branch,
            'source_type' => 'external-order',
            'source_id' => 44,
            'event_key' => 'order:44:loyalty:earn',
            'description' => 'Compra elegible externa',
            'metadata' => ['channel' => 'test'],
        ]);

        $this->assertSame(LoyaltyMovement::TYPE_PURCHASE, $movement->type);
        $this->assertSame('1234.5000', $movement->base_amount);
        $this->assertSame('7.2500', $movement->earning_percentage);
        $this->assertSame('1.5000', $movement->point_value);
        $this->assertSame('89.5013', $movement->points);
        $this->assertSame($branch->id, $movement->branch_id);
        $this->assertSame('external-order', $movement->source_type);
        $this->assertSame(44, $movement->source_id);
        $this->assertSame([
            'channel' => 'test',
            'base_points' => '89.5013',
            'multiplier' => '1.0000',
            'multiplier_id' => null,
            'multiplier_name' => null,
            'final_points' => '89.5013',
        ], $movement->metadata);
    }

    public function test_customer_and_branch_must_belong_to_company(): void
    {
        [$company, $customer] = $this->context();
        [$foreignCompany, $foreignCustomer] = $this->context();

        try {
            app(LoyaltyEarningService::class)->earnFromEligibleAmount($foreignCustomer, $company, 1000);
            $this->fail('Se esperaba rechazo por empresa del cliente.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('customer', $exception->errors());
        }

        $this->expectException(ValidationException::class);
        app(LoyaltyEarningService::class)->earnFromEligibleAmount($customer, $company, 1000, [
            'branch' => $this->branch($foreignCompany, 'Ajena'),
        ]);
    }

    public function test_zero_amount_does_not_create_account_or_movement_and_negative_is_rejected(): void
    {
        [$company, $customer] = $this->context();
        $service = app(LoyaltyEarningService::class);

        $this->assertNull($service->earnFromEligibleAmount($customer, $company, '0.0000'));
        $this->assertDatabaseCount('loyalty_accounts', 0);
        $this->assertDatabaseCount('loyalty_movements', 0);

        $this->expectException(ValidationException::class);
        $service->earnFromEligibleAmount($customer, $company, '-0.0001');
    }

    public function test_missing_or_disabled_configuration_prevents_earning(): void
    {
        $company = $this->company();
        $customer = $this->customer($company);

        try {
            app(LoyaltyEarningService::class)->earnFromEligibleAmount($customer, $company, 1000);
            $this->fail('Se esperaba configuración inexistente.');
        } catch (ValidationException $exception) {
            $this->assertSame('La empresa no tiene configuración de Fidelización.', $exception->errors()['loyalty'][0]);
        }

        $this->setting($company, ['is_active' => false]);
        $this->expectException(ValidationException::class);
        app(LoyaltyEarningService::class)->earnFromEligibleAmount($customer, $company, 1000);
    }

    public function test_invalid_percentage_and_point_value_are_rejected(): void
    {
        [$company, $customer, $setting] = $this->context('0.0000');

        try {
            app(LoyaltyEarningService::class)->earnFromEligibleAmount($customer, $company, 1000);
            $this->fail('Se esperaba porcentaje inválido.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('earning_percentage', $exception->errors());
        }

        $setting->update(['earning_percentage' => '5.0000', 'point_value' => '0.0000']);
        $this->expectException(ValidationException::class);
        app(LoyaltyEarningService::class)->earnFromEligibleAmount($customer, $company, 1000);
    }

    public function test_offer_respects_earn_on_offers_setting(): void
    {
        [$company, $customer, $setting] = $this->context('5.0000', ['earn_on_offers' => false]);
        $service = app(LoyaltyEarningService::class);

        $this->assertNull($service->earnFromEligibleAmount($customer, $company, 1000, ['is_offer' => true]));
        $this->assertDatabaseCount('loyalty_movements', 0);

        $setting->update(['earn_on_offers' => true]);
        $movement = $service->earnFromEligibleAmount($customer, $company, 1000, ['is_offer' => true]);
        $this->assertSame('50.0000', $movement->points);
    }

    public function test_event_key_reuses_movement_without_duplicating_balance(): void
    {
        [$company, $customer] = $this->context();
        $service = app(LoyaltyEarningService::class);
        $options = ['event_key' => 'sale:123:loyalty:earn'];

        $first = $service->earnFromEligibleAmount($customer, $company, 1000, $options);
        $second = $service->earnFromEligibleAmount($customer, $company, 1000, $options);

        $this->assertTrue($first->is($second));
        $this->assertSame('50.0000', $first->loyaltyAccount->fresh()->balance);
        $this->assertDatabaseCount('loyalty_movements', 1);
    }

    public function test_purchase_updates_last_qualifying_purchase_at_using_effective_date(): void
    {
        [$company, $customer] = $this->context();

        $movement = app(LoyaltyEarningService::class)->earnFromEligibleAmount($customer, $company, 1000, [
            'effective_at' => '2026-07-15 14:30:00',
        ]);

        $this->assertSame('2026-07-15 14:30:00', $movement->loyaltyAccount->fresh()->last_qualifying_purchase_at->format('Y-m-d H:i:s'));
        $this->assertNotNull($movement->loyaltyAccount->fresh()->last_activity_at);
    }

    public function test_non_purchase_movement_cannot_change_last_qualifying_purchase_at(): void
    {
        [$company, $customer] = $this->context();
        $accountService = app(LoyaltyAccountService::class);
        $account = $accountService->getOrCreateAccount($customer, $company);

        $accountService->addPoints($account, 10, LoyaltyMovement::TYPE_BIRTHDAY, [
            'qualifying_purchase_at' => '2026-01-01 10:00:00',
        ]);

        $this->assertNull($account->fresh()->last_qualifying_purchase_at);
    }

    public function test_rounding_is_half_up_to_four_decimals_without_floats(): void
    {
        [$company, $customer] = $this->context('5.0000');
        $service = app(LoyaltyEarningService::class);

        $roundedUp = $service->earnFromEligibleAmount($customer, $company, '0.0010', ['event_key' => 'round:up']);
        $fractional = $service->earnFromEligibleAmount($customer, $company, '1.2345', ['event_key' => 'round:fraction']);
        $roundedToZero = $service->earnFromEligibleAmount($customer, $company, '0.0001', ['event_key' => 'round:zero']);

        $this->assertSame('0.0001', $roundedUp->points);
        $this->assertSame('0.0617', $fractional->points);
        $this->assertNull($roundedToZero);
        $this->assertSame('0.0618', $roundedUp->loyaltyAccount->fresh()->balance);
        $this->assertDatabaseCount('loyalty_movements', 2);
    }

    private function context(string $percentage = '5.0000', array $setting = []): array
    {
        $company = $this->company();
        $customer = $this->customer($company);

        return [$company, $customer, $this->setting($company, ['earning_percentage' => $percentage] + $setting)];
    }

    private function setting(Company $company, array $overrides = []): LoyaltySetting
    {
        return LoyaltySetting::create($overrides + [
            'company_id' => $company->id,
            'is_active' => true,
            'earning_percentage' => '5.0000',
            'point_value' => '1.0000',
            'earn_on_offers' => false,
        ]);
    }

    private function company(): Company
    {
        return Company::create(['trade_name' => 'Empresa '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
    }

    private function customer(Company $company): Customer
    {
        return Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente '.uniqid(), 'credit_limit' => 0, 'is_active' => true]);
    }

    private function branch(Company $company, string $name): Branch
    {
        return Branch::create(['company_id' => $company->id, 'name' => $name, 'code' => strtoupper(substr(uniqid(), -6)), 'is_active' => true]);
    }
}
