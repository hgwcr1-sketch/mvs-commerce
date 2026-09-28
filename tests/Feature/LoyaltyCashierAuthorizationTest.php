<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltySetting;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\PaymentMethodProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoyaltyCashierAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_can_consult_and_redeem_points_from_pos(): void
    {
        [$company, $branch, $cashier, $customer, $account, $product] = $this->context();

        $this->actingAs($cashier)
            ->withSession($this->activeSession($company, $branch))
            ->getJson(route('pos.loyalty.summary', [
                'customer_id' => $customer->id,
                'total' => 1000,
            ]))
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('balance_points', '5000.0000')
            ->assertJsonPath('max_redeemable_points', '1000.0000');

        $cash = PaymentMethod::forCompany($company->id)
            ->where('type', PaymentMethod::TYPE_CASH)
            ->firstOrFail();
        $cashSession = $this->cashSession($company, $branch, $cashier);

        $this->actingAs($cashier)
            ->withSession($this->activeSession($company, $branch))
            ->postJson(route('pos.checkout'), [
                'checkout_token' => (string) Str::uuid(),
                'cash_session_id' => $cashSession->id,
                'customer_id' => $customer->id,
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'payments' => [[
                    'payment_method_id' => $cash->id,
                    'amount' => 500,
                    'received_amount' => 500,
                    'reference' => null,
                ]],
                'requested_points' => '500',
            ])
            ->assertOk()
            ->assertJsonPath('duplicate', false);

        $redemption = LoyaltyMovement::query()
            ->where('company_id', $company->id)
            ->where('type', LoyaltyMovement::TYPE_REDEMPTION)
            ->firstOrFail();

        $this->assertSame($cashier->id, $redemption->user_id);
        $this->assertSame($branch->id, $redemption->branch_id);
        $this->assertSame('-500.0000', $redemption->points);
        $this->assertSame('4550.0000', $account->fresh()->balance);
        $this->assertSame('500.0000', $account->fresh()->total_redeemed);
    }

    public function test_cashier_cannot_access_loyalty_configuration_or_administration(): void
    {
        [$company, $branch, $cashier] = $this->context();
        $session = $this->activeSession($company, $branch);

        $restrictedRoutes = [
            route('loyalty.settings'),
            route('loyalty.rules.index'),
            route('loyalty.adjustments.index'),
            route('loyalty.multipliers.index'),
            route('loyalty.rewards.index'),
            route('loyalty.redemptions.index'),
            route('loyalty.accesses.index'),
            route('loyalty.promotions.index'),
        ];

        foreach ($restrictedRoutes as $url) {
            $this->actingAs($cashier)
                ->withSession($session)
                ->get($url)
                ->assertForbidden();
        }
    }

    public function test_cashier_sidebar_does_not_expose_loyalty_administration(): void
    {
        [$company, $branch, $cashier] = $this->context();

        $this->actingAs($cashier)->withSession($this->activeSession($company, $branch));
        $html = view('components.navigation.sidebar')->render();

        $this->assertStringNotContainsString('Centro de reglas', $html);
        $this->assertStringNotContainsString('Ajustes de puntos', $html);
        $this->assertStringNotContainsString('Promociones del portal', $html);
    }

    private function context(): array
    {
        $company = Company::create([
            'trade_name' => 'Empresa '.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
        app(PaymentMethodProvisioner::class)->provision($company);

        $branch = Branch::create([
            'company_id' => $company->id,
            'name' => 'Principal',
            'code' => 'P-'.uniqid(),
            'is_active' => true,
        ]);
        $cashier = $this->cashier($company, $branch);

        LoyaltySetting::create([
            'company_id' => $company->id,
            'is_active' => true,
            'earning_percentage' => '5.0000',
            'point_value' => '1.0000',
            'maximum_redemption_percent' => '100.0000',
            'redeem_on_offers' => false,
        ]);

        $suffix = uniqid();
        $category = ProductCategory::create([
            'company_id' => $company->id,
            'name' => 'Categoría '.$suffix,
            'slug' => 'categoria-'.$suffix,
            'is_active' => true,
        ]);
        $unit = Unit::create([
            'company_id' => $company->id,
            'name' => 'Unidad',
            'abbreviation' => 'U',
            'slug' => 'unidad-'.$suffix,
            'is_active' => true,
        ]);
        $product = Product::create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'name' => 'Producto '.$suffix,
            'internal_code' => 'P-'.$suffix,
            'cost' => 500,
            'sale_price' => 1000,
            'tax_rate' => 0,
            'track_inventory' => true,
            'is_active' => true,
        ]);
        DB::table('branch_product')->insert([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'stock' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customer = Customer::create([
            'company_id' => $company->id,
            'name' => 'Cliente '.uniqid(),
            'customer_type' => 'individual',
            'is_active' => true,
        ]);
        $account = LoyaltyAccount::create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'balance' => '5000.0000',
        ]);

        return [$company, $branch, $cashier, $customer, $account, $product];
    }

    private function cashier(Company $company, Branch $branch): User
    {
        $role = Role::create([
            'company_id' => $company->id,
            'name' => 'Cajero',
            'is_active' => true,
        ]);

        foreach (['pos.acceder', 'ventas.crear'] as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['label' => $name, 'module' => 'POS', 'is_active' => true],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return $user;
    }

    private function cashSession(Company $company, Branch $branch, User $cashier): CashSession
    {
        $register = CashRegister::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'code' => 'CAJA-'.uniqid(),
            'name' => 'Caja',
            'is_active' => true,
        ]);

        return CashSession::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'cash_register_id' => $register->id,
            'session_number' => 'CAJA-'.uniqid(),
            'opened_by' => $cashier->id,
            'status' => CashSession::STATUS_OPEN,
            'open_guard' => CashSession::OPEN_GUARD,
            'opening_amount' => 0,
            'opened_at' => now(),
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
