<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyMovement;
use App\Models\User;
use App\Services\Loyalty\LoyaltyAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LoyaltyAccountServiceTest extends TestCase
{
    use RefreshDatabase;

    private LoyaltyAccountService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(LoyaltyAccountService::class);
    }

    public function test_it_creates_and_reuses_an_account_for_a_company_customer(): void
    {
        $company = $this->company();
        $customer = $this->customer($company);

        $first = $this->service->getOrCreateAccount($customer, $company);
        $second = $this->service->getOrCreateAccount($customer, $company);

        $this->assertTrue($first->is($second));
        $this->assertDatabaseCount('loyalty_accounts', 1);
    }

    public function test_it_rejects_a_customer_from_another_company(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->getOrCreateAccount($this->customer($this->company('B')), $this->company('A'));
    }

    public function test_it_adds_points_and_updates_kardex_balance_and_earned_total(): void
    {
        [$company, $customer, $account] = $this->context();

        $first = $this->service->addPoints($account, '50.1250', LoyaltyMovement::TYPE_PURCHASE);
        $second = $this->service->addPoints($account, '0.3750', LoyaltyMovement::TYPE_PROMOTION);

        $this->assertSame('0.0000', $first->balance_before);
        $this->assertSame('50.1250', $first->balance_after);
        $this->assertSame('50.1250', $second->balance_before);
        $this->assertSame('50.5000', $second->balance_after);
        $balance = $this->service->getBalance($account);
        $this->assertSame('50.5000', $balance['balance']);
        $this->assertSame('50.5000', $balance['total_earned']);
        $this->assertNotNull($balance['last_activity_at']);
    }

    public function test_it_subtracts_points_and_updates_only_the_corresponding_totals(): void
    {
        [, , $account] = $this->context();
        $this->service->addPoints($account, '1000.0000', LoyaltyMovement::TYPE_PURCHASE);

        $redemption = $this->service->subtractPoints($account, '250.0000', LoyaltyMovement::TYPE_REDEMPTION);
        $expiration = $this->service->subtractPoints($account, '100.0000', LoyaltyMovement::TYPE_EXPIRATION);
        $balance = $this->service->getBalance($account);

        $this->assertSame('-250.0000', $redemption->points);
        $this->assertSame('1000.0000', $redemption->balance_before);
        $this->assertSame('750.0000', $redemption->balance_after);
        $this->assertSame('650.0000', $expiration->balance_after);
        $this->assertSame('250.0000', $balance['total_redeemed']);
        $this->assertSame('100.0000', $balance['total_expired']);
        $this->assertSame('1000.0000', $balance['total_earned']);
    }

    public function test_it_rejects_invalid_points_and_negative_balance_atomically(): void
    {
        [, , $account] = $this->context();
        $this->service->addPoints($account, '10.0000', LoyaltyMovement::TYPE_PURCHASE);

        try {
            $this->service->subtractPoints($account, '10.0001', LoyaltyMovement::TYPE_REDEMPTION);
            $this->fail('Se esperaba saldo insuficiente.');
        } catch (ValidationException $exception) {
            $this->assertSame('Saldo de puntos insuficiente.', $exception->errors()['points'][0]);
        }

        $this->assertSame('10.0000', $account->fresh()->balance);
        $this->assertDatabaseCount('loyalty_movements', 1);

        $this->expectException(ValidationException::class);
        $this->service->addPoints($account, 0, LoyaltyMovement::TYPE_PURCHASE);
    }

    public function test_it_reverses_a_movement_without_deleting_it_and_keeps_totals_coherent(): void
    {
        [, , $account] = $this->context();
        $original = $this->service->addPoints($account, '500.0000', LoyaltyMovement::TYPE_PURCHASE);

        $reversal = $this->service->reverseMovement($original, LoyaltyMovement::TYPE_VOID, [
            'event_key' => 'sale:1:void',
        ]);

        $this->assertSame($original->id, $reversal->related_movement_id);
        $this->assertSame('-500.0000', $reversal->points);
        $this->assertSame('0.0000', $account->fresh()->balance);
        $this->assertSame('0.0000', $account->fresh()->total_earned);
        $this->assertDatabaseHas('loyalty_movements', ['id' => $original->id]);
        $this->assertDatabaseCount('loyalty_movements', 2);
    }

    public function test_event_key_is_idempotent_within_a_company(): void
    {
        [, , $account] = $this->context();
        $context = ['event_key' => 'sale:44:purchase'];

        $first = $this->service->addPoints($account, '20.0000', LoyaltyMovement::TYPE_PURCHASE, $context);
        $second = $this->service->addPoints($account, '20.0000', LoyaltyMovement::TYPE_PURCHASE, $context);

        $this->assertTrue($first->is($second));
        $this->assertSame('20.0000', $account->fresh()->balance);
        $this->assertDatabaseCount('loyalty_movements', 1);
    }

    public function test_two_companies_have_independent_accounts_and_event_keys(): void
    {
        [$companyA, , $accountA] = $this->context('A');
        [$companyB, , $accountB] = $this->context('B');

        $this->service->addPoints($accountA, 10, LoyaltyMovement::TYPE_PURCHASE, ['event_key' => 'shared']);
        $this->service->addPoints($accountB, 25, LoyaltyMovement::TYPE_PURCHASE, ['event_key' => 'shared']);

        $this->assertNotSame($companyA->id, $companyB->id);
        $this->assertSame('10.0000', $accountA->fresh()->balance);
        $this->assertSame('25.0000', $accountB->fresh()->balance);
    }

    public function test_valid_branch_and_user_are_traced_and_foreign_branch_is_rejected(): void
    {
        [$company, , $account] = $this->context();
        $branch = $this->branch($company);
        $user = User::factory()->create();

        $movement = $this->service->addPoints($account, 5, LoyaltyMovement::TYPE_BIRTHDAY, [
            'branch' => $branch,
            'user' => $user,
            'base_amount' => '100.0000',
            'earning_percentage' => '5.0000',
            'point_value' => '1.0000',
            'source_type' => 'test',
            'source_id' => 7,
            'metadata' => ['channel' => 'service-test'],
        ]);

        $this->assertSame($branch->id, $movement->branch_id);
        $this->assertSame($user->id, $movement->user_id);
        $this->assertSame(['channel' => 'service-test'], $movement->metadata);

        $this->expectException(ValidationException::class);
        $this->service->addPoints($account, 1, LoyaltyMovement::TYPE_PROMOTION, [
            'branch' => $this->branch($this->company('Foreign')),
        ]);
    }

    public function test_adjustments_support_both_directions_without_inflating_totals(): void
    {
        [, , $account] = $this->context();
        $this->service->adjustPoints($account, '10.5000');
        $this->service->adjustPoints($account, '-0.5000');

        $balance = $this->service->getBalance($account);
        $this->assertSame('10.0000', $balance['balance']);
        $this->assertSame('0.0000', $balance['total_earned']);
        $this->assertSame('0.0000', $balance['total_redeemed']);
        $this->assertSame('0.0000', $balance['total_expired']);
    }

    private function context(string $name = 'Empresa'): array
    {
        $company = $this->company($name);
        $customer = $this->customer($company);

        return [$company, $customer, $this->service->getOrCreateAccount($customer, $company)];
    }

    private function company(string $name = 'Empresa'): Company
    {
        return Company::create([
            'trade_name' => $name.' '.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
    }

    private function customer(Company $company): Customer
    {
        return Customer::create([
            'company_id' => $company->id,
            'customer_type' => 'individual',
            'name' => 'Cliente '.uniqid(),
            'credit_limit' => 0,
            'is_active' => true,
        ]);
    }

    private function branch(Company $company): Branch
    {
        return Branch::create([
            'company_id' => $company->id,
            'name' => 'Sucursal '.uniqid(),
            'code' => strtoupper(substr(uniqid(), -6)),
            'is_active' => true,
        ]);
    }
}
