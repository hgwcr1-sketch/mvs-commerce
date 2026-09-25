<?php

namespace Tests\Unit;

use App\Data\Purchases\PurchaseLineData;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\Unit;
use App\Services\Purchases\CompanyPurchaseSettingsResolver;
use App\Services\Purchases\ProductResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Validation\ValidationException;

class ProductResolverTaxRateTest extends TestCase
{
    use RefreshDatabase;

    private ProductResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ProductResolver(new CompanyPurchaseSettingsResolver());
    }

    public function test_missing_tax_rate_fails_explicitly_and_never_defaults_to_13(): void
    {
        [$company] = $this->context();
        $this->unit($company);

        try {
            $this->resolver->resolve($company, new PurchaseLineData(
                name: 'Producto sin tasa ' . uniqid(),
                category: 'Categoría ' . uniqid(),
                unit: 'Unidad U',
                unit_cost: 100,
                tax_rate: null,
            ));
            $this->fail('Expected ValidationException for tax_rate null');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('no se asume 13%', $exception->getMessage());
        }

        $this->assertDatabaseCount('products', 0);
    }

    public function test_provided_tax_rate_is_kept_without_invention(): void
    {
        [$company] = $this->context();
        $this->unit($company);

        foreach ([0.0, 1.0, 2.0, 4.0, 13.0] as $rate) {
            $product = $this->resolver->resolve($company, new PurchaseLineData(
                name: 'Producto con tasa ' . $rate . ' ' . uniqid(),
                category: 'Categoría ' . uniqid(),
                unit: 'Unidad U',
                unit_cost: 100,
                tax_rate: $rate,
            ));

            $this->assertSame($rate, (float) $product->tax_rate);
        }

        $this->assertSame(5, Product::query()->where('company_id', $company->id)->count());
        $this->assertSame(1, Product::query()->where('company_id', $company->id)->where('tax_rate', 13)->count());
    }

    private function context(): array
    {
        $company = Company::create(['trade_name' => 'Empresa ' . uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P' . uniqid(), 'is_active' => true]);

        return [$company, $branch];
    }

    private function unit(Company $company): Unit
    {
        return Unit::create(['company_id' => $company->id, 'name' => 'Unidad U', 'abbreviation' => 'U', 'slug' => 'u-' . uniqid(), 'is_active' => true]);
    }
}
