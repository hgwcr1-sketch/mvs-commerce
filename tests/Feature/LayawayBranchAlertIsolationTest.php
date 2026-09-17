<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\LayawayUpcomingNotification;
use App\Services\Sales\LayawayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class LayawayBranchAlertIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_upcoming_alert_only_reaches_same_branch_active_users(): void
    {
        Notification::fake();

        [$company, $branchA, $branchB, $product] = $this->companyContext();

        $userA = $this->user($company, $branchA, ['apartados.ver']);
        $userB = $this->user($company, $branchB, ['apartados.ver']);
        $inactiveA = $this->user($company, $branchA, ['apartados.ver'], false);

        $layaway = $this->createLayaway($company, $branchA, $product);
        $layaway->update(['expires_at' => today()->addDays(3)]);

        app(LayawayService::class)->createUpcomingAlerts();

        Notification::assertSentTo($userA, LayawayUpcomingNotification::class);
        Notification::assertNotSentTo($userB, LayawayUpcomingNotification::class);
        Notification::assertNotSentTo($inactiveA, LayawayUpcomingNotification::class);
    }

    private function companyContext(): array
    {
        $company = Company::create([
            'trade_name' => 'Empresa '.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'layaway_validity_days' => 30,
            'layaway_alert_days' => 5,
            'is_active' => true,
        ]);
        $branchA = Branch::create(['company_id' => $company->id, 'name' => 'A', 'code' => 'A'.uniqid(), 'is_active' => true]);
        $branchB = Branch::create(['company_id' => $company->id, 'name' => 'B', 'code' => 'B'.uniqid(), 'is_active' => true]);

        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Cat', 'slug' => 'cat', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad', 'abbreviation' => 'U', 'slug' => 'u', 'is_active' => true]);
        $product = Product::create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'name' => 'Producto',
            'internal_code' => 'P'.uniqid(),
            'cost' => 500,
            'sale_price' => 1000,
            'tax_rate' => 0,
            'track_inventory' => true,
            'is_active' => true,
        ]);
        $product->branches()->attach($branchA, ['stock' => 5]);

        return [$company, $branchA, $branchB, $product];
    }

    private function user(Company $company, Branch $branch, array $permissions, bool $active = true): User
    {
        $role = Role::create(['company_id' => $company->id, 'name' => 'R'.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Test', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user = User::factory()->create(['is_active' => $active]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return $user;
    }

    private function createLayaway(Company $company, Branch $branch, Product $product): Layaway
    {
        $customer = Customer::create([
            'company_id' => $company->id,
            'name' => 'Cliente',
            'identification_type' => '01',
            'identification' => 'ID-'.uniqid(),
            'is_active' => true,
        ]);

        $layaway = Layaway::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'created_by' => User::factory()->create()->id,
            'number' => 'AP-'.uniqid(),
            'status' => Layaway::STATUS_ACTIVE,
            'currency_code' => 'CRC',
            'total' => 2000,
            'paid_total' => 200,
            'balance_due' => 1800,
            'expires_at' => today()->addDays(3),
        ]);

        $layaway->items()->create([
            'product_id' => $product->id,
            'description' => $product->name,
            'quantity' => 2,
            'unit_price' => 1000,
            'tax_rate' => 0,
            'subtotal' => 2000,
            'tax_total' => 0,
            'total' => 2000,
        ]);

        return $layaway;
    }
}
