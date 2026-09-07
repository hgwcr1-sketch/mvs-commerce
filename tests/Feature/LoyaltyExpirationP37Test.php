<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltySetting;
use App\Models\User;
use App\Services\Imports\LoyaltyMigrationImportService;
use App\Services\Loyalty\LoyaltyCustomerPortalService;
use App\Services\Loyalty\LoyaltyEarningService;
use App\Services\Loyalty\LoyaltyExpirationPolicyService;
use App\Services\Loyalty\LoyaltyExpirationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LoyaltyExpirationP37Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-09-07 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_legacy_portal_and_expiration_share_date_and_do_not_invent_a_purchase(): void
    {
        [$company, $setting, $customer, $account] = $this->context();
        $legacy = $this->legacy($account);
        $original = $legacy->fresh()->getAttributes();
        $data = app(LoyaltyCustomerPortalService::class)->data($company, $customer);
        $this->assertSame('2026-10-02', $data['expiration']['date']->toDateString());
        $this->assertSame(25, $data['expiration']['days']);
        $html = view('loyalty.portal.show', $data)->render();
        $this->assertStringContainsString('97 puntos vencen en 25 días', $html);
        $this->assertStringContainsString('02/10/2026', $html);
        $service = app(LoyaltyExpirationService::class);
        $this->assertNull($service->expireAccount($company, $account, '2026-10-01 23:59:59'));
        $movement = $service->expireAccount($company, $account, '2026-10-02');
        $this->assertSame($data['expiration']['date']->toDateString(), $movement->metadata['due_date']);
        $this->assertSame('legacy_initial_balance', $movement->metadata['reference_source']);
        $this->assertSame($legacy->id, $movement->metadata['legacy_movement_id']);
        $this->assertNull($movement->metadata['last_qualifying_purchase_at']);
        $this->assertNull($account->fresh()->last_qualifying_purchase_at);
        $this->assertSame($original, $legacy->fresh()->getAttributes());
        $this->assertSame('-97.0000', $movement->points);
        $this->assertNull($service->expireAccount($company, $account, '2026-10-03'));
        $this->assertSame(1, LoyaltyMovement::where('type', 'expiration')->count());
        $this->assertNull(app(LoyaltyCustomerPortalService::class)->data($company, $customer)['expiration']);
    }

    public function test_real_p37_import_then_purchase_renews_and_expires_the_whole_balance(): void
    {
        [$company, $setting, $customer, $account] = $this->context();
        $account->delete(); // Fixture vacía: el importador debe crear la cuenta real.
        $user = User::factory()->create();
        $path = tempnam(sys_get_temp_dir(), 'p37-expiration-');
        try {
            file_put_contents($path, "NOMBRE,PUNTOS OTORGADOS,PUNTOS UTILIZADOS,SALDO\n{$customer->name},0,0,97\n");
            $this->travelTo(CarbonImmutable::parse('2026-09-02 12:00:00', 'UTC'));
            $importer = app(LoyaltyMigrationImportService::class);
            $preview = $importer->preview($path, $company->id);
            $this->assertSame(1, $importer->confirm($preview, $company->id, $user->id));
        } finally {
            unlink($path);
        }
        $account = LoyaltyAccount::where('customer_id', $customer->id)->sole();
        $data = app(LoyaltyCustomerPortalService::class)->data($company, $customer);
        $this->assertSame('legacy_initial_balance', $data['expiration']['reference_source']);
        $this->assertSame('2026-10-02', $data['expiration']['date']->toDateString());
        $this->purchase($company, $customer, '2026-09-20 12:00:00');
        $data = app(LoyaltyCustomerPortalService::class)->data($company, $customer);
        $this->assertSame('qualifying_purchase', $data['expiration']['reference_source']);
        $this->assertSame('2026-10-20', $data['expiration']['date']->toDateString());
        $this->assertSame('147.0000', $data['balance_points']);
        $this->assertNull(app(LoyaltyExpirationService::class)->expireAccount($company, $account, '2026-10-02'));
        $movement = app(LoyaltyExpirationService::class)->expireAccount($company, $account, '2026-10-20');
        $this->assertSame('-147.0000', $movement->points);
        $this->assertSame('qualifying_purchase', $movement->metadata['reference_source']);
        $this->assertSame('0.0000', $account->fresh()->balance);
    }

    public function test_purchase_after_legacy_deadline_wins_even_with_stale_account_instance(): void
    {
        [$company, $setting, $customer, $stale] = $this->context();
        $this->legacy($stale);
        // Una compra confirmada entre lectura inicial y bloqueo de expiración.
        $this->purchase($company, $customer, '2026-10-03 12:00:00');
        $this->assertNull($stale->last_qualifying_purchase_at);
        $this->assertNull(app(LoyaltyExpirationService::class)->expireAccount($company, $stale, '2026-10-03'));
        $movement = app(LoyaltyExpirationService::class)->expireAccount($company, $stale, '2026-11-03');
        $this->assertSame('2026-11-03', $movement->metadata['due_date']);
        $this->assertSame('-147.0000', $movement->points);
    }

    public function test_purchase_after_executed_expiration_does_not_restore_legacy_points(): void
    {
        [$company, $setting, $customer, $account] = $this->context();
        $this->legacy($account);
        app(LoyaltyExpirationService::class)->expireAccount($company, $account, '2026-10-02');
        $this->purchase($company, $customer, '2026-10-03 12:00:00');
        $this->assertSame('50.0000', $account->fresh()->balance);
        $movement = app(LoyaltyExpirationService::class)->expireAccount($company, $account, '2026-11-03');
        $this->assertSame('-50.0000', $movement->points);
        $this->assertSame(2, LoyaltyMovement::where('type', 'expiration')->count());
    }

    #[DataProvider('invalidReferences')]
    public function test_non_p37_or_invalid_reference_does_not_expire(array $changes): void
    {
        [$company, $setting, $customer, $account] = $this->context();
        $this->legacy($account, $changes);
        $account->update(['last_activity_at' => '2020-01-01']);
        $this->assertNull(app(LoyaltyExpirationPolicyService::class)->resolve($company, $setting, $account));
        $this->assertNull(app(LoyaltyExpirationService::class)->expireAccount($company, $account, '2030-01-01'));
        $this->assertSame('97.0000', $account->fresh()->balance);
    }

    public static function invalidReferences(): array
    {
        return [
            'source' => [['source_type' => 'manual']],
            'migration' => [['metadata' => ['migration' => 'P36', 'kind' => 'legacy_initial_balance']]],
            'kind' => [['metadata' => ['migration' => 'P37', 'kind' => 'awarded']]],
            'missing metadata' => [['metadata' => null]],
            'negative' => [['points' => '-97.0000']],
            'zero' => [['points' => '0.0000']],
            'not adjustment' => [['type' => 'promotion']],
        ];
    }

    public function test_ambiguous_references_are_not_selected_arbitrarily(): void
    {
        [$company, $setting, $customer, $account] = $this->context();
        $this->legacy($account);
        $this->legacy($account, ['effective_at' => '2026-09-03 12:00:00']);
        $this->assertNull(app(LoyaltyExpirationPolicyService::class)->resolve($company, $setting, $account));
        $this->assertNull(app(LoyaltyExpirationService::class)->expireAccount($company, $account, '2030-01-01'));
        $this->purchase($company, $customer, '2026-09-10 12:00:00');
        $this->assertSame('qualifying_purchase', app(LoyaltyExpirationPolicyService::class)->resolve($company, $setting, $account->fresh())['reference_source']);
    }

    public function test_foreign_company_account_and_customer_references_are_rejected(): void
    {
        [$company, $setting, $customer, $account] = $this->context();
        [$other, $otherSetting, $otherCustomer, $otherAccount] = $this->context();
        $this->legacy($account, ['company_id' => $other->id]);
        $this->legacy($account, ['customer_id' => $otherCustomer->id]);
        $this->legacy($account, ['loyalty_account_id' => $otherAccount->id]);
        $policy = app(LoyaltyExpirationPolicyService::class);
        $this->assertNull($policy->resolve($company, $setting, $account));
        $this->assertNull($policy->resolve($other, $otherSetting, $account));
        $this->assertNull($policy->resolve($company, $otherSetting, $account));
        $this->assertNull(app(LoyaltyExpirationService::class)->expireAccount($other, $account, '2030-01-01'));
    }

    public function test_disabled_expiration_and_zero_balance_have_no_date_or_expiration(): void
    {
        [$company, $setting, $customer, $account] = $this->context();
        $this->legacy($account);
        $setting->update(['expiration_enabled' => false]);
        $this->assertNull(app(LoyaltyCustomerPortalService::class)->data($company, $customer)['expiration']);
        $this->assertNull(app(LoyaltyExpirationService::class)->expireAccount($company, $account, '2030-01-01'));
        $setting->update(['expiration_enabled' => true]);
        $account->update(['balance' => '0.0000']);
        $this->assertNull(app(LoyaltyCustomerPortalService::class)->data($company, $customer)['expiration']);
        $this->assertNull(app(LoyaltyExpirationService::class)->expireAccount($company, $account, '2030-01-01'));
    }

    public function test_legacy_month_end_uses_company_day_and_no_overflow(): void
    {
        [$company, $setting, $customer, $account] = $this->context();
        // 01-feb UTC sigue siendo 31-ene en Costa Rica.
        $this->legacy($account, ['effective_at' => CarbonImmutable::parse('2026-02-01 02:00:00', 'UTC')]);
        $policy = app(LoyaltyExpirationPolicyService::class)->resolve($company, $setting, $account, '2026-02-27');
        $this->assertSame('2026-01-31', $policy['reference_date']->toDateString());
        $this->assertSame('2026-02-28', $policy['date']->toDateString());
        $this->assertSame(1, $policy['days']);
        $service = app(LoyaltyExpirationService::class);
        $this->assertNull($service->expireAccount($company, $account, CarbonImmutable::parse('2026-02-28 05:59:59', 'UTC')));
        $this->assertSame('2026-02-28', $service->expireAccount($company, $account, CarbonImmutable::parse('2026-02-28 06:00:00', 'UTC'))->metadata['due_date']);
    }

    private function context(): array
    {
        $company = Company::create(['trade_name' => 'P37 '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $setting = LoyaltySetting::create(['company_id' => $company->id, 'is_active' => true, 'expiration_enabled' => true, 'expiration_months' => 1, 'earning_percentage' => '5.0000', 'point_value' => '1.0000']);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Cliente '.uniqid(), 'customer_type' => 'individual', 'is_active' => true]);
        $account = LoyaltyAccount::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'balance' => '97.0000', 'is_active' => true]);

        return [$company, $setting, $customer, $account];
    }

    private function legacy(LoyaltyAccount $account, array $changes = []): LoyaltyMovement
    {
        return LoyaltyMovement::create(array_replace([
            'company_id' => $account->company_id, 'customer_id' => $account->customer_id,
            'loyalty_account_id' => $account->id, 'type' => 'adjustment', 'points' => '97.0000',
            'balance_before' => '0.0000', 'balance_after' => '97.0000', 'source_type' => 'LoyaltyMigration',
            'description' => 'P37 · Saldo inicial legado migrado',
            'effective_at' => '2026-09-02 12:00:00', 'metadata' => ['migration' => 'P37', 'kind' => 'legacy_initial_balance'],
        ], $changes));
    }

    private function purchase(Company $company, Customer $customer, string $date): void
    {
        app(LoyaltyEarningService::class)->earnFromEligibleAmount($customer, $company, '1000.0000', ['effective_at' => $date]);
    }
}
