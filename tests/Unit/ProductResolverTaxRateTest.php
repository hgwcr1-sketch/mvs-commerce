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
        $this->resolver = new ProductResolver(
            new CompanyPurchaseSettingsResolver(),
            app(\App\Services\Fiscal\FiscalTaxService::class),
        );
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
            $this->assertStringContainsString('no incluye perfil fiscal', $exception->getMessage());
        }

        $this->assertDatabaseCount('products', 0);
    }

    public function test_ambiguous_rates_are_blocked_and_unequivocal_rates_are_kept(): void
    {
        [$company] = $this->context();
        $this->unit($company);

        foreach ([0.0, 8.0] as $rate) {
            try {
                $this->resolver->resolve($company, new PurchaseLineData(
                    name: 'Producto ambiguo ' . $rate . ' ' . uniqid(),
                    category: 'Categoría ' . uniqid(),
                    unit: 'Unidad U',
                    unit_cost: 100,
                    tax_rate: $rate,
                ));
                $this->fail('Expected ValidationException for ambiguous rate '.$rate);
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }

        foreach ([1.0, 2.0, 4.0, 13.0] as $rate) {
            $product = $this->resolver->resolve($company, new PurchaseLineData(
                name: 'Producto con tasa ' . $rate . ' ' . uniqid(),
                category: 'Categoría ' . uniqid(),
                unit: 'Unidad U',
                unit_cost: 100,
                tax_rate: $rate,
            ));

            $this->assertSame($rate, (float) $product->tax_rate);
            $this->assertNotNull($product->fiscal_profile_id);
        }

        $this->assertSame(4, Product::query()->where('company_id', $company->id)->count());
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
