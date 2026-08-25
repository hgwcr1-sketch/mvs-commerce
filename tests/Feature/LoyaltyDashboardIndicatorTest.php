<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Loyalty\LoyaltyDashboardIndicatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyDashboardIndicatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_indicators_aggregate_company_accounts_with_decimal_precision(): void
    {
        [$company] = $this->context();

        $this->account($company, '10.1234', '20.1234', '5.0000', '2.0000');
        $this->account($company, '0.8766', '9.8766', '1.2500', '3.5000');

        $this->assertSame([
            'customers' => 2,
            'total_earned' => '30.0000',
            'total_redeemed' => '6.2500',
            'total_expired' => '5.5000',
            'balance' => '11.0000',
        ], app(LoyaltyDashboardIndicatorService::class)->forCompany($company));
    }

    public function test_dashboard_displays_company_indicators_without_foreign_data(): void
    {
        [$company, $branch, $user] = $this->context();
        [$foreignCompany] = $this->context();

        $this->account($company, '123.4567', '200.0000', '50.0000', '26.5433');
        $this->account($foreignCompany, '9999.9999', '9999.9999', '9999.9999', '9999.9999');

        $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->get(route('loyalty.dashboard'))
            ->assertOk()
            ->assertSeeInOrder([
                'Indicadores acumulados',
                'Clientes con cuenta',
                '1',
                'Puntos generados',
                '200.0000',
                'Puntos canjeados',
                '50.0000',
                'Puntos vencidos',
                '26.5433',
                'Saldo vigente',
                '123.4567',
            ])
            ->assertDontSee('9999.9999');
    }

    public function test_empty_company_has_zero_indicators(): void
    {
        [$company] = $this->context();

        $this->assertSame([
            'customers' => 0,
            'total_earned' => '0.0000',
            'total_redeemed' => '0.0000',
            'total_expired' => '0.0000',
            'balance' => '0.0000',
        ], app(LoyaltyDashboardIndicatorService::class)->forCompany($company));
    }

    private function context(): array
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
        $permission = Permission::firstOrCreate(
            ['name' => 'fidelidad.dashboard'],
            ['label' => 'Ver dashboard de Fidelidad', 'module' => 'Fidelidad', 'is_active' => true],
        );
        $role = Role::create([
            'company_id' => $company->id,
            'name' => 'Dashboard '.uniqid(),
            'is_active' => true,
        ]);
        $role->permissions()->attach($permission);
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return [$company, $branch, $user];
    }

    private function account(
        Company $company,
        string $balance,
        string $earned,
        string $redeemed,
        string $expired,
    ): LoyaltyAccount {
        $customer = Customer::create([
            'company_id' => $company->id,
            'name' => 'Cliente '.uniqid(),
            'customer_type' => 'individual',
            'is_active' => true,
        ]);

        return LoyaltyAccount::create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'balance' => $balance,
            'total_earned' => $earned,
            'total_redeemed' => $redeemed,
            'total_expired' => $expired,
            'is_active' => true,
        ]);
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return [
            'active_company_id' => $company->id,
            'active_branch_id' => $branch->id,
        ];
    }
}
