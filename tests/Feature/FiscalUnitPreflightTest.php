<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalProfile;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Sale;
use App\Models\Unit;
use App\Services\Fiscal\FiscalPreflightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Unidad de medida en el preflight FE/TE: la unidad de cada línea debe ser
 * convertible a una unidad fiscal ANTES de que el flujo llegue al proveedor.
 *
 * La normalización es global (catálogo MVS → código oficial), nunca por
 * producto ni por venta; una unidad no convertible bloquea con error humano
 * en lugar de fallar en silencio durante la emisión.
 */
class FiscalUnitPreflightTest extends TestCase
{
    use RefreshDatabase;

    private string $nombreProducto = 'Producto';

    public function test_unidad_general_pasa_el_preflight_como_unidad_fiscal(): void
    {
        $this->preflightConUnidad('GENERAL');

        $this->addToAssertionCount(1);
    }

    public function test_unidades_fiscales_validas_siguen_pasando_el_preflight(): void
    {
        foreach (['UN', 'UNIDAD', 'kg', 'LT'] as $unidad) {
            $this->preflightConUnidad($unidad);
        }

        $this->addToAssertionCount(1);
    }

    public function test_unidad_no_convertible_falla_en_preflight_con_error_humano(): void
    {
        try {
            $this->preflightConUnidad('PIEZA');
            $this->fail('La unidad no convertible debió bloquear el preflight.');
        } catch (ValidationException $exception) {
            $errores = $exception->errors();

            $this->assertSame(['items.0.unit_code'], array_keys($errores));
            $mensaje = $errores['items.0.unit_code'][0];
            $this->assertStringContainsString('PIEZA', $mensaje);
            $this->assertStringContainsString('no es convertible a una unidad fiscal', $mensaje);
            $this->assertStringContainsString('antes de emitir FE/TE', $mensaje);
        }
    }

    public function test_unidad_vacia_sigue_pidiendo_completar_la_unidad(): void
    {
        try {
            $this->preflightConUnidad('');
            $this->fail('La unidad vacía debió bloquear el preflight.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Complete la unidad de '.$this->nombreProducto.' antes de emitir FE/TE.',
                $exception->errors()['items.0.unit_code'][0]
            );
        }
    }

    private function preflightConUnidad(string $abbreviacion): void
    {
        $company = $this->company();
        $customer = $this->customer($company);
        $perfil = FiscalProfile::query()->where('tax_code', '01')->where('tax_rate_code', '08')->firstOrFail();
        $product = $this->product($company, $abbreviacion);
        $this->nombreProducto = $product->name;

        app(FiscalPreflightService::class)->validate(
            Sale::DOCUMENT_ELECTRONIC_INVOICE,
            $company,
            $customer,
            [['product' => $product, 'fiscalProfile' => $perfil]],
        );
    }

    private function company(): Company
    {
        return Company::create([
            'trade_name' => 'Empresa '.uniqid(),
            'legal_name' => 'Empresa Fiscal S.A.',
            'identification_number' => '3101000001',
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
    }

    private function customer(Company $company): Customer
    {
        return Customer::create([
            'company_id' => $company->id,
            'name' => 'Cliente',
            'customer_type' => 'individual',
            'identification_type' => '01',
            'identification' => '109880401',
            'is_active' => true,
        ]);
    }

    private function product(Company $company, string $abbreviation): Product
    {
        $suffix = uniqid();
        $category = ProductCategory::create([
            'company_id' => $company->id, 'name' => 'Cat '.$suffix, 'slug' => 'cat-'.$suffix, 'is_active' => true,
        ]);
        $unit = Unit::create([
            'company_id' => $company->id, 'name' => 'Unidad '.$suffix, 'abbreviation' => $abbreviation,
            'slug' => 'u-'.$suffix, 'allows_decimals' => false, 'is_active' => true,
        ]);

        return Product::create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'name' => 'Producto '.$suffix,
            'internal_code' => 'P-'.$suffix,
            'cost' => 500,
            'sale_price' => 1000,
            'cabys_code' => '3532306030100',
            'track_inventory' => false,
            'is_active' => true,
        ]);
    }
}
