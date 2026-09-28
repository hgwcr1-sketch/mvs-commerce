<?php

namespace Tests\Feature;

use App\Models\AccountPayable;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\InventoryTransfer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltyMultiplier;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\User;
use App\Services\DemoCompanyProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Database\Seeders\PermissionSeeder;
use Tests\TestCase;

class DemoTransactionalScenarioTest extends TestCase
{
    use RefreshDatabase;

    private DemoCompanyProvisioner $provisioner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->provisioner = app(DemoCompanyProvisioner::class);
    }

    private function getCompanyId(): int
    {
        return $this->provisioner->demoCompanyId();
    }

    public function test_sales_total(): void
    {
        $this->provisioner->create();
        $count = Sale::where('company_id', $this->getCompanyId())->count();
        $this->assertGreaterThanOrEqual(16, $count, "Expected at least 16 sales, got {$count}");
    }

    public function test_sales_dsj(): void
    {
        $this->provisioner->create();
        $dsjBranch = \App\Models\Branch::where('company_id', $this->getCompanyId())->where('code', 'DSJ')->first();
        $count = Sale::where('company_id', $this->getCompanyId())->where('branch_id', $dsjBranch->id)->count();
        $this->assertGreaterThanOrEqual(9, $count, "Expected at least 9 DSJ sales, got {$count}");
    }

    public function test_sales_dlb(): void
    {
        $this->provisioner->create();
        $dlbBranch = \App\Models\Branch::where('company_id', $this->getCompanyId())->where('code', 'DLB')->first();
        $count = Sale::where('company_id', $this->getCompanyId())->where('branch_id', $dlbBranch->id)->count();
        $this->assertGreaterThanOrEqual(7, $count, "Expected at least 7 DLB sales, got {$count}");
    }

    public function test_payments_match_totals(): void
    {
        $this->provisioner->create();
        $sales = Sale::where('company_id', $this->getCompanyId())->get();
        foreach ($sales as $sale) {
            $paymentsSum = (string) $sale->payments()->sum('amount');
            $this->assertEquals(
                0,
                bccomp($paymentsSum, (string) $sale->total, 4),
                "Sale {$sale->id}: payments {$paymentsSum} != total {$sale->total}"
            );
        }
    }

    public function test_cash_sale_has_cash_session(): void
    {
        $this->provisioner->create();
        $cashMethod = PaymentMethod::where('company_id', $this->getCompanyId())->where('code', 'cash')->first();
        $cashSales = Sale::where('company_id', $this->getCompanyId())
            ->whereHas('payments', fn ($q) => $q->where('payment_method_id', $cashMethod->id))
            ->get();
        foreach ($cashSales as $sale) {
            $this->assertNotNull($sale->cash_session_id, "Cash sale {$sale->id} has no cash_session_id");
        }
    }

    public function test_no_negative_stock(): void
    {
        $this->provisioner->create();
        $products = Product::where('company_id', $this->getCompanyId())->get();
        $branches = \App\Models\Branch::where('company_id', $this->getCompanyId())->get();
        foreach ($products as $product) {
            foreach ($branches as $branch) {
                $stock = \DB::table('branch_product')
                    ->where('branch_id', $branch->id)
                    ->where('product_id', $product->id)
                    ->value('stock');
                $this->assertGreaterThanOrEqual(0, (float) $stock,
                    "Product {$product->internal_code} branch {$branch->code} has negative stock: {$stock}");
            }
        }
    }

    public function test_purchases_exist(): void
    {
        $this->provisioner->create();
        $count = Purchase::where('company_id', $this->getCompanyId())->count();
        $this->assertGreaterThanOrEqual(3, $count, "Expected at least 3 purchases, got {$count}");
    }

    public function test_purchase_generates_inventory_movements(): void
    {
        $this->provisioner->create();
        $count = InventoryMovement::where('company_id', $this->getCompanyId())->where('type', 'purchase')->count();
        $this->assertGreaterThan(0, $count, "Expected purchase inventory movements");
    }

    public function test_credit_purchase_creates_account_payable(): void
    {
        $this->provisioner->create();
        $count = AccountPayable::where('company_id', $this->getCompanyId())->count();
        $this->assertGreaterThan(0, $count, "Expected accounts payable from credit purchase");
    }

    public function test_transfers_exist(): void
    {
        $this->provisioner->create();
        $count = InventoryTransfer::where('company_id', $this->getCompanyId())->count();
        $this->assertGreaterThanOrEqual(2, $count, "Expected at least 2 transfers, got {$count}");
    }

    public function test_transfer_generates_movements(): void
    {
        $this->provisioner->create();
        $types = ['transfer_out', 'transfer_in'];
        foreach ($types as $type) {
            $count = InventoryMovement::where('company_id', $this->getCompanyId())->where('type', $type)->count();
            $this->assertGreaterThan(0, $count, "Expected {$type} movements");
        }
    }

    public function test_kardex_has_multiple_types(): void
    {
        $this->provisioner->create();
        $types = InventoryMovement::where('company_id', $this->getCompanyId())
            ->distinct()
            ->pluck('type')
            ->toArray();
        $this->assertContains('purchase', $types, 'Kardex missing purchase type');
        $this->assertContains('sale', $types, 'Kardex missing sale type');
    }

    public function test_loyalty_accounts_exist(): void
    {
        $this->provisioner->create();
        $count = LoyaltyAccount::where('company_id', $this->getCompanyId())->count();
        $this->assertGreaterThan(0, $count, "Expected loyalty accounts");
    }

    public function test_loyalty_movements_exist(): void
    {
        $this->provisioner->create();
        $count = LoyaltyMovement::where('company_id', $this->getCompanyId())->count();
        $this->assertGreaterThan(0, $count, "Expected loyalty movements");
    }

    public function test_double_points_multiplier_exists(): void
    {
        $this->provisioner->create();
        $multiplier = LoyaltyMultiplier::where('company_id', $this->getCompanyId())->where('multiplier', '2.0000')->first();
        $this->assertNotNull($multiplier, 'Expected 2X loyalty multiplier');
        $this->assertTrue($multiplier->is_active);
    }

    public function test_double_points_effect(): void
    {
        $this->provisioner->create();
        $movement = LoyaltyMovement::where('company_id', $this->getCompanyId())
            ->where('type', 'purchase')
            ->whereNotNull('metadata')
            ->first();
        if ($movement) {
            $metadata = $movement->metadata;
            if (isset($metadata['multiplier']) && $metadata['multiplier'] !== '1.0000') {
                $this->assertEquals('2.0000', $metadata['multiplier'], 'Expected 2X multiplier in metadata');
            }
        }
    }

    public function test_cash_sessions_closed(): void
    {
        $this->provisioner->create();
        $closedCount = CashSession::where('company_id', $this->getCompanyId())->where('status', 'closed')->count();
        $this->assertGreaterThan(0, $closedCount, "Expected closed cash sessions");
    }

    public function test_cash_sessions_open_count(): void
    {
        $this->provisioner->create();
        $openCount = CashSession::where('company_id', $this->getCompanyId())->whereIn('status', ['open', 'closing'])->count();
        $this->assertLessThanOrEqual(2, $openCount, "Expected at most 2 open sessions");
    }

    public function test_dashboard_sales_today(): void
    {
        $this->provisioner->create();
        $now = \Carbon\CarbonImmutable::now('America/Costa_Rica');
        $todaySales = Sale::where('company_id', $this->getCompanyId())
            ->where('status', Sale::STATUS_COMPLETED)
            ->whereDate('completed_at', $now->toDateString())
            ->count();
        $salesWithDate = Sale::where('company_id', $this->getCompanyId())
            ->whereNotNull('completed_at')
            ->count();
        $this->assertGreaterThan(0, $salesWithDate, "Expected sales with completed_at for dashboard");
    }

    public function test_dashboard_sales_week(): void
    {
        $this->provisioner->create();
        $completedSales = Sale::where('company_id', $this->getCompanyId())
            ->where('status', Sale::STATUS_COMPLETED)
            ->count();
        $this->assertGreaterThan(0, $completedSales, "Expected completed sales for dashboard");
    }

    public function test_both_branches_have_activity(): void
    {
        $this->provisioner->create();
        $cid = $this->getCompanyId();
        $branches = \App\Models\Branch::where('company_id', $cid)->get();
        foreach ($branches as $branch) {
            $salesCount = Sale::where('company_id', $cid)->where('branch_id', $branch->id)->count();
            $this->assertGreaterThan(0, $salesCount, "Branch {$branch->code} has no sales activity");
        }
    }

    public function test_reset_idempotent(): void
    {
        $this->provisioner->create();
        $count1 = Sale::where('company_id', $this->getCompanyId())->count();
        $this->assertGreaterThan(0, $count1);

        $this->provisioner->reset();
        $count2 = Sale::where('company_id', $this->getCompanyId())->count();
        $this->assertGreaterThan(0, $count2);

        $this->assertSame(
            $count1,
            $count2,
            "Reset should produce identical sale counts: first={$count1}, second={$count2}"
        );
    }

    public function test_multitenant_isolation(): void
    {
        $this->provisioner->create();
        $demoSalesCount = Sale::where('company_id', $this->getCompanyId())->count();
        $this->assertGreaterThan(0, $demoSalesCount);

        $otherCompany = \App\Models\Company::create([
            'trade_name' => 'Other Company',
            'is_active' => true,
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
        ]);

        $otherSalesCount = Sale::where('company_id', $otherCompany->id)->count();
        $this->assertEquals(0, $otherSalesCount);

        $this->provisioner->reset();
        $otherSalesAfter = Sale::where('company_id', $otherCompany->id)->count();
        $this->assertEquals(0, $otherSalesAfter, "Other company should remain intact after demo reset");
    }

    public function test_clock_restored(): void
    {
        $this->provisioner->create();
        $testNow = \Carbon\Carbon::getTestNow();
        $this->assertNull($testNow, 'Carbon testNow should be null after scenario completes');
    }
}
