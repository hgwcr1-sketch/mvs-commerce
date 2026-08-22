<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltyMultiplier;
use App\Models\LoyaltySetting;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoyaltyPosIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_sale_earns_once_from_net_subtotal_with_auditable_context(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        $customer = $this->customer($company);
        $product = $this->product($company, 1000);
        $token = (string) Str::uuid();

        $first = $this->checkout($user, $company, $branch, $cash, $product, $customer, $token);
        $second = $this->checkout($user, $company, $branch, $cash, $product, $customer, $token);

        $first->assertOk()->assertJsonPath('duplicate', false);
        $second->assertOk()->assertJsonPath('duplicate', true);
        $sale = Sale::firstOrFail();
        $movement = LoyaltyMovement::firstOrFail();
        $account = LoyaltyAccount::firstOrFail();

        $this->assertSame(Sale::STATUS_COMPLETED, $sale->status);
        $this->assertSame('1000.0000', $sale->subtotal);
        $this->assertSame('50.0000', $movement->points);
        $this->assertSame('1000.0000', $movement->base_amount);
        $this->assertSame($company->id, $movement->company_id);
        $this->assertSame($branch->id, $movement->branch_id);
        $this->assertSame($customer->id, $movement->customer_id);
        $this->assertSame(Sale::class, $movement->source_type);
        $this->assertSame($sale->id, $movement->source_id);
        $this->assertSame("sale:{$sale->id}:loyalty:earn", $movement->event_key);
        $this->assertSame('50.0000', $account->balance);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('loyalty_movements', 1);
    }

    public function test_customer_account_is_global_across_branches(): void
    {
        [$company, $firstBranch, $user, $cash] = $this->context();
        $secondBranch = $this->branch($company, 'Secundaria');
        $user->branches()->attach($secondBranch->id);
        $customer = $this->customer($company);
        $product = $this->product($company, 1000);
        LoyaltySetting::query()->where('company_id', $company->id)->update(['earning_percentage' => '3.0000']);

        $this->checkout($user, $company, $firstBranch, $cash, $product, $customer)->assertOk();
        $this->checkout($user, $company, $secondBranch, $cash, $product, $customer)->assertOk();

        $this->assertDatabaseCount('loyalty_accounts', 1);
        $this->assertSame('60.0000', LoyaltyAccount::firstOrFail()->balance);
        $this->assertSame(['30.0000', '30.0000'], LoyaltyMovement::query()->pluck('points')->all());
        $this->assertEqualsCanonicalizing(
            [$firstBranch->id, $secondBranch->id],
            LoyaltyMovement::query()->pluck('branch_id')->all(),
        );
    }

    public function test_missing_or_disabled_loyalty_does_not_block_completed_sale(): void
    {
        [$company, $branch, $user, $cash] = $this->context(false);
        $customer = $this->customer($company);
        $product = $this->product($company, 1000);

        $this->checkout($user, $company, $branch, $cash, $product, $customer)->assertOk();
        $this->assertSame(Sale::STATUS_COMPLETED, Sale::firstOrFail()->status);
        $this->assertDatabaseCount('loyalty_movements', 0);

        [$disabledCompany, $disabledBranch, $disabledUser, $disabledCash] = $this->context(false);
        $this->setting($disabledCompany, false);
        $this->checkout(
            $disabledUser,
            $disabledCompany,
            $disabledBranch,
            $disabledCash,
            $this->product($disabledCompany, 1000),
            $this->customer($disabledCompany),
        )->assertOk();

        $this->assertSame(2, Sale::query()->completed()->count());
        $this->assertDatabaseCount('loyalty_movements', 0);
    }

    public function test_final_consumer_and_suspended_sale_do_not_earn_points(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        $product = $this->product($company, 1000);

        $this->checkout($user, $company, $branch, $cash, $product)->assertOk();
        $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->postJson(route('pos.suspended.store'), [
                'customer_id' => $this->customer($company)->id,
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertCreated();

        $this->assertDatabaseCount('loyalty_accounts', 0);
        $this->assertDatabaseCount('loyalty_movements', 0);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('suspended_sales', 1);
    }

    public function test_completed_birthday_sales_award_one_annual_bonus_and_keep_normal_earning(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        LoyaltySetting::query()->where('company_id', $company->id)->update([
            'birthday_enabled' => true,
            'birthday_points' => '100.0000',
        ]);
        $customer = $this->customer($company);
        $customer->update(['birth_date' => now($company->timezone)->subYears(30)->toDateString()]);
        $product = $this->product($company, 1000);
        $token = (string) Str::uuid();

        $this->checkout($user, $company, $branch, $cash, $product, $customer, $token)->assertOk();
        $this->checkout($user, $company, $branch, $cash, $product, $customer, $token)->assertOk()->assertJsonPath('duplicate', true);
        $this->checkout($user, $company, $branch, $cash, $product, $customer)->assertOk();

        $birthday = LoyaltyMovement::query()->where('type', LoyaltyMovement::TYPE_BIRTHDAY)->firstOrFail();
        $this->assertSame('100.0000', $birthday->points);
        $this->assertSame($company->id, $birthday->company_id);
        $this->assertSame($branch->id, $birthday->branch_id);
        $this->assertSame("birthday:{$customer->id}:".now($company->timezone)->year, $birthday->event_key);
        $this->assertDatabaseCount('loyalty_accounts', 1);
        $this->assertSame('200.0000', LoyaltyAccount::firstOrFail()->balance);
        $this->assertSame(2, LoyaltyMovement::query()->where('type', LoyaltyMovement::TYPE_PURCHASE)->count());
        $this->assertSame(1, LoyaltyMovement::query()->where('type', LoyaltyMovement::TYPE_BIRTHDAY)->count());
    }

    public function test_returning_birthday_customer_receives_three_independent_movements_using_previous_purchase(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        LoyaltySetting::query()->where('company_id', $company->id)->update([
            'birthday_enabled' => true,
            'birthday_points' => '100.0000',
            'returning_customer_enabled' => true,
            'returning_customer_days' => 30,
            'returning_customer_points' => '100.0000',
        ]);
        $customer = $this->customer($company);
        $customer->update(['birth_date' => now($company->timezone)->subYears(30)->toDateString()]);
        $account = LoyaltyAccount::create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'last_qualifying_purchase_at' => now($company->timezone)->subDays(30),
        ]);
        $product = $this->product($company, 1000);
        $token = (string) Str::uuid();

        $this->checkout($user, $company, $branch, $cash, $product, $customer, $token)->assertOk();
        $this->checkout($user, $company, $branch, $cash, $product, $customer, $token)->assertOk()->assertJsonPath('duplicate', true);
        $this->checkout($user, $company, $branch, $cash, $product, $customer)->assertOk();

        $this->assertSame('300.0000', $account->fresh()->balance);
        $this->assertSame(2, LoyaltyMovement::query()->where('type', LoyaltyMovement::TYPE_PURCHASE)->count());
        $this->assertSame(1, LoyaltyMovement::query()->where('type', LoyaltyMovement::TYPE_BIRTHDAY)->count());
        $returning = LoyaltyMovement::query()->where('type', LoyaltyMovement::TYPE_RETURN_CUSTOMER)->firstOrFail();
        $this->assertSame('100.0000', $returning->points);
        $this->assertSame($branch->id, $returning->branch_id);
        $this->assertSame('returning_customer:sale:'.Sale::firstOrFail()->id, $returning->event_key);
        $this->assertSame(now($company->timezone)->toDateString(), $account->fresh()->last_qualifying_purchase_at->timezone($company->timezone)->toDateString());
    }

    public function test_offer_setting_controls_purchase_earning_and_persists_snapshot(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        $customer = $this->customer($company);
        $offer = $this->product($company, 1200);
        $offer->update(['special_price' => 1000]);

        $this->checkout($user, $company, $branch, $cash, $offer, $customer)->assertOk();
        $this->assertDatabaseCount('loyalty_movements', 0);
        $this->assertTrue(Sale::firstOrFail()->items->first()->is_offer);

        LoyaltySetting::where('company_id', $company->id)->update(['earn_on_offers' => true]);
        $this->checkout($user, $company, $branch, $cash, $offer, $customer)->assertOk();
        $movement = LoyaltyMovement::firstOrFail();
        $this->assertSame('50.0000', $movement->points);
        $this->assertSame('1000.0000', $movement->metadata['offer_eligibility']['offer_amount']);
        $this->assertTrue($movement->metadata['offer_eligibility']['earn_on_offers']);
    }

    public function test_mixed_sale_excludes_only_offer_lines_then_applies_multiplier(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        $customer = $this->customer($company);
        $normal = $this->product($company, 700);
        $offer = $this->product($company, 500);
        $offer->update(['special_price' => 300]);
        LoyaltyMultiplier::create(['company_id' => $company->id, 'name' => 'Doble', 'multiplier' => 2, 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'is_active' => true]);

        $this->checkoutItems($user, $company, $branch, $cash, $customer, [$normal, $offer])->assertOk();
        $movement = LoyaltyMovement::firstOrFail();
        $this->assertSame('70.0000', $movement->points);
        $this->assertSame('700.0000', $movement->base_amount);
        $this->assertSame('700.0000', $movement->metadata['offer_eligibility']['normal_amount']);
        $this->assertSame('300.0000', $movement->metadata['offer_eligibility']['offer_amount']);
        $this->assertSame('2.0000', $movement->metadata['multiplier']);

        LoyaltySetting::where('company_id', $company->id)->update(['earn_on_offers' => true]);
        $this->checkoutItems($user, $company, $branch, $cash, $customer, [$normal, $offer])->assertOk();
        $included = LoyaltyMovement::query()->latest('id')->firstOrFail();
        $this->assertSame('100.0000', $included->points);
        $this->assertSame('1000.0000', $included->base_amount);
    }

    public function test_manual_discount_is_not_an_offer(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        $permission = Permission::firstOrCreate(['name' => 'pos.aplicar_descuento'], ['label' => 'Descuento', 'module' => 'POS', 'is_active' => true]);
        $user->roleInCompany($company)->permissions()->attach($permission);
        $customer = $this->customer($company);
        $product = $this->product($company, 1000);
        $cashSession = $this->cashSession($company, $branch, $user);
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.checkout'), [
            'checkout_token' => (string) Str::uuid(), 'cash_session_id' => $cashSession->id, 'customer_id' => $customer->id,
            'payments' => [['payment_method_id' => $cash->id, 'amount' => 900, 'received_amount' => 900]],
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'discount' => 100, 'discount_type' => 'fixed']],
        ])->assertOk();
        $item = Sale::firstOrFail()->items->first();
        $this->assertFalse($item->is_offer);
        $this->assertSame('45.0000', LoyaltyMovement::firstOrFail()->points);
    }

    private function checkout(
        User $user,
        Company $company,
        Branch $branch,
        PaymentMethod $cash,
        Product $product,
        ?Customer $customer = null,
        ?string $token = null,
    ) {
        $cashSession = $this->cashSession($company, $branch, $user);

        return $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.checkout'), [
            'checkout_token' => $token ?? (string) Str::uuid(),
            'cash_session_id' => $cashSession->id,
            'customer_id' => $customer?->id,
            'payments' => [[
                'payment_method_id' => $cash->id,
                'amount' => 1000,
                'received_amount' => 1000,
                'reference' => null,
            ]],
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
    }

    private function checkoutItems(User $user, Company $company, Branch $branch, PaymentMethod $cash, Customer $customer, array $products)
    {
        $cashSession = $this->cashSession($company, $branch, $user);
        $total = collect($products)->sum(fn (Product $product) => (float) ($product->special_price ?? $product->sale_price));

        return $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.checkout'), [
            'checkout_token' => (string) Str::uuid(), 'cash_session_id' => $cashSession->id, 'customer_id' => $customer->id,
            'payments' => [['payment_method_id' => $cash->id, 'amount' => $total, 'received_amount' => $total]],
            'items' => collect($products)->map(fn (Product $product) => ['product_id' => $product->id, 'quantity' => 1])->all(),
        ]);
    }

    private function context(bool $withSetting = true): array
    {
        $company = Company::create(['trade_name' => 'Empresa '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = $this->branch($company, 'Principal');
        $user = $this->user($company, $branch);
        $cash = PaymentMethod::create(['company_id' => $company->id, 'code' => 'cash-'.uniqid(), 'name' => 'Efectivo', 'type' => 'cash', 'is_active' => true, 'allows_change' => true]);
        if ($withSetting) {
            $this->setting($company, true);
        }

        return [$company, $branch, $user, $cash];
    }

    private function setting(Company $company, bool $active): LoyaltySetting
    {
        return LoyaltySetting::create(['company_id' => $company->id, 'is_active' => $active, 'earning_percentage' => '5.0000', 'point_value' => '1.0000', 'earn_on_offers' => false, 'birthday_enabled' => false, 'birthday_points' => '0.0000', 'returning_customer_enabled' => false, 'returning_customer_days' => 0, 'returning_customer_points' => '0.0000']);
    }

    private function branch(Company $company, string $name): Branch
    {
        return Branch::create(['company_id' => $company->id, 'name' => $name, 'code' => strtoupper(substr(uniqid(), -6)), 'is_active' => true]);
    }

    private function user(Company $company, Branch $branch): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'POS '.uniqid(), 'is_active' => true]);
        foreach (['pos.acceder', 'ventas.crear'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'POS', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return $user;
    }

    private function customer(Company $company): Customer
    {
        return Customer::create(['company_id' => $company->id, 'name' => 'Cliente '.uniqid(), 'customer_type' => 'individual', 'is_active' => true]);
    }

    private function product(Company $company, int $price): Product
    {
        $id = uniqid();
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Categoría '.$id, 'slug' => 'cat-'.$id, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad '.$id, 'abbreviation' => 'U', 'slug' => 'u-'.$id, 'allows_decimals' => false, 'is_active' => true]);

        return Product::create(['company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id, 'name' => 'Producto '.$id, 'internal_code' => 'P-'.$id, 'cost' => 500, 'sale_price' => $price, 'tax_rate' => 0, 'track_inventory' => false, 'is_active' => true]);
    }

    private function cashSession(Company $company, Branch $branch, User $user): CashSession
    {
        $existing = CashSession::query()->forCompany($company->id)->forBranch($branch->id)->where('opened_by', $user->id)->where('status', CashSession::STATUS_OPEN)->first();
        if ($existing !== null) {
            return $existing;
        }
        $register = CashRegister::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'CAJA-'.uniqid(), 'name' => 'Caja', 'is_active' => true]);

        return CashSession::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'cash_register_id' => $register->id, 'session_number' => 'SES-'.uniqid(), 'opened_by' => $user->id, 'status' => CashSession::STATUS_OPEN, 'open_guard' => CashSession::OPEN_GUARD, 'opening_amount' => 0, 'opened_at' => now()]);
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
