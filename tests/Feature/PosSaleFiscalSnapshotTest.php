<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalProfile;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemTax;
use App\Models\Unit;
use App\Models\User;
use App\Services\CompanyLicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class PosSaleFiscalSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_thirteen_percent_product_freezes_profile_snapshot_on_item_and_tax_rows(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        $product = $this->product($company, ['tax_rate' => 13, 'fiscal_profile_id' => $this->profile('08')->id]);

        $this->checkout($user, $company, $branch, $cash, $product, 1130)->assertOk();

        $sale = Sale::firstOrFail();
        $this->assertSame('1000.0000', $sale->subtotal);
        $this->assertSame('130.0000', $sale->tax_total);
        $this->assertSame('1130.0000', $sale->total);

        $item = SaleItem::firstOrFail();
        $this->assertSame('13.0000', $item->tax_rate);
        $this->assertSame('130.0000', $item->tax_total);
        $this->assertSame('01', $item->tax_code);
        $this->assertSame('08', $item->tax_rate_code);
        $this->assertSame('taxable', $item->tax_treatment);
        $this->assertNotNull($item->fiscal_snapshot);
        $this->assertSame('01', $item->fiscal_snapshot['taxes'][0]['codigo']);
        $this->assertSame('08', $item->fiscal_snapshot['taxes'][0]['codigoTarifa']);
        $this->assertEquals(13, $item->fiscal_snapshot['taxes'][0]['tarifa']);
        $this->assertNotEmpty($item->fiscal_source);

        $this->assertDatabaseCount('sale_item_taxes', 1);
        $tax = SaleItemTax::firstOrFail();
        $this->assertSame($item->id, (int) $tax->sale_item_id);
        $this->assertSame('01', $tax->tax_code);
        $this->assertSame('08', $tax->tax_rate_code);
        $this->assertSame('1000.0000', $tax->base_amount);
        $this->assertSame('130.0000', $tax->tax_amount);
        $this->assertSame('13.0000', $tax->rate);
        $this->assertSame(1, (int) $tax->sequence);
        $this->assertNull($tax->exemption_snapshot);
    }

    public function test_explicit_fiscal_profile_is_the_authority_over_the_legacy_rate(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        $product = $this->product($company, [
            'tax_rate' => 13,
            'fiscal_profile_id' => $this->profile('04')->id,
        ]);

        $this->checkout($user, $company, $branch, $cash, $product, 1040)->assertOk();

        $sale = Sale::firstOrFail();
        $this->assertSame('1000.0000', $sale->subtotal);
        $this->assertSame('40.0000', $sale->tax_total);
        $this->assertSame('1040.0000', $sale->total);

        $item = SaleItem::firstOrFail();
        $this->assertSame('4.0000', $item->tax_rate);
        $this->assertSame('04', $item->tax_rate_code);
        $this->assertSame('reduced_rate', $item->tax_treatment);
        $this->assertSame('40.0000', $item->tax_total);
        $this->assertSame('40.0000', SaleItemTax::firstOrFail()->tax_amount);
    }

    public function test_pending_profiles_allow_normal_pos_without_inferred_snapshots(): void
    {
        Http::preventStrayRequests();
        foreach ([0, 1, 2, 4, 8, 13, null] as $rate) {
            [$company, $branch, $user, $cash] = $this->context();
            $product = $this->product($company, ['tax_rate' => $rate]);
            $total = 1000 + (int) ($rate * 10);
            $this->checkout($user, $company, $branch, $cash, $product, $total)->assertOk();
            $item = SaleItem::latest('id')->firstOrFail();
            $this->assertNull($product->fresh()->fiscal_profile_id);
            $this->assertNull($item->fiscal_snapshot);
            $this->assertNull($item->tax_code);
            $this->assertNull($item->tax_treatment);
            $this->assertSame(number_format($total, 4, '.', ''), $item->total);
            $this->assertDatabaseCount('sale_item_taxes', 0);
        }
        Http::assertNothingSent();
    }

    public function test_fe_te_preflight_blocks_pending_profile_and_missing_cabys_without_side_effects(): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        foreach ([Sale::DOCUMENT_ELECTRONIC_INVOICE, Sale::DOCUMENT_ELECTRONIC_TICKET] as $type) {
            [$company, $branch, $user, $cash] = $this->context();
            $company->update(['legal_name' => 'Empresa fiscal', 'identification_number' => '3101000001']);
            app(CompanyLicenseService::class)->ensure($company);
            $company->license()->update(['fiscal_enabled' => true]);
            $customer = Customer::create(['company_id' => $company->id, 'name' => 'Cliente', 'customer_type' => 'individual', 'identification_type' => '01', 'identification' => '109880401', 'is_active' => true]);
            $product = $this->product($company, ['tax_rate' => 13, 'cabys_code' => '0111100000100']);
            $extra = ['document_type' => $type, 'customer_id' => $customer->id];
            $this->checkout($user, $company, $branch, $cash, $product, 1130, $extra)
                ->assertUnprocessable()->assertJsonValidationErrors('items.0.fiscal_profile_id');
            $product->update(['fiscal_profile_id' => $this->profile('08')->id, 'cabys_code' => null]);
            $this->checkout($user, $company, $branch, $cash, $product, 1130, $extra)
                ->assertUnprocessable()->assertJsonValidationErrors('items.0.cabys_code');
            $product->update(['cabys_code' => '0111100000100']);
            $company->update(['legal_name' => null]);
            $this->checkout($user, $company, $branch, $cash, $product, 1130, $extra)
                ->assertUnprocessable()->assertJsonValidationErrors('document_type');
            $company->update(['legal_name' => 'Empresa fiscal']);
            if ($type === Sale::DOCUMENT_ELECTRONIC_INVOICE) {
                $customer->update(['identification' => null]);
                $this->checkout($user, $company, $branch, $cash, $product, 1130, $extra)
                    ->assertUnprocessable()->assertJsonValidationErrors('customer_id');
                $customer->update(['identification' => '109880401']);
            }
            $product->unit->update(['abbreviation' => '']);
            $this->checkout($user, $company, $branch, $cash, $product, 1130, $extra)
                ->assertUnprocessable()->assertJsonValidationErrors('items.0.unit_code');
            $this->assertDatabaseCount('sales', 0);
            $this->assertDatabaseCount('sale_payments', 0);
            $this->assertDatabaseCount('inventory_movements', 0);
            $this->assertDatabaseCount('electronic_documents', 0);
        }
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_zero_rate_exempt_and_not_subject_profiles_are_frozen_with_their_own_treatment(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        $expected = [
            ['01', 'zero_rate'],
            ['10', 'exempt'],
            ['11', 'not_subject'],
        ];

        foreach ($expected as [$rateCode, $treatment]) {
            $product = $this->product($company, [
                'tax_rate' => 0,
                'fiscal_profile_id' => $this->profile($rateCode)->id,
            ]);

            $this->checkout($user, $company, $branch, $cash, $product, 1000)->assertOk();

            $item = SaleItem::latest('id')->firstOrFail();
            $this->assertSame('0.0000', $item->tax_rate);
            $this->assertSame('0.0000', $item->tax_total);
            $this->assertSame('01', $item->tax_code);
            $this->assertSame($rateCode, $item->tax_rate_code);
            $this->assertSame($treatment, $item->tax_treatment);
            $this->assertSame($rateCode, $item->fiscal_snapshot['taxes'][0]['codigoTarifa']);
            $this->assertSame($treatment, $item->fiscal_snapshot['taxes'][0]['treatment']);

            $tax = SaleItemTax::latest('id')->firstOrFail();
            $this->assertSame($rateCode, $tax->tax_rate_code);
            $this->assertSame('1000.0000', $tax->base_amount);
            $this->assertSame('0.0000', $tax->tax_amount);
            $this->assertNull($tax->exemption_snapshot);
        }
    }

    public function test_totals_and_mixed_payments_are_preserved_with_frozen_snapshots(): void
    {
        [$company, $branch, $user] = $this->context();
        $cash = $this->payment($company);
        $card = $this->payment($company, ['name' => 'Tarjeta', 'type' => 'card', 'requires_reference' => true, 'allows_change' => false]);
        $product = $this->product($company, ['tax_rate' => 13, 'sale_price' => 1000, 'fiscal_profile_id' => $this->profile('08')->id]);
        $cashSession = $this->ensureCashSession($company, $branch, $user);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.checkout'), array_merge([
            'checkout_token' => (string) Str::uuid(),
            'cash_session_id' => $cashSession->id,
            'customer_id' => null,
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
            'payments' => [
                ['payment_method_id' => $card->id, 'amount' => 2000, 'reference' => 'CARD'],
                ['payment_method_id' => $cash->id, 'amount' => 1390, 'received_amount' => 2000],
            ],
        ], $extra ?? []))->assertOk();

        $sale = Sale::firstOrFail();
        $this->assertSame('3000.0000', $sale->subtotal);
        $this->assertSame('390.0000', $sale->tax_total);
        $this->assertSame('3390.0000', $sale->total);
        $this->assertDatabaseCount('sale_payments', 2);
        $this->assertDatabaseCount('sale_item_taxes', 1);

        $item = SaleItem::firstOrFail();
        $this->assertSame('390.0000', $item->tax_total);
        $this->assertSame('390.0000', SaleItemTax::firstOrFail()->tax_amount);
        $this->assertSame('13', (string) $item->fiscal_snapshot['taxes'][0]['tarifa']);
    }

    private function profile(string $rateCode): FiscalProfile
    {
        return FiscalProfile::query()
            ->where('tax_code', '01')
            ->where('tax_rate_code', $rateCode)
            ->firstOrFail();
    }

    private function context(string $name = 'Empresa'): array
    {
        $company = $this->company($name);
        $branch = $this->branch($company, 'Principal');
        $user = $this->user($company, $branch, ['pos.acceder', 'ventas.crear']);

        return [$company, $branch, $user, $this->payment($company)];
    }

    private function company(string $name): Company
    {
        return Company::create(['trade_name' => $name.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
    }

    private function branch(Company $company, string $name): Branch
    {
        return Branch::create(['company_id' => $company->id, 'name' => $name, 'code' => $name.'-'.$company->id, 'is_active' => true]);
    }

    private function user(Company $company, Branch $branch, array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'POS', 'is_active' => true]);
            $role->permissions()->syncWithoutDetaching($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return $user;
    }

    private function payment(Company $company, array $attributes = []): PaymentMethod
    {
        return PaymentMethod::create(array_merge([
            'company_id' => $company->id,
            'code' => 'pay-'.uniqid(),
            'name' => 'Efectivo',
            'type' => 'cash',
            'is_active' => true,
            'allows_change' => true,
        ], $attributes));
    }

    private function product(Company $company, array $attributes): Product
    {
        $suffix = uniqid();
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Cat '.$suffix, 'slug' => 'cat-'.$suffix, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad', 'abbreviation' => 'U', 'slug' => 'u-'.$suffix, 'allows_decimals' => false, 'is_active' => true]);

        return Product::create(array_merge([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'name' => 'Producto '.$suffix,
            'internal_code' => 'P-'.$suffix,
            'cost' => 500,
            'sale_price' => 1000,
            'track_inventory' => false,
            'is_active' => true,
        ], $attributes));
    }

    private function checkout(User $user, Company $company, Branch $branch, PaymentMethod $method, Product $product, int $received, array $extra = [])
    {
        $cashSession = $this->ensureCashSession($company, $branch, $user);

        return $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.checkout'), array_merge([
            'checkout_token' => (string) Str::uuid(),
            'cash_session_id' => $cashSession->id,
            'customer_id' => null,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [[
                'payment_method_id' => $method->id,
                'amount' => $received,
                'received_amount' => $received,
                'reference' => null,
            ]],
        ], $extra));
    }

    private function ensureCashSession(Company $company, Branch $branch, User $user): CashSession
    {
        $session = CashSession::query()->forCompany($company->id)->forBranch($branch->id)->where('opened_by', $user->id)->where('status', CashSession::STATUS_OPEN)->first();
        if ($session) {
            return $session;
        }

        $register = CashRegister::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'CAJA-'.uniqid(), 'name' => 'Caja', 'is_active' => true]);

        return CashSession::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'cash_register_id' => $register->id, 'session_number' => 'CAJA-'.uniqid(), 'opened_by' => $user->id, 'status' => CashSession::STATUS_OPEN, 'open_guard' => CashSession::OPEN_GUARD, 'opening_amount' => 0, 'opened_at' => now()]);
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
