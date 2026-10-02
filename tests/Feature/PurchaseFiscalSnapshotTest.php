<?php

namespace Tests\Feature;

use App\Data\Purchases\PurchaseData;
use App\Data\Purchases\PurchaseLineData;
use App\Models\Branch;
use App\Models\Company;
use App\Models\FiscalProfile;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\Purchases\CompanyPurchaseSettingsResolver;
use App\Services\Purchases\PurchaseProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchaseFiscalSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_item_freezes_fiscal_snapshot_and_creates_tax_row(): void
    {
        [$company, $branch, $user, $supplier, $product] = $this->context([
            'fiscal_profile_id' => $this->profileId('08'),
        ]);

        $purchase = $this->process($company, $branch, $user, $supplier, $product, null);

        $item = $purchase->items->firstOrFail();

        $this->assertSame(13.0, (float) $item->tax_rate);
        $this->assertSame('01', $item->tax_code);
        $this->assertSame('08', $item->tax_rate_code);
        $this->assertSame('taxable', $item->tax_treatment);
        $this->assertSame('Hacienda v4.4 / Facturaencr OpenAPI', $item->fiscal_source);
        $this->assertSame('v4.4', $item->fiscal_source_version);

        $snapshot = $item->fiscal_snapshot;
        $this->assertSame('01', $snapshot['taxes'][0]['codigo']);
        $this->assertSame('08', $snapshot['taxes'][0]['codigoTarifa']);
        $this->assertSame(13.0, (float) $snapshot['taxes'][0]['tarifa']);
        $this->assertSame('taxable', $snapshot['taxes'][0]['treatment']);

        $row = $item->taxes()->firstOrFail();
        $this->assertSame('01', $row->tax_code);
        $this->assertSame('08', $row->tax_rate_code);
        $this->assertSame('taxable', $row->treatment);
        $this->assertSame(13.0, (float) $row->rate);
        $this->assertSame(1000.0, (float) $row->base_amount);
        $this->assertSame(130.0, (float) $row->tax_amount);
        $this->assertSame(1, $row->sequence);

        $this->assertSame(130.0, (float) $purchase->tax);
        $this->assertSame(1130.0, (float) $purchase->total);
    }

    public function test_purchase_accepts_pending_profile_and_keeps_operational_tax_rate(): void
    {
        [$company, $branch, $user, $supplier, $product] = $this->context(['tax_rate' => 0]);

        $purchase = $this->process($company, $branch, $user, $supplier, $product, 8);
        $item = $purchase->items->firstOrFail();

        $this->assertSame(8.0, (float) $item->tax_rate);
        $this->assertNull($item->tax_code);
        $this->assertNull($item->fiscal_snapshot);
        $this->assertSame(80.0, (float) $purchase->tax);
        $this->assertDatabaseCount('purchase_item_taxes', 0);
        $this->assertNull($product->fresh()->fiscal_profile_id);
    }

    public function test_operational_line_rate_does_not_infer_or_replace_product_profile(): void
    {
        [$company, $branch, $user, $supplier, $product] = $this->context([
            'tax_rate' => 0,
            'fiscal_profile_id' => $this->profileId('10'),
        ]);

        $purchase = $this->process($company, $branch, $user, $supplier, $product, 13);
        $item = $purchase->items->firstOrFail();

        $this->assertSame(13.0, (float) $item->tax_rate);
        $this->assertNull($item->tax_code);
        $this->assertNull($item->tax_rate_code);
        $this->assertNull($item->tax_treatment);
        $this->assertSame(130.0, (float) $purchase->tax);
        $this->assertSame(0, $item->taxes()->count());
        $this->assertSame($this->profileId('10'), (int) $product->fresh()->fiscal_profile_id);
    }

    public function test_explicit_exento_profile_allows_zero_rate_line(): void
    {
        [$company, $branch, $user, $supplier, $product] = $this->context([
            'tax_rate' => 0,
            'fiscal_profile_id' => $this->profileId('10'),
        ]);

        $purchase = $this->process($company, $branch, $user, $supplier, $product, 0);
        $item = $purchase->items->firstOrFail();

        $this->assertSame(0.0, (float) $item->tax_rate);
        $this->assertSame('01', $item->tax_code);
        $this->assertSame('10', $item->tax_rate_code);
        $this->assertSame('exempt', $item->tax_treatment);
        $this->assertSame(0.0, (float) $purchase->tax);

        $row = $item->taxes()->firstOrFail();
        $this->assertSame(1000.0, (float) $row->base_amount);
        $this->assertSame(0.0, (float) $row->tax_amount);
    }

    public function test_line_without_rate_resolves_product_not_subject_profile(): void
    {
        [$company, $branch, $user, $supplier, $product] = $this->context([
            'tax_rate' => 0,
            'fiscal_profile_id' => $this->profileId('11'),
        ]);

        $purchase = $this->process($company, $branch, $user, $supplier, $product, null);
        $item = $purchase->items->firstOrFail();

        $this->assertSame(0.0, (float) $item->tax_rate);
        $this->assertSame('11', $item->tax_rate_code);
        $this->assertSame('not_subject', $item->tax_treatment);
        $this->assertSame(0.0, (float) $purchase->tax);
    }

    public function test_operational_product_tax_rate_does_not_create_purchase_snapshot(): void
    {
        [$company, $branch, $user, $supplier, $product] = $this->context(['tax_rate' => 13]);

        $purchase = $this->process($company, $branch, $user, $supplier, $product, null);
        $item = $purchase->items->firstOrFail();

        $this->assertSame(13.0, (float) $item->tax_rate);
        $this->assertNull($item->tax_rate_code);
        $this->assertNull($item->fiscal_snapshot);
        $this->assertSame(130.0, (float) $purchase->tax);
        $this->assertNull($product->fresh()->fiscal_profile_id);
    }

    private function process(
        Company $company,
        Branch $branch,
        User $user,
        Supplier $supplier,
        Product $product,
        ?float $lineRate,
    ): Purchase {
        return app(PurchaseProcessor::class)->process(new PurchaseData(
            company_id: $company->id,
            branch_id: $branch->id,
            supplier_id: $supplier->id,
            user_id: $user->id,
            purchase_date: today()->toDateString(),
            payment_type: 'cash',
            due_date: null,
            notes: 'Compra con snapshot fiscal',
            lines: [new PurchaseLineData(
                product_id: $product->id,
                quantity: 2,
                unit_cost: 500,
                tax_rate: $lineRate,
            )],
        ));
    }

    private function profileId(string $rateCode): int
    {
        return (int) FiscalProfile::query()
            ->where('tax_code', '01')
            ->where('tax_rate_code', $rateCode)
            ->value('id');
    }

    /**
     * @param  array<string, mixed>  $productAttributes
     * @return array{0: Company, 1: Branch, 2: User, 3: Supplier, 4: Product}
     */
    private function context(array $productAttributes = []): array
    {
        $company = Company::create([
            'trade_name' => 'Empresa '.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
        $branch = Branch::create([
            'company_id' => $company->id,
            'name' => 'Principal',
            'code' => 'P'.uniqid(),
            'is_active' => true,
        ]);

        $user = User::factory()->create();
        $role = Role::create([
            'company_id' => $company->id,
            'name' => 'Compras '.uniqid(),
            'is_active' => true,
        ]);
        $permission = Permission::firstOrCreate(
            ['name' => 'compras.crear'],
            ['label' => 'Crear compras', 'module' => 'Compras', 'is_active' => true],
        );
        $role->permissions()->attach($permission->id);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        app(CompanyPurchaseSettingsResolver::class)->forCompany($company);

        $supplier = Supplier::create([
            'company_id' => $company->id,
            'supplier_type' => 'company',
            'name' => 'Proveedor '.uniqid(),
            'credit_days' => 30,
            'is_active' => true,
        ]);

        $id = Str::lower(Str::random(8));
        $category = ProductCategory::create([
            'company_id' => $company->id,
            'name' => 'Categoría '.$id,
            'slug' => 'cat-'.$id,
            'is_active' => true,
        ]);
        $unit = Unit::create([
            'company_id' => $company->id,
            'name' => 'Unidad '.$id,
            'abbreviation' => 'U',
            'slug' => 'u-'.$id,
            'allows_decimals' => false,
            'is_active' => true,
        ]);

        $product = Product::create(array_merge([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'name' => 'Producto '.$id,
            'internal_code' => 'P-'.$id,
            'cost' => 400,
            'sale_price' => 800,
            'tax_rate' => 0,
            'track_inventory' => true,
            'is_active' => true,
        ], $productAttributes));

        return [$company, $branch, $user, $supplier, $product];
    }
}
