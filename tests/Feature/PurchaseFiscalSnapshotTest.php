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
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchaseFiscalSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_item_freezes_fiscal_snapshot_and_creates_tax_row(): void
    {
        [$company, $branch, $user, $supplier, $product] = $this->context();

        $purchase = $this->process($company, $branch, $user, $supplier, $product, 13);

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

    public function test_ambiguous_line_rate_without_explicit_profile_is_blocked(): void
    {
        [$company, $branch, $user, $supplier, $product] = $this->context(['tax_rate' => 0]);

        $blocked = false;

        try {
            $this->process($company, $branch, $user, $supplier, $product, 0);
        } catch (ValidationException $exception) {
            $blocked = true;
            $this->assertArrayHasKey('items', $exception->errors());
        }

        $this->assertTrue($blocked, 'La línea ambigua debió ser bloqueada.');
        $this->assertSame(0, Purchase::query()->count());
        $this->assertSame(0, PurchaseItem::query()->count());
    }

    public function test_unequivocal_line_rate_wins_over_product_exento_profile(): void
    {
        [$company, $branch, $user, $supplier, $product] = $this->context([
            'tax_rate' => 0,
            'fiscal_profile_id' => $this->profileId('10'),
        ]);

        $purchase = $this->process($company, $branch, $user, $supplier, $product, 13);
        $item = $purchase->items->firstOrFail();

        $this->assertSame(13.0, (float) $item->tax_rate);
        $this->assertSame('08', $item->tax_rate_code);
        $this->assertSame('taxable', $item->tax_treatment);
        $this->assertSame(130.0, (float) $purchase->tax);
        $this->assertSame(13.0, (float) $item->taxes()->firstOrFail()->rate);
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

    public function test_line_without_rate_resolves_product_legacy_13_percent(): void
    {
        [$company, $branch, $user, $supplier, $product] = $this->context(['tax_rate' => 13]);

        $purchase = $this->process($company, $branch, $user, $supplier, $product, null);
        $item = $purchase->items->firstOrFail();

        $this->assertSame(13.0, (float) $item->tax_rate);
        $this->assertSame('08', $item->tax_rate_code);
        $this->assertSame(130.0, (float) $purchase->tax);
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
