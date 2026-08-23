<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltySetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PosLoyaltyInterfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_returns_balance_value_minimum_and_maximum_for_customer_with_account(): void
    {
        [$company, $branch, $user] = $this->context('50.0000');
        $customer = $this->customerWithAccount($company, '5000.0000');

        $response = $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->getJson(route('pos.loyalty.summary', ['customer_id' => $customer->id, 'total' => 1000]));

        $response->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('balance_points', '5000.0000')
            ->assertJsonPath('point_value', '1.0000')
            ->assertJsonPath('minimum_enabled', false)
            ->assertJsonPath('eligible', true)
            ->assertJsonPath('maximum_redemption_percent', '50.0000')
            ->assertJsonPath('max_redeemable_money', '500.0000')
            ->assertJsonPath('max_redeemable_points', '500.0000')
            ->assertJsonPath('offers_allowed', true);
    }

    public function test_summary_reports_minimum_configuration_when_enabled(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customerWithAccount($company);
        LoyaltySetting::query()->where('company_id', $company->id)->update(['redemption_minimum_enabled' => true, 'redemption_minimum_amount' => '2000.0000']);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->getJson(route('pos.loyalty.summary', ['customer_id' => $customer->id, 'total' => 10000]))
            ->assertOk()
            ->assertJsonPath('minimum_enabled', true)
            ->assertJsonPath('minimum_amount', '2000.0000')
            ->assertJsonPath('eligible', true);
    }

    public function test_summary_reports_no_account_and_inactive_loyalty_cleanly(): void
    {
        [$company, $branch, $user] = $this->context();
        $withoutAccount = Customer::create(['company_id' => $company->id, 'name' => 'Sin cuenta '.uniqid(), 'customer_type' => 'individual', 'is_active' => true]);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->getJson(route('pos.loyalty.summary', ['customer_id' => $withoutAccount->id, 'total' => 1000]))
            ->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonPath('reason', 'no_account');

        LoyaltySetting::query()->where('company_id', $company->id)->update(['is_active' => false]);
        $member = $this->customerWithAccount($company);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->getJson(route('pos.loyalty.summary', ['customer_id' => $member->id, 'total' => 1000]))
            ->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonPath('reason', 'inactive');
    }

    public function test_summary_flags_offer_restriction_from_cart_flag(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customerWithAccount($company);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->getJson(route('pos.loyalty.summary', ['customer_id' => $customer->id, 'total' => 1000, 'has_offers' => true]))
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('offers_allowed', false);

        LoyaltySetting::query()->where('company_id', $company->id)->update(['redeem_on_offers' => true]);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->getJson(route('pos.loyalty.summary', ['customer_id' => $customer->id, 'total' => 1000, 'has_offers' => true]))
            ->assertOk()
            ->assertJsonPath('offers_allowed', true);
    }

    public function test_summary_rejects_unknown_or_foreign_customer(): void
    {
        [$company, $branch, $user] = $this->context();
        [$otherCompany] = $this->context(name: 'Otra');
        $foreign = $this->customerWithAccount($otherCompany);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->getJson(route('pos.loyalty.summary', ['customer_id' => $foreign->id, 'total' => 1000]))
            ->assertNotFound();

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->getJson(route('pos.loyalty.summary', ['total' => 1000]))
            ->assertUnprocessable();
    }

    public function test_pos_view_contains_loyalty_panel_and_summary_endpoint_markers(): void
    {
        [$company, $branch, $user] = $this->context();

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('pos.index'))
            ->assertOk()
            ->assertSee('Puntos de fidelización')
            ->assertSee('Saldo')
            ->assertSee('Máximo utilizable')
            ->assertSee('Mínimo requerido')
            ->assertSee('Usar puntos')
            ->assertSee('Valor del canje')
            ->assertSee('Pendiente por pagar')
            ->assertSee('x-model="loyalty.requested"', false)
            ->assertSee('step="0.0001"', false)
            ->assertSee('requested_points', false)
            ->assertSee('refreshLoyalty', false);
    }

    public function test_checkout_contract_still_rejects_client_side_authority_fields(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customerWithAccount($company);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->postJson(route('pos.checkout'), [
                'checkout_token' => (string) Str::uuid(),
                'cash_session_id' => null,
                'customer_id' => $customer->id,
                'payments' => [['payment_method_id' => 999999, 'amount' => 1000]],
                'items' => [['product_id' => 999999, 'quantity' => 1]],
                'redeemed_amount' => 500,
                'point_value' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['redeemed_amount', 'point_value']);
    }

    private function context(string $percentage = '100.0000', string $name = 'Empresa'): array
    {
        $company = Company::create(['trade_name' => $name.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P-'.uniqid(), 'is_active' => true]);
        $user = User::factory()->create();
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach (['pos.acceder', 'ventas.crear'] as $permissionName) {
            $permission = Permission::firstOrCreate(['name' => $permissionName], ['label' => $permissionName, 'module' => 'POS', 'is_active' => true]);
            $role->permissions()->syncWithoutDetaching($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        LoyaltySetting::create(['company_id' => $company->id, 'is_active' => true, 'earning_percentage' => 5, 'point_value' => '1.0000', 'maximum_redemption_percent' => $percentage, 'redeem_on_offers' => false]);

        return [$company, $branch, $user];
    }

    private function customerWithAccount(Company $company, string $balance = '5000.0000'): Customer
    {
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Cliente '.uniqid(), 'customer_type' => 'individual', 'is_active' => true]);
        LoyaltyAccount::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'balance' => $balance]);

        return $customer;
    }
}
