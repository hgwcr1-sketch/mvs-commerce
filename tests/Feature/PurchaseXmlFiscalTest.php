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
use App\Models\PurchaseItemTax;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\Purchases\PurchaseProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchaseXmlFiscalTest extends TestCase
{
    use RefreshDatabase;

    public function test_multi_tax_and_exoneration_are_frozen_faithfully(): void
    {
        [$company, $branch, $user, $product] = $this->context();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('compras.import.xml'), ['file' => $this->uploaded($this->xmlMultiTax())])
            ->assertRedirect(route('compras.import.review'));

        $validation = session('purchase_import_validation');
        $this->assertEmpty($validation['missing'], 'El producto precreado debe resolverse por código.');

        $this->post(route('compras.import.confirm'))->assertRedirect();

        $item = PurchaseItem::query()->sole();
        $this->assertSame(13.0, (float) $item->tax_rate);
        $this->assertSame('01', $item->tax_code);
        $this->assertSame('08', $item->tax_rate_code);

        $snapshot = $item->fiscal_snapshot;
        $this->assertCount(2, $snapshot['document']['impuestos']);
        $this->assertSame('01', $snapshot['document']['impuestos'][0]['codigo']);
        $this->assertSame('08', $snapshot['document']['impuestos'][0]['codigo_tarifa']);
        $this->assertSame('02', $snapshot['document']['impuestos'][1]['codigo']);

        $taxes = PurchaseItemTax::query()->where('purchase_item_id', $item->id)->orderBy('sequence')->get();
        $this->assertCount(2, $taxes);

        $primary = $taxes[0];
        $this->assertSame(1, (int) $primary->sequence);
        $this->assertSame('xml_hacienda', $primary->source);
        $this->assertSame('01', $primary->tax_code);
        $this->assertSame('08', $primary->tax_rate_code);
        $this->assertSame(13.0, (float) $primary->rate);
        $this->assertSame(1500.0, (float) $primary->base_amount);
        $this->assertSame(65.0, (float) $primary->tax_amount);
        $this->assertSame('5060100000001', $primary->exemption_snapshot['documento'] ?? null);

        $secondary = $taxes[1];
        $this->assertSame(2, (int) $secondary->sequence);
        $this->assertSame('02', $secondary->tax_code);
        $this->assertSame(1.5, (float) $secondary->rate);
        $this->assertSame(9.75, (float) $secondary->tax_amount);
        $this->assertNull($secondary->base_amount);

        // Montos explícitos del documento = autoridad económica:
        // 1500 + IVA 65.00 + impuesto 02 de 9.75 = 1574.75.
        $purchase = Purchase::query()->sole();
        $this->assertSame(1500.0, (float) $purchase->subtotal);
        $this->assertSame(74.75, (float) $purchase->tax);
        $this->assertSame(1574.75, (float) $purchase->total);
        $this->assertSame(74.75, (float) $item->tax);
        $this->assertSame(1574.75, (float) $item->total);

        $product->refresh();
        $this->assertSame($this->profile('01', '08')->id, (int) $product->fiscal_profile_id);
        $this->assertSame(13.0, (float) $product->tax_rate);
    }

    public function test_exoneration_net_amount_is_used_without_recharging_the_exempt_part(): void
    {
        [$company, $branch, $user, $product] = $this->context();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('compras.import.xml'), ['file' => $this->uploaded($this->xmlPartialExoneration())])
            ->assertRedirect(route('compras.import.review'));

        $this->post(route('compras.import.confirm'))->assertRedirect();

        $item = PurchaseItem::query()->sole();
        $purchase = Purchase::query()->sole();

        // El XML trae Monto 19.50 (neto después de exoneración); el 13% sobre
        // 1500 serían 195.00. No se vuelve a cobrar la parte exonerada.
        $this->assertSame(19.5, (float) $item->tax);
        $this->assertSame(1519.5, (float) $item->total);
        $this->assertSame(19.5, (float) $purchase->tax);
        $this->assertSame(1519.5, (float) $purchase->total);

        $primary = PurchaseItemTax::query()->where('purchase_item_id', $item->id)->orderBy('sequence')->sole();
        $this->assertSame(19.5, (float) $primary->tax_amount);
        $this->assertSame('5060100000001', $primary->exemption_snapshot['documento'] ?? null);
        $this->assertSame(19.5, (float) ($primary->exemption_snapshot['monto_impuesto'] ?? -1));
    }

    public function test_credit_account_payable_uses_the_document_complete_total(): void
    {
        [$company, $branch, $user, $product] = $this->context();

        $supplier = Supplier::query()->where('company_id', $company->id)->firstOrFail();

        $purchase = app(PurchaseProcessor::class)->process(new PurchaseData(
            company_id: $company->id,
            branch_id: $branch->id,
            supplier_id: $supplier->id,
            user_id: $user->id,
            purchase_date: now()->toDateString(),
            payment_type: 'credit',
            due_date: now()->addDays(30)->toDateString(),
            lines: [
                new PurchaseLineData(
                    product_id: $product->id,
                    quantity: 3,
                    unit_cost: 500,
                    document_taxes: [
                        ['codigo' => '01', 'codigo_tarifa' => '08', 'tarifa' => '13', 'monto' => '65.00'],
                        ['codigo' => '02', 'tarifa' => '1.5', 'monto' => '9.75'],
                    ],
                ),
            ],
        ));

        $this->assertSame(1574.75, (float) $purchase->total);

        $account = $purchase->accountPayable;
        $this->assertNotNull($account);
        $this->assertSame(1574.75, (float) $account->original_amount);
        $this->assertSame(1574.75, (float) $account->balance_due);
    }

    public function test_simple_xml_without_explicit_amounts_keeps_the_computed_iva_regression(): void
    {
        [$company, $branch, $user, $product] = $this->context();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('compras.import.xml'), ['file' => $this->uploaded($this->xmlSimple())])
            ->assertRedirect(route('compras.import.review'));

        $this->post(route('compras.import.confirm'))->assertRedirect();

        $item = PurchaseItem::query()->sole();
        $purchase = Purchase::query()->sole();

        // Sin Monto explícito en el XML: se calcula 1500 × 13% = 195.00 (comportamiento previo).
        $this->assertSame(195.0, (float) $item->tax);
        $this->assertSame(1695.0, (float) $item->total);
        $this->assertSame(195.0, (float) $purchase->tax);
        $this->assertSame(1695.0, (float) $purchase->total);

        $primary = PurchaseItemTax::query()->where('purchase_item_id', $item->id)->orderBy('sequence')->sole();
        $this->assertSame(195.0, (float) $primary->tax_amount);
        $this->assertNull($primary->exemption_snapshot);
    }

    public function test_document_rate_without_unequivocal_classification_blocks_the_line(): void
    {
        [$company, $branch, $user, $product] = $this->context();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('compras.import.xml'), ['file' => $this->uploaded($this->xmlAmbiguous())])
            ->assertRedirect(route('compras.import.review'));

        $this->post(route('compras.import.confirm'))
            ->assertSessionHasErrors('items');

        $this->assertDatabaseCount('purchases', 0);
    }

    public function test_document_treatment_wins_over_the_product_profile(): void
    {
        [$company, $branch, $user, $product] = $this->context();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('compras.import.xml'), ['file' => $this->uploaded($this->xmlExempt())])
            ->assertRedirect(route('compras.import.review'));

        $this->post(route('compras.import.confirm'))->assertRedirect();

        $item = PurchaseItem::query()->sole();
        $this->assertSame(0.0, (float) $item->tax_rate);
        $this->assertSame('10', $item->tax_rate_code);
        $this->assertSame('exempt', $item->tax_treatment);

        // El producto conserva su perfil de 13%: el documento no lo sustituye.
        $product->refresh();
        $this->assertSame($this->profile('01', '08')->id, (int) $product->fiscal_profile_id);
        $this->assertSame(13.0, (float) $product->tax_rate);
    }

    private function context(): array
    {
        $suffix = Str::lower(Str::random(8));
        $company = Company::create(['trade_name' => 'XmlFiscal '.$suffix, 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.$suffix, 'is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.$suffix, 'is_active' => true]);
        $permission = Permission::firstOrCreate(['name' => 'compras.crear'], ['label' => 'compras.crear', 'module' => 'Compras', 'is_active' => true]);
        $role->permissions()->attach($permission);
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        Supplier::create(['company_id' => $company->id, 'supplier_type' => 'company', 'name' => 'Proveedor XML', 'is_active' => true]);
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'General '.$suffix, 'slug' => 'general-'.$suffix, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad '.$suffix, 'abbreviation' => 'U'.$suffix, 'slug' => 'unidad-'.$suffix, 'is_active' => true]);
        $product = Product::create([
            'company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id,
            'name' => 'Producto XML '.$suffix, 'internal_code' => 'XML-1234567890123',
            'cost' => 400, 'sale_price' => 800, 'tax_rate' => 13,
            'fiscal_profile_id' => $this->profile('01', '08')->id,
            'track_inventory' => true, 'is_active' => true,
        ]);

        return [$company, $branch, $user, $product];
    }

    private function profile(string $taxCode, string $rateCode): FiscalProfile
    {
        return FiscalProfile::query()
            ->where('tax_code', $taxCode)
            ->where('tax_rate_code', $rateCode)
            ->where('is_active', true)
            ->firstOrFail();
    }

    private function xmlMultiTax(): string
    {
        return $this->xml(<<<'XML'
<Impuesto><Codigo>01</Codigo><CodigoTarifaIVA>08</CodigoTarifaIVA><Tarifa>13</Tarifa><Monto>65.00</Monto><FactorCalculoIVA>1</FactorCalculoIVA><Exoneracion><NumeroDocumento>5060100000001</NumeroDocumento><FechaEmision>2026-01-15</FechaEmision><NombreInstitucion>Hacienda</NombreInstitucion><MontoImpuesto>65.00</MontoImpuesto><PorcentajeCompra>13.00</PorcentajeCompra></Exoneracion></Impuesto><Impuesto><Codigo>02</Codigo><Tarifa>1.5</Tarifa><Monto>9.75</Monto></Impuesto>
XML);
    }

    private function xmlAmbiguous(): string
    {
        return $this->xml('<Impuesto><Tarifa>8.5</Tarifa><Monto>127.50</Monto></Impuesto>');
    }

    private function xmlExempt(): string
    {
        return $this->xml('<Impuesto><Codigo>01</Codigo><CodigoTarifaIVA>10</CodigoTarifaIVA><Tarifa>0</Tarifa><Monto>0.00</Monto></Impuesto>');
    }

    private function xmlPartialExoneration(): string
    {
        return $this->xml(<<<'XML'
<Impuesto><Codigo>01</Codigo><CodigoTarifaIVA>08</CodigoTarifaIVA><Tarifa>13</Tarifa><Monto>19.50</Monto><FactorCalculoIVA>1</FactorCalculoIVA><Exoneracion><NumeroDocumento>5060100000001</NumeroDocumento><FechaEmision>2026-01-15</FechaEmision><NombreInstitucion>Hacienda</NombreInstitucion><MontoImpuesto>19.50</MontoImpuesto><PorcentajeCompra>13.00</PorcentajeCompra></Exoneracion></Impuesto>
XML);
    }

    private function xmlSimple(): string
    {
        return $this->xml('<Impuesto><Codigo>01</Codigo><CodigoTarifaIVA>08</CodigoTarifaIVA><Tarifa>13</Tarifa></Impuesto>');
    }

    private function xml(string $impuestos): string
    {
        $path = tempnam(sys_get_temp_dir(), 'purchase-xml-fiscal-').'.xml';
        file_put_contents($path, '<?xml version="1.0" encoding="UTF-8"?><FacturaElectronica><Clave>50601082600011111111100100001010000000001111111111</Clave><FechaEmision>2026-08-26T10:00:00-06:00</FechaEmision><Emisor><Nombre>Proveedor XML</Nombre><Identificacion><Numero>3101123456</Numero></Identificacion></Emisor><DetalleServicio><LineaDetalle><CodigoCABYS>1234567890123</CodigoCABYS><Cantidad>3</Cantidad><UnidadMedida>Unid</UnidadMedida><Detalle>Artículo XML</Detalle><PrecioUnitario>500</PrecioUnitario>'.$impuestos.'</LineaDetalle></DetalleServicio></FacturaElectronica>');

        return $path;
    }

    private function uploaded(string $path): UploadedFile
    {
        return new UploadedFile($path, 'factura.xml', 'application/xml', null, true);
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
