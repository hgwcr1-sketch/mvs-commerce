<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltySetting;
use App\Services\Loyalty\LoyaltyExpirationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class LoyaltyExpirationTest extends TestCase
{
    use RefreshDatabase;

    public function test_expires_exact_balance_after_configured_months_with_coherent_kardex(): void
    {
        [$company, $setting] = $this->context(2);
        $customer = $this->account($company, '2026-06-15 10:00:00', '250.0000');
        $account = LoyaltyAccount::query()->where('customer_id', $customer->id)->firstOrFail();

        $movement = app(LoyaltyExpirationService::class)
            ->expireAccount($company, $account, '2026-08-15 09:30:00');

        $this->assertNotNull($movement);
        $this->assertSame(LoyaltyMovement::TYPE_EXPIRATION, $movement->type);
        $this->assertSame('-250.0000', $movement->points);
        $this->assertSame('250.0000', $movement->balance_before);
        $this->assertSame('0.0000', $movement->balance_after);
        $this->assertSame('0.0000', $account->fresh()->balance);
        $this->assertSame('250.0000', $account->fresh()->total_expired);
        $this->assertSame("expiration:{$account->id}:2026-08-15", $movement->event_key);
        $this->assertSame('Vencimiento de puntos por inactividad', $movement->description);
        $this->assertSame('loyalty_expiration', $movement->source_type);
        $this->assertNull($movement->user_id);
        $this->assertNull($movement->branch_id);
        $this->assertSame('2026-08-15', $movement->metadata['due_date']);
        $this->assertSame(2, $movement->metadata['expiration_months']);
        $this->assertStringStartsWith('2026-06-15T00:00:00', $movement->metadata['last_qualifying_purchase_at']);
        $this->assertSame($company->id, $movement->company_id);
        $this->assertSame($customer->id, $movement->customer_id);
        $this->assertDatabaseHas('loyalty_movements', ['id' => $movement->id, 'type' => 'expiration']);
    }

    public function test_day_before_due_does_not_expire_but_exact_day_does(): void
    {
        [$company] = $this->context(1);
        $service = app(LoyaltyExpirationService::class);
        $customer = $this->account($company, '2026-07-15 10:00:00', '100.0000');
        $account = LoyaltyAccount::query()->where('customer_id', $customer->id)->firstOrFail();

        $this->assertNull($service->expireAccount($company, $account, '2026-08-14 23:59:59'));
        $this->assertDatabaseCount('loyalty_movements', 0);
        $this->assertSame('100.0000', $account->fresh()->balance);

        $movement = $service->expireAccount($company, $account, '2026-08-15 00:00:00');
        $this->assertNotNull($movement);
        $this->assertSame("expiration:{$account->id}:2026-08-15", $movement->event_key);
        $this->assertDatabaseCount('loyalty_movements', 1);
    }

    public function test_month_end_addition_uses_last_valid_day_without_overflow(): void
    {
        [$company] = $this->context(1);
        $service = app(LoyaltyExpirationService::class);
        $customer = $this->account($company, '2026-01-31 12:00:00', '80.0000');
        $account = LoyaltyAccount::query()->where('customer_id', $customer->id)->firstOrFail();

        $this->assertNull($service->expireAccount($company, $account, '2026-02-27 23:59:59'));

        $movement = $service->expireAccount($company, $account, '2026-02-28 08:00:00');

        $this->assertNotNull($movement);
        $this->assertSame("expiration:{$account->id}:2026-02-28", $movement->event_key);
        $this->assertSame('-80.0000', $movement->points);
    }

    public function test_recent_purchase_does_not_expire_and_new_activity_resets_the_window(): void
    {
        [$company] = $this->context(1);
        $service = app(LoyaltyExpirationService::class);
        $customer = $this->account($company, '2026-06-15 10:00:00', '120.0000');
        $account = LoyaltyAccount::query()->where('customer_id', $customer->id)->firstOrFail();

        $this->assertNull($service->expireAccount($company, $account, '2026-07-14 23:59:59'));
        $this->assertNull($service->expireAccount($company, $account, '2026-07-02 10:00:00'));

        $account->update(['last_qualifying_purchase_at' => '2026-06-30 18:00:00']);
        $this->assertNull($service->expireAccount($company, $account, '2026-07-29 10:00:00'));
        $this->assertDatabaseCount('loyalty_movements', 0);
        $this->assertSame('120.0000', $account->fresh()->balance);
    }

    public function test_double_execution_for_same_period_does_not_duplicate_anything(): void
    {
        [$company] = $this->context(1);
        $service = app(LoyaltyExpirationService::class);
        $customer = $this->account($company, '2024-03-10 10:00:00', '90.5000');
        $account = LoyaltyAccount::query()->where('customer_id', $customer->id)->firstOrFail();

        $first = $service->process();
        $duplicateCall = $service->expireAccount($company, $account, '2026-08-23 10:00:00');
        $second = $service->process();

        $this->assertSame(1, $first['expired_accounts']);
        $this->assertSame('90.5000', $first['expired_points']);
        $this->assertNull($service->expireAccount($company, $account, '2026-08-23 10:00:00'));
        $this->assertDatabaseHas('loyalty_movements', ['event_key' => "expiration:{$account->id}:2024-04-10"]);
        $this->assertSame(0, $second['expired_accounts']);
        $this->assertSame(0, $second['skipped']);
        $this->assertDatabaseCount('loyalty_movements', 1);
        $this->assertSame('0.0000', $account->fresh()->balance);
        $this->assertSame('90.5000', $account->fresh()->total_expired);
    }

    public function test_new_inactivity_period_produces_a_different_event_key(): void
    {
        [$company] = $this->context(1);
        $service = app(LoyaltyExpirationService::class);
        $customer = $this->account($company, '2024-03-10 10:00:00', '200.0000');
        $account = LoyaltyAccount::query()->where('customer_id', $customer->id)->firstOrFail();

        $first = $service->process();

        $account->update([
            'balance' => '400.0000',
            'total_earned' => '600.0000',
            'last_qualifying_purchase_at' => '2026-07-10 12:00:00',
        ]);

        $result = $service->process();

        $this->assertSame(1, $first['expired_accounts']);
        $this->assertSame(1, $result['expired_accounts']);
        $movements = LoyaltyMovement::query()->orderBy('id')->get();
        $this->assertCount(2, $movements);
        $this->assertNotSame($movements[0]->event_key, $movements[1]->event_key);
        $this->assertSame("expiration:{$account->id}:2026-08-10", $movements[1]->event_key);
        $this->assertSame('600.0000', $account->fresh()->total_expired);
    }

    public function test_null_last_qualifying_purchase_never_expires(): void
    {
        [$company] = $this->context(1);
        $this->account($company, null, '150.0000');

        $result = app(LoyaltyExpirationService::class)->process();

        $this->assertSame(0, $result['expired_accounts']);
        $this->assertSame(1, $result['skipped']);
        $this->assertDatabaseCount('loyalty_movements', 0);
    }

    public function test_zero_balance_creates_no_movement(): void
    {
        [$company] = $this->context(1);
        $this->account($company, '2024-01-01 10:00:00', '0.0000');

        $result = app(LoyaltyExpirationService::class)->process();

        $this->assertSame(0, $result['expired_accounts']);
        $this->assertSame(0, $result['skipped']);
        $this->assertDatabaseCount('loyalty_movements', 0);
    }

    public function test_disabled_expiration_or_missing_eligible_setting_does_nothing(): void
    {
        [$company, $setting] = $this->context(1);
        $customer = $this->account($company, '2024-01-01 10:00:00', '70.0000');
        $account = LoyaltyAccount::query()->where('customer_id', $customer->id)->firstOrFail();
        $service = app(LoyaltyExpirationService::class);

        $setting->update(['expiration_enabled' => false]);
        $this->assertNull($service->expireAccount($company, $account));
        $result = $service->process();
        $this->assertSame(0, $result['expired_accounts']);

        $setting->update(['expiration_enabled' => true]);
        $this->assertSame(1, $service->process()['expired_accounts']);

        [$otherCompany] = $this->context(1);
        $otherCustomer = $this->account($otherCompany, '2024-01-01 10:00:00', '70.0000');
        LoyaltySetting::query()->where('company_id', $otherCompany->id)->delete();
        $this->assertNull($service->expireAccount($otherCompany, LoyaltyAccount::query()->where('customer_id', $otherCustomer->id)->firstOrFail()));

        [$inactiveModuleCompany, $inactiveSetting] = $this->context(1);
        $inactiveCustomer = $this->account($inactiveModuleCompany, '2024-01-01 10:00:00', '50.0000');
        $inactiveSetting->update(['is_active' => false]);
        $result = $service->process();
        $this->assertSame(0, $result['expired_accounts']);
        $this->assertDatabaseMissing('loyalty_movements', ['customer_id' => $inactiveCustomer->id]);
        $this->assertDatabaseHas('loyalty_accounts', ['customer_id' => $otherCustomer->id, 'balance' => '70.0000']);
    }

    public function test_processing_is_scoped_to_the_requested_company(): void
    {
        $service = app(LoyaltyExpirationService::class);

        [$companyA] = $this->context(1);
        $customerA = $this->account($companyA, '2024-01-01 10:00:00', '111.0000');
        [$companyB] = $this->context(2);
        $customerB = $this->account($companyB, '2024-01-01 10:00:00', '222.0000');

        $scoped = $service->process($companyA->id);

        $this->assertSame(1, $scoped['expired_accounts']);
        $this->assertSame('111.0000', $scoped['expired_points']);
        $this->assertDatabaseHas('loyalty_accounts', ['customer_id' => $customerB->id, 'balance' => '222.0000']);
        $this->assertDatabaseMissing('loyalty_movements', ['company_id' => $companyB->id]);

        $full = $service->process();

        $this->assertSame(1, $full['expired_accounts']);
        $this->assertSame('222.0000', $full['expired_points']);
        $this->assertDatabaseHas('loyalty_movements', ['company_id' => $companyB->id, 'type' => 'expiration']);
    }

    public function test_due_date_follows_company_local_days_even_when_utc_date_differs(): void
    {
        [$company] = $this->context(1);
        $service = app(LoyaltyExpirationService::class);
        $customer = $this->account($company, '2026-05-31 23:30:00', '60.0000');
        $account = LoyaltyAccount::query()->where('customer_id', $customer->id)->firstOrFail();

        $beforeBoundary = $service->expireAccount($company, $account, '2026-06-29 23:00:00');
        $afterBoundary = $service->expireAccount($company, $account, '2026-06-30 00:30:00');

        $this->assertNull($beforeBoundary);
        $this->assertNotNull($afterBoundary);
        $this->assertSame("expiration:{$account->id}:2026-06-30", $afterBoundary->event_key);
        $this->assertSame('2026-06-30', $afterBoundary->metadata['due_date']);
    }

    public function test_command_reports_counters_and_returns_success(): void
    {
        [$company] = $this->context(1);
        $this->account($company, '2024-01-01 10:00:00', '300.0000');
        $this->account($company, '2026-08-20 10:00:00', '999.0000');

        $exitCode = Artisan::call('loyalty:expire-points');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Cuentas vencidas: 1', $output);
        $this->assertStringContainsString('puntos vencidos: 300.0000', $output);
        $this->assertStringContainsString('omitidas: 1', $output);
        $this->assertDatabaseCount('loyalty_movements', 1);
    }

    public function test_scheduler_registers_the_daily_expiration_once(): void
    {
        $contents = file_get_contents(base_path('routes/console.php'));

        $this->assertSame(1, substr_count($contents, "Schedule::command('loyalty:expire-points')"));
    }

    private function context(int $months = 1, bool $enabled = true): array
    {
        $company = Company::create(['trade_name' => 'Empresa '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $setting = LoyaltySetting::create([
            'company_id' => $company->id,
            'is_active' => true,
            'earning_percentage' => '5.0000',
            'point_value' => '1.0000',
            'expiration_enabled' => $enabled,
            'expiration_months' => $enabled ? $months : null,
        ]);

        return [$company, $setting];
    }

    private function account(Company $company, ?string $lastPurchase, string $balance): Customer
    {
        $customer = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente '.uniqid(), 'is_active' => true]);
        LoyaltyAccount::create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'balance' => $balance,
            'total_earned' => $balance,
            'total_redeemed' => '0.0000',
            'total_expired' => '0.0000',
            'last_qualifying_purchase_at' => $lastPurchase,
        ]);

        return $customer;
    }
}
