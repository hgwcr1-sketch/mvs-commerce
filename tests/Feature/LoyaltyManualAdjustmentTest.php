<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyMovement;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoyaltyManualAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_adds_points_manually_with_full_traceability(): void
    {
        [$company, $branch, $user, $customer] = $this->context();

        $response = $this->submit($company, $branch, $user, [
            'customer_id' => $customer->id,
            'direction' => 'sumar',
            'points' => '125.5',
            'reason' => 'Compensación por error de caja',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $movement = LoyaltyMovement::query()->where('type', LoyaltyMovement::TYPE_ADJUSTMENT)->firstOrFail();
        $this->assertSame('125.5000', (string) $movement->points);
        $this->assertSame('125.5000', (string) $movement->balance_after);
        $this->assertSame($company->id, $movement->company_id);
        $this->assertSame($customer->id, $movement->customer_id);
        $this->assertSame($user->id, $movement->user_id);
        $this->assertSame($branch->id, $movement->branch_id);
        $this->assertSame('Compensación por error de caja', $movement->description);
        $this->assertSame('sumar', $movement->metadata['direction']);
        $this->assertSame('Compensación por error de caja', $movement->metadata['reason']);

        $account = LoyaltyAccount::query()->where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('125.5000', (string) $account->balance);
        $this->assertSame('0.0000', (string) $account->total_earned);
    }

    public function test_subtracts_points_without_touching_earned_totals(): void
    {
        [$company, $branch, $user, $customer] = $this->context();
        LoyaltyAccount::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'balance' => '100.0000', 'total_earned' => '100.0000', 'total_redeemed' => '0.0000', 'total_expired' => '0.0000']);

        $this->submit($company, $branch, $user, [
            'customer_id' => $customer->id,
            'direction' => 'restar',
            'points' => '40',
            'reason' => 'Corrección por devolución administrativa',
        ])->assertRedirect()->assertSessionHas('success');

        $movement = LoyaltyMovement::query()->where('type', LoyaltyMovement::TYPE_ADJUSTMENT)->firstOrFail();
        $this->assertSame('-40.0000', (string) $movement->points);
        $this->assertSame('100.0000', (string) $movement->balance_before);
        $this->assertSame('60.0000', (string) $movement->balance_after);

        $account = LoyaltyAccount::query()->where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('60.0000', (string) $account->balance);
        $this->assertSame('100.0000', (string) $account->total_earned);
    }

    public function test_reason_is_required_and_rejects_the_submission(): void
    {
        [$company, $branch, $user] = $this->context();

        $this->submit($company, $branch, $user, [
            'direction' => 'sumar',
            'points' => '10',
            'reason' => '',
        ])->assertRedirect()->assertSessionHasErrors('reason');

        $this->assertDatabaseCount('loyalty_movements', 0);
    }

    public function test_adjustment_movement_is_visible_in_kardex_data(): void
    {
        [$company, $branch, $user, $customer] = $this->context();

        $this->submit($company, $branch, $user, [
            'customer_id' => $customer->id,
            'direction' => 'sumar',
            'points' => '15',
            'reason' => 'Ajuste de cortesía',
        ])->assertRedirect();

        $kardexMovement = LoyaltyMovement::query()
            ->where('company_id', $company->id)
            ->where('type', LoyaltyMovement::TYPE_ADJUSTMENT)
            ->with(['customer', 'user', 'branch'])
            ->firstOrFail();

        $this->assertSame($customer->id, $kardexMovement->customer_id);
        $this->assertSame($branch->id, $kardexMovement->branch_id);
        $this->assertSame($user->id, $kardexMovement->user_id);
    }

    public function test_balance_can_never_go_negative(): void
    {
        [$company, $branch, $user, $customer] = $this->context();
        LoyaltyAccount::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'balance' => '30.0000', 'total_earned' => '30.0000', 'total_redeemed' => '0.0000', 'total_expired' => '0.0000']);

        $response = $this->submit($company, $branch, $user, [
            'direction' => 'restar',
            'points' => '50',
            'reason' => 'Resta mayor que el saldo',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors();

        $this->assertDatabaseCount('loyalty_movements', 0);
        $this->assertSame('30.0000', (string) LoyaltyAccount::query()->where('customer_id', $customer->id)->value('balance'));
    }

    public function test_invalid_amounts_are_rejected(): void
    {
        [$company, $branch, $user, $customer] = $this->context();

        foreach (['0', '-5', 'abc', '1.23456'] as $invalidPoints) {
            $this->submit($company, $branch, $user, [
                'direction' => 'sumar',
                'points' => $invalidPoints,
                'reason' => 'Intento inválido',
            ])->assertRedirect()->assertSessionHasErrors('points');
        }

        $this->assertDatabaseCount('loyalty_movements', 0);
        $this->assertNull(LoyaltyAccount::query()->where('customer_id', $customer->id)->value('balance'));
    }

    public function test_amounts_keep_four_decimal_precision(): void
    {
        [$company, $branch, $user, $customer] = $this->context();

        $this->submit($company, $branch, $user, [
            'customer_id' => $customer->id,
            'direction' => 'sumar',
            'points' => '12.3456',
            'reason' => 'Precisión decimal',
        ])->assertRedirect();

        $this->assertSame('12.3456', (string) LoyaltyMovement::query()->where('type', LoyaltyMovement::TYPE_ADJUSTMENT)->value('points'));
    }

    public function test_cross_company_customers_are_blocked(): void
    {
        [$companyA, $branchA, $userA] = $this->context();
        [$companyB, $branchB, $userB, $foreignCustomer] = $this->context();

        $response = $this->actingAs($userA)
            ->withSession(['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id])
            ->post(route('loyalty.adjustments.store'), [
                'customer_id' => $foreignCustomer->id,
                'direction' => 'sumar',
                'points' => '25',
                'reason' => 'Intento cruzado',
                'event_token' => (string) Str::uuid(),
            ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('customer_id');
        $this->assertDatabaseCount('loyalty_movements', 0);
    }

    public function test_users_without_permission_cannot_access_or_adjust(): void
    {
        [$company, $branch, $user, $customer] = $this->context(['fidelidad.ver']);

        $session = ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];

        $this->actingAs($user)->withSession($session)->get(route('loyalty.adjustments.index'))->assertForbidden();

        $this->actingAs($user)->withSession($session)->post(route('loyalty.adjustments.store'), [
            'customer_id' => $customer->id,
            'direction' => 'sumar',
            'points' => '10',
            'reason' => 'Sin permiso',
            'event_token' => (string) Str::uuid(),
        ])->assertForbidden();

        $this->assertDatabaseCount('loyalty_movements', 0);
    }

    public function test_double_submission_with_same_token_does_not_duplicate(): void
    {
        [$company, $branch, $user, $customer] = $this->context();
        $token = (string) Str::uuid();

        $payload = [
            'customer_id' => $customer->id,
            'direction' => 'sumar',
            'points' => '50',
            'reason' => 'Doble envío controlado',
        ];

        $this->submit($company, $branch, $user, $payload, $token)->assertRedirect();
        $this->submit($company, $branch, $user, $payload, $token)->assertRedirect();

        $movements = LoyaltyMovement::query()->where('type', LoyaltyMovement::TYPE_ADJUSTMENT)->get();
        $this->assertCount(1, $movements);
        $this->assertSame('adjustment:'.$token, $movements[0]->event_key);
        $this->assertSame('50.0000', (string) LoyaltyAccount::query()->where('customer_id', $customer->id)->value('balance'));
    }

    private function context(array $permissions = ['fidelidad.ajustes']): array
    {
        $company = Company::create(['trade_name' => 'Empresa '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => strtoupper(substr(uniqid(), -6)), 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Test', 'is_active' => true]);
            $role->permissions()->attach($permission->id);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        $customer = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente '.uniqid(), 'is_active' => true]);

        return [$company, $branch, $user, $customer];
    }

    private function submit(Company $company, Branch $branch, User $user, array $overrides, ?string $token = null)
    {
        return $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->post(route('loyalty.adjustments.store'), array_merge([
                'event_token' => $token ?? (string) Str::uuid(),
            ], $overrides));
    }
}
