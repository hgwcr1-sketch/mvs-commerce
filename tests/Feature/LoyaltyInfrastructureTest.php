<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltyMovementLine;
use App\Models\LoyaltySetting;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LoyaltyInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_has_only_one_loyalty_setting_with_decimal_configuration(): void
    {
        $company = $this->company();
        $setting = LoyaltySetting::create([
            'company_id' => $company->id,
            'is_active' => true,
            'earning_percentage' => '5.1250',
            'point_value' => '0.5000',
            'minimum_redemption_points' => '3000.2500',
            'maximum_redemption_percent' => '40.7500',
            'earn_on_offers' => true,
            'redeem_on_offers' => false,
            'expiration_enabled' => true,
            'expiration_months' => 17,
        ]);

        $this->assertTrue($setting->company->is($company));
        $this->assertSame('5.1250', $setting->earning_percentage);
        $this->assertSame('0.5000', $setting->point_value);
        $this->assertSame('3000.2500', $setting->minimum_redemption_points);
        $this->assertSame('40.7500', $setting->maximum_redemption_percent);
        $this->assertSame(17, $setting->expiration_months);

        $this->expectException(QueryException::class);
        LoyaltySetting::create(['company_id' => $company->id]);
    }

    public function test_loyalty_account_is_unique_per_company_and_customer_but_independent_between_companies(): void
    {
        $firstCompany = $this->company('Primera');
        $secondCompany = $this->company('Segunda');
        $firstCustomer = $this->customer($firstCompany, 'IDENTIDAD-COMPARTIDA');
        $secondCustomer = $this->customer($secondCompany, 'IDENTIDAD-COMPARTIDA');

        $first = LoyaltyAccount::create([
            'company_id' => $firstCompany->id,
            'customer_id' => $firstCustomer->id,
            'balance' => '15000.0005',
            'total_earned' => '16000.0005',
            'total_redeemed' => '1000.0000',
            'total_expired' => '0.0000',
        ]);
        $second = LoyaltyAccount::create([
            'company_id' => $secondCompany->id,
            'customer_id' => $secondCustomer->id,
            'balance' => '25.5000',
        ]);

        $this->assertTrue($first->company->is($firstCompany));
        $this->assertTrue($first->customer->is($firstCustomer));
        $this->assertTrue($second->company->is($secondCompany));
        $this->assertSame('15000.0005', $first->balance);
        $this->assertSame('25.5000', $second->balance);

        try {
            LoyaltyAccount::create([
                'company_id' => $firstCompany->id,
                'customer_id' => $firstCustomer->id,
            ]);
            $this->fail('Se esperaba la restricción única de empresa y cliente.');
        } catch (QueryException) {
            $this->assertDatabaseCount('loyalty_accounts', 2);
        }
    }

    public function test_movement_kardex_lines_decimal_precision_types_and_relations_are_available(): void
    {
        $company = $this->company();
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'PRI', 'is_active' => true]);
        $customer = $this->customer($company);
        $user = User::factory()->create();
        $account = LoyaltyAccount::create(['company_id' => $company->id, 'customer_id' => $customer->id]);
        [$product, $category] = $this->product($company);
        $saleItem = $this->saleItem($company, $branch, $customer, $user, $product);

        $movement = LoyaltyMovement::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'loyalty_account_id' => $account->id,
            'customer_id' => $customer->id,
            'user_id' => $user->id,
            'type' => LoyaltyMovement::TYPE_PURCHASE,
            'points' => '50.1250',
            'balance_before' => '10.5000',
            'balance_after' => '60.6250',
            'base_amount' => '1002.5000',
            'earning_percentage' => '5.0000',
            'point_value' => '1.0000',
            'description' => 'Puntos por compra',
            'source_type' => Sale::class,
            'source_id' => $saleItem->sale_id,
            'event_key' => 'sale:'.$saleItem->sale_id.':purchase',
            'effective_at' => now(),
            'metadata' => ['channel' => 'pos'],
        ]);
        $related = LoyaltyMovement::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'loyalty_account_id' => $account->id,
            'customer_id' => $customer->id,
            'user_id' => $user->id,
            'type' => LoyaltyMovement::TYPE_ADJUSTMENT,
            'points' => '-0.1250',
            'balance_before' => '60.6250',
            'balance_after' => '60.5000',
            'description' => 'Ajuste de precisión',
            'related_movement_id' => $movement->id,
            'effective_at' => now(),
        ]);
        $line = LoyaltyMovementLine::create([
            'loyalty_movement_id' => $movement->id,
            'sale_item_id' => $saleItem->id,
            'product_id' => $product->id,
            'product_category_id' => $category->id,
            'eligible_amount' => '1002.5000',
            'earning_percentage' => '5.0000',
            'multiplier' => '1.2500',
            'points' => '62.6563',
        ]);

        $this->assertTrue($movement->company->is($company));
        $this->assertTrue($movement->branch->is($branch));
        $this->assertTrue($movement->loyaltyAccount->is($account));
        $this->assertTrue($movement->customer->is($customer));
        $this->assertTrue($movement->user->is($user));
        $this->assertTrue($related->relatedMovement->is($movement));
        $this->assertTrue($account->movements->contains($movement));
        $this->assertTrue($movement->lines->contains($line));
        $this->assertTrue($line->movement->is($movement));
        $this->assertTrue($line->saleItem->is($saleItem));
        $this->assertTrue($line->product->is($product));
        $this->assertTrue($line->productCategory->is($category));
        $this->assertSame('50.1250', $movement->points);
        $this->assertSame('62.6563', $line->points);
        $this->assertSame(['channel' => 'pos'], $movement->metadata);
        $this->assertSame([
            'purchase', 'new_customer', 'birthday', 'return_customer', 'promotion',
            'redemption', 'reward', 'return', 'void', 'expiration', 'adjustment',
        ], LoyaltyMovement::TYPES);
    }

    public function test_loyalty_tables_have_the_required_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('loyalty_settings', [
            'company_id', 'earning_percentage', 'point_value', 'expiration_months',
        ]));
        $this->assertTrue(Schema::hasColumns('loyalty_accounts', [
            'company_id', 'customer_id', 'balance', 'total_earned', 'total_redeemed', 'total_expired',
        ]));
        $this->assertTrue(Schema::hasColumns('loyalty_movements', [
            'loyalty_account_id', 'points', 'balance_before', 'balance_after', 'event_key', 'metadata',
        ]));
        $this->assertTrue(Schema::hasColumns('loyalty_movement_lines', [
            'loyalty_movement_id', 'eligible_amount', 'earning_percentage', 'multiplier', 'points',
        ]));
    }

    private function company(string $name = 'Empresa'): Company
    {
        return Company::create(['trade_name' => $name.' '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
    }

    private function customer(Company $company, ?string $identification = null): Customer
    {
        return Customer::create([
            'company_id' => $company->id,
            'customer_type' => 'individual',
            'identification' => $identification,
            'name' => 'Cliente '.uniqid(),
            'credit_limit' => 0,
            'is_active' => true,
        ]);
    }

    private function product(Company $company): array
    {
        $id = uniqid();
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Categoría '.$id, 'slug' => 'cat-'.$id, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad '.$id, 'abbreviation' => 'U', 'slug' => 'u-'.$id, 'allows_decimals' => false, 'is_active' => true]);
        $product = Product::create(['company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id, 'name' => 'Producto '.$id, 'internal_code' => 'P-'.$id, 'cost' => 500, 'sale_price' => 1000, 'tax_rate' => 0, 'is_active' => true]);

        return [$product, $category];
    }

    private function saleItem(Company $company, Branch $branch, Customer $customer, User $user, Product $product): SaleItem
    {
        $sale = Sale::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'sale_number' => 'POS-'.uniqid(),
            'status' => Sale::STATUS_COMPLETED,
            'currency_code' => 'CRC',
            'subtotal' => 1000,
            'total' => 1000,
            'paid_total' => 1000,
            'completed_at' => now(),
        ]);

        return SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'description' => $product->name,
            'quantity' => 1,
            'unit_price' => 1000,
            'gross_total' => 1000,
            'subtotal' => 1000,
            'total' => 1000,
            'unit_cost' => 500,
        ]);
    }
}
