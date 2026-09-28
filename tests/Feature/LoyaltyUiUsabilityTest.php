<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyUiUsabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_opportunities_have_mobile_cards_desktop_table_and_touch_safe_actions(): void
    {
        CarbonImmutable::setTestNow('2026-08-26 12:00:00');
        [$company, $branch, $user] = $this->context([
            'fidelidad.oportunidades',
            'fidelidad.contactar',
        ]);
        $customer = Customer::create([
            'company_id' => $company->id,
            'name' => 'Cliente móvil',
            'customer_type' => 'individual',
            'is_active' => true,
        ]);
        LoyaltyAccount::create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'balance' => '25.0000',
            'last_qualifying_purchase_at' => now()->subDays(40),
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->get(route('loyalty.opportunities.index'))
            ->assertOk()
            ->assertSee('data-mobile-opportunity-card', false)
            ->assertSee('data-desktop-opportunities', false)
            ->assertSee('space-y-3 md:hidden', false)
            ->assertSee('hidden overflow-hidden', false)
            ->assertSee('md:block', false)
            ->assertSee('min-h-11 w-full', false)
            ->assertSee('overflow-x-auto', false);

        CarbonImmutable::setTestNow();
    }

    public function test_dashboard_header_stacks_at_360_and_preserves_desktop_layout(): void
    {
        [$company, $branch, $user] = $this->context([
            'fidelidad.dashboard',
            'fidelidad.oportunidades',
        ]);

        $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->get(route('loyalty.dashboard'))
            ->assertOk()
            ->assertSee('flex flex-col gap-3 sm:flex-row', false)
            ->assertSee('min-h-11 w-full', false)
            ->assertSee('sm:w-auto', false)
            ->assertSee('sm:grid-cols-2 xl:grid-cols-5', false)
            ->assertSee('space-y-3 md:hidden', false)
            ->assertSee('overflow-x-auto', false);
    }

    private function context(array $permissions): array
    {
        $company = Company::create([
            'trade_name' => 'Empresa '.uniqid(),
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
        $branch = Branch::create([
            'company_id' => $company->id,
            'name' => 'Principal',
            'code' => strtoupper(substr(uniqid(), -6)),
            'is_active' => true,
        ]);
        $role = Role::create([
            'company_id' => $company->id,
            'name' => 'UI '.uniqid(),
            'is_active' => true,
        ]);

        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['label' => $name, 'module' => 'Fidelidad', 'is_active' => true],
            );
            $role->permissions()->attach($permission);
        }

        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return [$company, $branch, $user];
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return [
            'active_company_id' => $company->id,
            'active_branch_id' => $branch->id,
        ];
    }
}
