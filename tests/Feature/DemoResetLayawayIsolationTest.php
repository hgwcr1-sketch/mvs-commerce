<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Unit;
use App\Models\User;
use App\Services\DemoCompanyProvisioner;
use App\Services\PaymentMethodProvisioner;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DemoResetLayawayIsolationTest extends TestCase
{
    use RefreshDatabase;

    private DemoCompanyProvisioner $provisioner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->provisioner = app(DemoCompanyProvisioner::class);
    }

    public function test_reset_cleans_demo_layaways_and_preserves_real_company(): void
    {
        $demo = $this->provisioner->create();
        $this->makeLayaway($demo);
        $real = $this->makeCompany('Empresa Real');
        $realLayaway = $this->makeLayaway($real)->id;
        $realItems = DB::table('layaway_items')->where('layaway_id', $realLayaway)->count();
        $realPays = DB::table('layaway_payments')->where('company_id', $real->id)->count();
        $this->assertGreaterThan(0, $realItems);
        $this->assertGreaterThan(0, $realPays);

        $this->provisioner->reset();

        $this->assertSame(0, DB::table('layaway_payments')->where('company_id', $demo->id)->count());
        $this->assertSame(0, DB::table('layaway_items')->whereIn('layaway_id', fn ($q) => $q->select('id')->from('layaways')->where('company_id', $demo->id))->count());
        $this->assertDatabaseHas('layaways', ['id' => $realLayaway, 'company_id' => $real->id]);
        $this->assertSame($realItems, DB::table('layaway_items')->where('layaway_id', $realLayaway)->count());
        $this->assertSame($realPays, DB::table('layaway_payments')->where('company_id', $real->id)->count());
    }

    public function test_old_buggy_code_would_fail_on_postgres_strict_sql(): void
    {
        // SQLite Query Builder ignora columnas inexistentes en DELETE (devuelve 0),
        // pero PostgreSQL lanza SQLSTATE[42703]. Este test usa SQL crudo estricto
        // para documentar que el código viejo era inválido contra el esquema real.
        $this->expectException(QueryException::class);
        DB::connection()->select('delete from layaway_items where company_id = 1');
    }

    public function test_reset_is_idempotent_with_layaways(): void
    {
        $demo = $this->provisioner->create();
        $this->makeLayaway($demo);
        $this->provisioner->reset();
        $first = DB::table('sales')->where('company_id', $demo->id)->count();
        $this->provisioner->reset();
        $second = DB::table('sales')->where('company_id', $demo->id)->count();
        $this->assertSame($first, $second);
        $this->assertSame(0, DB::table('layaway_payments')->where('company_id', $demo->id)->count());
    }

    private function makeLayaway(Company $company): Layaway
    {
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'P'.$company->id, 'code' => 'P'.$company->id.'-'.uniqid(), 'is_active' => true]);
        $user = User::factory()->create();
        $company->users()->attach($user->id);
        app(PaymentMethodProvisioner::class)->provision($company);
        $method = PaymentMethod::forCompany($company->id)->firstOrFail();
        $register = CashRegister::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'C'.uniqid(), 'name' => 'Caja', 'is_active' => true]);
        $session = CashSession::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'cash_register_id' => $register->id, 'session_number' => 'CAJA-'.uniqid(), 'opened_by' => $user->id, 'status' => 'open', 'open_guard' => 'OPEN-'.uniqid(), 'opening_amount' => 0, 'opened_at' => now()]);
        $uid = uniqid();
        $cat = ProductCategory::create(['company_id' => $company->id, 'name' => 'Cat '.$uid, 'slug' => 'cat-'.$uid, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad', 'abbreviation' => 'U', 'slug' => 'u-'.$uid, 'is_active' => true]);
        $product = Product::create(['company_id' => $company->id, 'category_id' => $cat->id, 'unit_id' => $unit->id, 'name' => 'Prod Apartado', 'internal_code' => 'AP-'.$uid, 'cost' => 500, 'sale_price' => 1000, 'tax_rate' => 0, 'track_inventory' => false, 'is_active' => true]);
        $customer = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'identification_type' => 'national', 'identification' => 'ID-'.$uid, 'name' => 'Cliente Apartado', 'is_active' => true]);
        $layaway = Layaway::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'created_by' => $user->id, 'number' => 'AP-'.$uid, 'status' => Layaway::STATUS_ACTIVE, 'currency_code' => 'CRC', 'total' => 2000, 'paid_total' => 500, 'balance_due' => 1500, 'expires_at' => today()->addDays(10)->toDateString()]);
        DB::table('layaway_items')->insert(['layaway_id' => $layaway->id, 'product_id' => $product->id, 'description' => $product->name, 'quantity' => 2, 'unit_price' => 1000, 'tax_rate' => 0, 'subtotal' => 2000, 'tax_total' => 0, 'total' => 2000, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('layaway_payments')->insert(['layaway_id' => $layaway->id, 'company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $user->id, 'cash_session_id' => $session->id, 'payment_method_id' => $method->id, 'amount' => 500, 'affects_cash_snapshot' => false, 'cash_effect_amount' => 0, 'paid_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return $layaway;
    }

    private function makeCompany(string $name): Company
    {
        return Company::create(['trade_name' => $name.' '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
    }
}
