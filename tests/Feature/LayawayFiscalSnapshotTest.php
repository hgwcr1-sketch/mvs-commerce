<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalProfile;
use App\Models\Layaway;
use App\Models\LayawayItemTax;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItemTax;
use App\Models\Unit;
use App\Models\User;
use App\Services\Fiscal\FiscalTaxService;
use App\Services\PaymentMethodProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LayawayFiscalSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_layaway_creation_freezes_fiscal_snapshot_and_child_tax_rows(): void
    {
        [$company, $branch, $user, $session, $cash, $product, $customer] = $this->context();

        $this->createLayaway($user, $company, $branch, $session, $cash, $product, $customer, 2000, 2)
            ->assertRedirect();

        $layaway = Layaway::with('items')->firstOrFail();
        $item = $layaway->items->first();

        $this->assertSame('13.0000', $item->tax_rate);
        $this->assertSame('01', $item->tax_code);
        $this->assertSame('08', $item->tax_rate_code);
        $this->assertSame('taxable', $item->tax_treatment);
        $this->assertEquals('08', $item->fiscal_snapshot['taxes'][0]['codigoTarifa']);
        $this->assertEquals(13, $item->fiscal_snapshot['taxes'][0]['tarifa']);
        $this->assertSame('2000.0000', $item->subtotal);
        $this->assertSame('260.0000', $item->tax_total);
        $this->assertSame('2260.0000', $item->total);
        $this->assertSame('2260.0000', (string) $layaway->total);

        $tax = LayawayItemTax::where('layaway_item_id', $item->id)->firstOrFail();
        $this->assertSame('01', $tax->tax_code);
        $this->assertSame('08', $tax->tax_rate_code);
        $this->assertSame('taxable', $tax->treatment);
        $this->assertSame('13.0000', $tax->rate);
        $this->assertSame('2000.0000', $tax->base_amount);
        $this->assertSame('260.0000', $tax->tax_amount);
        $this->assertSame(1, (int) $tax->sequence);
        $this->assertNull($tax->exemption_snapshot);
    }

    public function test_ambiguous_legacy_tax_rates_are_blocked_without_creating_layaways(): void
    {
        foreach ([0, 8, null] as $rate) {
            [$company, $branch, $user, $session, $cash, $product, $customer] = $this->context();

            $this->createLayaway($user, $company, $branch, $session, $cash, $product, $customer, 1000, 2, ['tax_rate' => $rate])
                ->assertRedirect()
                ->assertSessionHasErrors('items');
        }

        $this->assertDatabaseCount('layaways', 0);
        $this->assertDatabaseCount('layaway_items', 0);
        $this->assertDatabaseCount('layaway_item_taxes', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_zero_rate_exempt_and_not_subject_profiles_keep_their_own_treatment(): void
    {
        $expected = [
            ['01', 'zero_rate'],
            ['10', 'exempt'],
            ['11', 'not_subject'],
        ];

        foreach ($expected as [$rateCode, $treatment]) {
            [$company, $branch, $user, $session, $cash, $product, $customer] = $this->context();

            $product->update([
                'tax_rate' => 0,
                'fiscal_profile_id' => $this->profile('01', $rateCode),
            ]);

            $this->createLayaway($user, $company, $branch, $session, $cash, $product, $customer, 1000, 1)
                ->assertRedirect();

            $item = Layaway::latest('id')->firstOrFail()->items->first();
            $this->assertSame('0.0000', $item->tax_rate);
            $this->assertSame('0.0000', $item->tax_total);
            $this->assertSame($rateCode, $item->tax_rate_code);
            $this->assertSame($treatment, $item->tax_treatment);
            $this->assertSame($treatment, $item->fiscal_snapshot['taxes'][0]['treatment']);
        }
    }

    public function test_delivery_copies_the_frozen_snapshot_exactly_to_the_sale(): void
    {
        [$company, $branch, $user, $session, $cash, $product, $customer] = $this->context();

        $this->createLayaway($user, $company, $branch, $session, $cash, $product, $customer, 2260, 2)->assertRedirect();
        $layaway = Layaway::with('items')->firstOrFail();
        $this->assertSame(Layaway::STATUS_PAID, $layaway->status);

        $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->post(route('apartados.deliver', $layaway))
            ->assertRedirect();

        $this->assertSame(Layaway::STATUS_DELIVERED, $layaway->fresh()->status);
        $fiscalTaxService = app(FiscalTaxService::class);

        $layawayItem = $layaway->items()->first();
        $saleItem = Sale::firstOrFail()->items()->first();

        $this->assertSame(
            $fiscalTaxService->serializeSnapshot($layawayItem->fiscal_snapshot),
            $fiscalTaxService->serializeSnapshot($saleItem->fiscal_snapshot),
        );
        $this->assertSame('13.0000', $saleItem->tax_rate);
        $this->assertSame('260.0000', $saleItem->tax_total);

        $frozen = LayawayItemTax::where('layaway_item_id', $layawayItem->id)->firstOrFail();
        $tax = SaleItemTax::where('sale_item_id', $saleItem->id)->firstOrFail();

        $this->assertSame($frozen->tax_code, $tax->tax_code);
        $this->assertSame($frozen->tax_rate_code, $tax->tax_rate_code);
        $this->assertSame($frozen->treatment, $tax->treatment);
        $this->assertSame($frozen->rate, $tax->rate);
        $this->assertSame($frozen->factor_iva, $tax->factor_iva);
        $this->assertSame($frozen->base_amount, $tax->base_amount);
        $this->assertSame($frozen->tax_amount, $tax->tax_amount);
        $this->assertSame($frozen->source, $tax->source);
        $this->assertSame($frozen->source_version, $tax->source_version);
        $this->assertSame((int) $frozen->sequence, (int) $tax->sequence);
        $this->assertNull($tax->exemption_snapshot);
    }

    public function test_delivery_is_blocked_when_product_fiscality_changed_after_creation(): void
    {
        [$company, $branch, $user, $session, $cash, $product, $customer] = $this->context();

        $this->createLayaway($user, $company, $branch, $session, $cash, $product, $customer, 2260, 2)->assertRedirect();
        $layaway = Layaway::with('items')->firstOrFail();

        $product->update(['tax_rate' => 4]);

        $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->post(route('apartados.deliver', $layaway))
            ->assertSessionHasErrors('layaway');

        $this->assertSame(Layaway::STATUS_PAID, $layaway->fresh()->status);
        $this->assertNull($layaway->fresh()->delivered_sale_id);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_items', 0);
        $this->assertDatabaseCount('sale_item_taxes', 0);
    }

    private function context(array|string $permissions = ['apartados.ver', 'apartados.crear', 'apartados.abonar', 'apartados.cancelar', 'apartados.entregar']): array
    {
        if (is_string($permissions)) {
            $permissions = ['apartados.ver'];
        }

        $company = Company::create(['trade_name' => 'Empresa'.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'layaway_validity_days' => 30, 'layaway_alert_days' => 5, 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.uniqid(), 'is_active' => true]);
        $user = User::factory()->create();
        $role = Role::create(['company_id' => $company->id, 'name' => 'R'.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Test', 'is_active' => true]);
            $role->permissions()->syncWithoutDetaching($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        app(PaymentMethodProvisioner::class)->provision($company);
        $cash = PaymentMethod::forCompany($company->id)->where('type', 'cash')->firstOrFail();
        $register = CashRegister::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'C'.uniqid(), 'name' => 'Caja', 'is_active' => true]);
        $session = CashSession::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'cash_register_id' => $register->id, 'session_number' => 'CAJA-'.uniqid(), 'opened_by' => $user->id, 'status' => 'open', 'open_guard' => 'OPEN', 'opening_amount' => 0, 'opened_at' => now()]);

        $id = uniqid();
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'C'.$id, 'slug' => 'c'.$id, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad', 'abbreviation' => 'U', 'slug' => 'u'.$id, 'is_active' => true]);
        $product = Product::create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'name' => 'Producto',
            'internal_code' => 'P'.$id,
            'cost' => 500,
            'sale_price' => 1000,
            'tax_rate' => 13,
            'track_inventory' => true,
            'is_active' => true,
        ]);
        DB::table('branch_product')->insert(['branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => 5, 'created_at' => now(), 'updated_at' => now()]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Cliente', 'customer_type' => 'individual', 'credit_limit' => 0, 'is_active' => true]);

        return [$company, $branch, $user, $session, $cash, $product, $customer];
    }

    private function profile(string $taxCode, string $rateCode): int
    {
        return (int) FiscalProfile::query()->where('tax_code', $taxCode)->where('tax_rate_code', $rateCode)->value('id');
    }

    private function createLayaway(User $user, Company $company, Branch $branch, CashSession $session, PaymentMethod $cash, Product $product, Customer $customer, float $initialAmount, int $quantity, array $productOverrides = [])
    {
        if ($productOverrides !== []) {
            $product->update($productOverrides);
        }

        return $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])->post(route('apartados.store'), [
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
            'initial_amount' => $initialAmount,
            'payment_method_id' => $cash->id,
            'cash_session_id' => $session->id,
        ]);
    }
}
