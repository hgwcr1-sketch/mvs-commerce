<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyMultiplier;
use App\Models\LoyaltySetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Loyalty\LoyaltyEarningService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyMultiplierTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_two_three_and_decimal_multipliers_use_f08_base_formula(): void
    {
        [$company, $customer] = $this->context();
        $service = app(LoyaltyEarningService::class);
        $this->assertSame('50.0000', $service->earnFromEligibleAmount($customer, $company, 1000, ['event_key' => 'normal'])->points);

        foreach (['2.0000' => '100.0000', '3.0000' => '150.0000', '1.5000' => '75.0000'] as $factor => $expected) {
            LoyaltyMultiplier::query()->delete();
            $campaign = $this->multiplier($company, $factor);
            $movement = $service->earnFromEligibleAmount($customer, $company, 1000, ['event_key' => 'factor:'.$factor, 'effective_at' => $this->instant()]);
            $this->assertSame($expected, $movement->points);
            $this->assertSame('5.0000', $movement->earning_percentage);
            $this->assertSame('50.0000', $movement->metadata['base_points']);
            $this->assertSame($factor, $movement->metadata['multiplier']);
            $this->assertSame($campaign->id, $movement->metadata['multiplier_id']);
            $this->assertSame($expected, $movement->metadata['final_points']);
        }
    }

    public function test_validity_is_inclusive_and_inactive_or_outside_period_does_not_apply(): void
    {
        [$company, $customer] = $this->context();
        $campaign = $this->multiplier($company, '2.0000');
        $service = app(LoyaltyEarningService::class);
        $cases = [
            ['2026-08-21 23:59:59', '50.0000'],
            ['2026-08-22 00:00:00', '100.0000'],
            ['2026-08-22 12:00:00', '100.0000'],
            ['2026-08-22 23:59:59', '100.0000'],
            ['2026-08-23 00:00:00', '50.0000'],
        ];
        foreach ($cases as $index => [$instant, $expected]) {
            $movement = $service->earnFromEligibleAmount($customer, $company, 1000, ['event_key' => 'boundary:'.$index, 'effective_at' => CarbonImmutable::parse($instant, 'UTC')]);
            $this->assertSame($expected, $movement->points);
        }
        $campaign->update(['is_active' => false]);
        $this->assertSame('50.0000', $service->earnFromEligibleAmount($customer, $company, 1000, ['event_key' => 'inactive', 'effective_at' => $this->instant()])->points);
    }

    public function test_overlap_uses_only_highest_multiplier_without_stacking(): void
    {
        [$company, $customer] = $this->context();
        $this->multiplier($company, '2.0000');
        $winner = $this->multiplier($company, '3.0000');
        $movement = app(LoyaltyEarningService::class)->earnFromEligibleAmount($customer, $company, 1000, ['effective_at' => $this->instant()]);
        $this->assertSame('150.0000', $movement->points);
        $this->assertSame('3.0000', $movement->metadata['multiplier']);
        $this->assertSame($winner->id, $movement->metadata['multiplier_id']);
    }

    public function test_company_and_branch_scope_are_enforced_and_global_applies_everywhere(): void
    {
        [$company, $customer, $branch] = $this->context();
        [$other] = $this->context();
        $otherBranch = Branch::create(['company_id' => $company->id, 'name' => 'Otra', 'code' => 'OTRA'.uniqid(), 'is_active' => true]);
        $this->multiplier($other, '3.0000');
        $this->multiplier($company, '2.0000', $branch);
        $service = app(LoyaltyEarningService::class);
        $this->assertSame('50.0000', $service->earnFromEligibleAmount($customer, $company, 1000, ['event_key' => 'wrong-branch', 'branch' => $otherBranch, 'effective_at' => $this->instant()])->points);
        $this->multiplier($company, '1.5000');
        $this->assertSame('75.0000', $service->earnFromEligibleAmount($customer, $company, 1000, ['event_key' => 'global', 'branch' => $otherBranch, 'effective_at' => $this->instant()])->points);
    }

    public function test_idempotency_returns_original_snapshot_even_if_multiplier_changes(): void
    {
        [$company, $customer] = $this->context();
        $campaign = $this->multiplier($company, '2.0000');
        $service = app(LoyaltyEarningService::class);
        $context = ['event_key' => 'sale:1:loyalty:earn', 'effective_at' => $this->instant()];
        $first = $service->earnFromEligibleAmount($customer, $company, 1000, $context);
        $campaign->update(['multiplier' => '3.0000']);
        $second = $service->earnFromEligibleAmount($customer, $company, 1000, $context);
        $this->assertTrue($first->is($second));
        $this->assertSame('100.0000', $second->points);
        $this->assertDatabaseCount('loyalty_movements', 1);
    }

    public function test_permission_and_company_isolation_protect_administration(): void
    {
        [$company, , $branch] = $this->context();
        [$other] = $this->context();
        $this->multiplier($other, '3.0000');
        $without = $this->user($company, $branch, []);
        $this->actingAs($without)->withSession($this->activeSession($company, $branch))->get(route('loyalty.multipliers.index'))->assertForbidden();
        $user = $this->user($company, $branch, ['fidelidad.multiplicadores']);
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->get(route('loyalty.multipliers.index'))->assertOk()->assertDontSee('3.0000');
    }

    public function test_administrator_creates_edits_and_toggles_multiplier_with_validation(): void
    {
        [$company, , $branch] = $this->context();
        $user = $this->user($company, $branch, ['fidelidad.multiplicadores']);
        $session = $this->activeSession($company, $branch);
        $payload = ['name' => 'Semana doble', 'multiplier' => '2.5000', 'branch_id' => $branch->id, 'starts_at' => '2026-08-22T08:00', 'ends_at' => '2026-08-23T08:00', 'is_active' => '1'];
        $this->actingAs($user)->withSession($session)->post(route('loyalty.multipliers.store'), $payload)->assertRedirect();
        $multiplier = LoyaltyMultiplier::firstOrFail();
        $this->assertSame('2.5000', $multiplier->multiplier);
        $this->actingAs($user)->withSession($session)->put(route('loyalty.multipliers.update', $multiplier), array_merge($payload, ['name' => 'Semana triple', 'multiplier' => '3']))->assertRedirect();
        $this->assertSame('3.0000', $multiplier->fresh()->multiplier);
        $this->actingAs($user)->withSession($session)->patch(route('loyalty.multipliers.toggle', $multiplier))->assertRedirect();
        $this->assertFalse($multiplier->fresh()->is_active);
        $this->actingAs($user)->withSession($session)->post(route('loyalty.multipliers.store'), array_merge($payload, ['multiplier' => '0']))->assertSessionHasErrors('multiplier');
    }

    private function context(): array
    {
        $company = Company::create(['trade_name' => 'Empresa '.uniqid(), 'timezone' => 'UTC', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => strtoupper(substr(uniqid(), -6)), 'is_active' => true]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Cliente', 'customer_type' => 'individual', 'is_active' => true]);
        LoyaltySetting::create(['company_id' => $company->id, 'is_active' => true, 'earning_percentage' => '5.0000', 'point_value' => '1.0000', 'earn_on_offers' => false]);

        return [$company, $customer, $branch];
    }

    private function multiplier(Company $company, string $factor, ?Branch $branch = null): LoyaltyMultiplier
    {
        return LoyaltyMultiplier::create(['company_id' => $company->id, 'branch_id' => $branch?->id, 'name' => $factor.'x', 'multiplier' => $factor, 'starts_at' => '2026-08-22 00:00:00', 'ends_at' => '2026-08-22 23:59:59', 'is_active' => true]);
    }

    private function instant(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-08-22 12:00:00', 'UTC');
    }

    private function user(Company $company, Branch $branch, array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Fidelidad', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return $user;
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
