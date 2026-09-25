<?php

namespace Tests\Unit;

use App\Models\FiscalCatalogVersion;
use App\Models\FiscalProfile;
use App\Models\SaleItem;
use App\Services\Fiscal\FiscalTaxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class FiscalTaxServiceTest extends TestCase
{
    use RefreshDatabase;

    private FiscalTaxService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->service = new FiscalTaxService();
    }

    public function test_iva_13_percent_uses_tax_code_01_with_rate_code_08(): void
    {
        $profile = $this->service->resolveProfile('01', '08');

        $this->assertSame('01', $profile->tax_code);
        $this->assertSame('08', $profile->tax_rate_code);
        $this->assertSame(13.0, (float) $profile->rate);
        $this->assertSame('taxable', $profile->treatment);

        $snapshot = $this->service->snapshotFromProfile($profile);

        $this->assertSame('01', $snapshot['taxes'][0]['codigo']);
        $this->assertSame('08', $snapshot['taxes'][0]['codigoTarifa']);
        $this->assertSame(13.0, (float) $snapshot['taxes'][0]['tarifa']);
    }

    public function test_zero_percent_article_32_1_uses_rate_code_01(): void
    {
        $profile = $this->service->resolveProfile('01', '01');

        $this->assertSame('01', $profile->tax_rate_code);
        $this->assertSame(0.0, (float) $profile->rate);
        $this->assertSame('zero_rate', $profile->treatment);
    }

    public function test_exempt_uses_rate_code_10(): void
    {
        $profile = $this->service->resolveProfile('01', '10');

        $this->assertSame('10', $profile->tax_rate_code);
        $this->assertSame(0.0, (float) $profile->rate);
        $this->assertSame('exempt', $profile->treatment);
    }

    public function test_not_subject_uses_rate_code_11(): void
    {
        $profile = $this->service->resolveProfile('01', '11');

        $this->assertSame('11', $profile->tax_rate_code);
        $this->assertSame(0.0, (float) $profile->rate);
        $this->assertSame('not_subject', $profile->treatment);
    }

    public function test_zero_exempt_and_not_subject_are_distinct_treatments(): void
    {
        $zero = $this->service->resolveProfile('01', '01');
        $exempt = $this->service->resolveProfile('01', '10');
        $notSubject = $this->service->resolveProfile('01', '11');

        $treatments = [$zero->treatment, $exempt->treatment, $notSubject->treatment];
        $rateCodes = [$zero->tax_rate_code, $exempt->tax_rate_code, $notSubject->tax_rate_code];

        $this->assertSame(['zero_rate', 'exempt', 'not_subject'], $treatments);
        $this->assertSame(['01', '10', '11'], $rateCodes);
        $this->assertCount(3, array_unique($treatments), '0%, Exento y No Sujeto deben ser tratamientos distintos');
        $this->assertCount(3, array_unique($rateCodes));

        foreach ([$zero, $exempt, $notSubject] as $profile) {
            $this->assertSame(0.0, (float) $profile->rate);
        }

        $this->assertNotSame($zero->treatment, $exempt->treatment);
        $this->assertNotSame($zero->treatment, $notSubject->treatment);
        $this->assertNotSame($exempt->treatment, $notSubject->treatment);
    }

    public function test_legacy_tax_rate_zero_is_ambiguous_and_not_auto_resolvable(): void
    {
        foreach ([0.0, 0, null] as $legacyRate) {
            try {
                $this->service->resolveLegacyTaxRate($legacyRate);
                $this->fail('Expected InvalidArgumentException for tax_rate=' . var_export($legacyRate, true));
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('ambiguo', $exception->getMessage());
            }
        }

        $item = $this->makeSaleItem(['tax_rate' => 0]);

        try {
            $this->service->snapshotForSaleItem($item);
            $this->fail('Expected InvalidArgumentException for legacy tax_rate=0');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('ambiguo', $exception->getMessage());
        }
    }

    public function test_legacy_tax_rate_without_unequivocal_equivalence_is_rejected(): void
    {
        foreach ([5.0, 8.0, 12.0] as $legacyRate) {
            try {
                $this->service->resolveLegacyTaxRate($legacyRate);
                $this->fail('Expected InvalidArgumentException for tax_rate=' . $legacyRate);
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('equivalencia fiscal', $exception->getMessage());
            }
        }
    }

    public function test_legacy_tax_rate_reduced_iva_rates_map_unequivocally(): void
    {
        $cases = [1.0 => '02', 2.0 => '03', 4.0 => '04', 13.0 => '08'];

        foreach ($cases as $legacyRate => $rateCode) {
            $profile = $this->service->resolveLegacyTaxRate($legacyRate);

            $this->assertSame('01', $profile->tax_code);
            $this->assertSame($rateCode, $profile->tax_rate_code);
        }
    }

    public function test_legacy_tax_rate_eight_is_rejected_for_products(): void
    {
        try {
            $this->service->resolveLegacyTaxRate(8.0, '01');
            $this->fail('Expected InvalidArgumentException for tax_rate=8');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('equivalencia fiscal', $exception->getMessage());
        }
    }

    public function test_resolve_for_product_prefers_explicit_profile(): void
    {
        $exempt = $this->service->resolveProfile('01', '10');

        $profile = $this->service->resolveForProduct($exempt->id, 13.0);

        $this->assertSame($exempt->id, $profile->id);
        $this->assertSame('exempt', $profile->treatment);
        $this->assertSame('10', $profile->tax_rate_code);
    }

    public function test_resolve_for_product_legacy_bridge_is_unequivocal_only(): void
    {
        $profile = $this->service->resolveForProduct(null, 13.0);

        $this->assertSame('08', $profile->tax_rate_code);

        foreach ([null, 0.0, 8.0] as $legacyRate) {
            try {
                $this->service->resolveForProduct(null, $legacyRate);
                $this->fail('Expected InvalidArgumentException for legacy tax_rate=' . var_export($legacyRate, true));
            } catch (InvalidArgumentException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
    }

    public function test_product_profiles_include_zero_exempt_and_not_subject(): void
    {
        $profiles = $this->service->productProfiles();

        $this->assertNotEmpty($profiles);

        $rateCodes = $profiles->pluck('tax_rate_code')->all();

        $this->assertContains('01', $rateCodes);
        $this->assertContains('10', $rateCodes);
        $this->assertContains('11', $rateCodes);
        $this->assertContains('08', $rateCodes);

        foreach (['05', '06', '07'] as $transitional) {
            $this->assertNotContains($transitional, $rateCodes);
        }

        foreach ($profiles as $profile) {
            $this->assertSame('01', $profile->tax_code);
            $this->assertContains('01', (array) $profile->document_types);
        }
    }

    public function test_legacy_tax_rate_13_keeps_unequivocal_iva_equivalence(): void
    {
        $profile = $this->service->resolveLegacyTaxRate(13.0);

        $this->assertSame('01', $profile->tax_code);
        $this->assertSame('08', $profile->tax_rate_code);
        $this->assertSame(13.0, (float) $profile->rate);
    }

    public function test_transitional_rate_codes_are_blocked_for_normal_invoice(): void
    {
        foreach (['05', '06', '07'] as $rateCode) {
            try {
                $this->service->resolveProfile('01', $rateCode, '01');
                $this->fail('Expected InvalidArgumentException for rate_code=' . $rateCode . ' on factura');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('transitoria', $exception->getMessage());
            }

            $this->assertSame('01', $this->service->resolveProfile('01', $rateCode, '02')->tax_code);
            $this->assertSame('01', $this->service->resolveProfile('01', $rateCode, '03')->tax_code);
        }

        $snapshot = [
            'source' => 'test',
            'source_version' => 'v1',
            'taxes' => [
                ['codigo' => '01', 'codigoTarifa' => '08', 'tarifa' => 13.0],
                ['codigo' => '01', 'codigoTarifa' => '07', 'tarifa' => 8.0],
            ],
        ];

        try {
            $this->service->validateSnapshot($snapshot, '01');
            $this->fail('Expected InvalidArgumentException for transitional rate on factura');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('transitoria', $exception->getMessage());
        }

        $this->service->validateSnapshot($snapshot, '02');
        $this->service->validateSnapshot($snapshot, '03');
    }

    public function test_multiple_taxes_per_line_are_preserved(): void
    {
        $snapshot = [
            'source' => 'Hacienda v4.4 / Facturaencr OpenAPI',
            'source_version' => 'v4.4',
            'taxes' => [
                ['codigo' => '01', 'codigoTarifa' => '08', 'tarifa' => 13.0],
                [
                    'codigo' => '12',
                    'tarifa' => 5.0,
                    'datosImpuestoEspecifico' => [
                        'montoImpuestoEspecifico' => 50.0,
                        'unidadMedida' => 'kg',
                    ],
                ],
            ],
        ];

        $serialized = $this->service->serializeSnapshot($snapshot);

        $this->assertCount(2, $serialized);
        $this->assertSame('01', $serialized[0]['codigo']);
        $this->assertSame('08', $serialized[0]['codigoTarifa']);
        $this->assertSame('12', $serialized[1]['codigo']);
        $this->assertSame(5, $serialized[1]['tarifa']);
        $this->assertSame(['montoImpuestoEspecifico' => 50.0, 'unidadMedida' => 'kg'], $serialized[1]['datosImpuestoEspecifico']);
    }

    public function test_impuesto_12_conserves_five_percent_rate(): void
    {
        $snapshot = [
            'taxes' => [
                ['codigo' => '01', 'codigoTarifa' => '08', 'tarifa' => 13.0],
                [
                    'codigo' => '12',
                    'tarifa' => 5.0,
                    'datosImpuestoEspecifico' => ['montoImpuestoEspecifico' => 25.0],
                ],
            ],
        ];

        $serialized = $this->service->serializeSnapshot($snapshot);

        $this->assertSame(5, $serialized[1]['tarifa']);
        $this->assertSame(13, $serialized[0]['tarifa']);

        $profile = FiscalProfile::query()
            ->where('tax_code', '12')
            ->whereNull('tax_rate_code')
            ->first();

        $this->assertNotNull($profile);
        $this->assertSame(5.0, (float) $profile->rate);
    }

    public function test_tax_code_08_requires_factor_iva(): void
    {
        try {
            $this->service->resolveProfile('08', null);
            $this->fail('Expected InvalidArgumentException for tax_code=08 without factorIVA');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('factorIVA', $exception->getMessage());
        }

        foreach ([
            ['codigo' => '08'],
            ['codigo' => '08', 'factorIVA' => null],
        ] as $tax) {
            try {
                $this->service->serializeSnapshot(['taxes' => [$tax]]);
                $this->fail('Expected InvalidArgumentException for tax_code=08 without FactorCalculoIVA');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('factorIVA', $exception->getMessage());
            }
        }

        $serialized = $this->service->serializeSnapshot([
            'taxes' => [['codigo' => '08', 'factorIVA' => 0.666667]],
        ]);

        $this->assertSame('08', $serialized[0]['codigo']);
        $this->assertSame(0.666667, $serialized[0]['factorIVA']);
    }

    public function test_tax_code_99_requires_codigo_impuesto_otro(): void
    {
        try {
            $this->service->resolveProfile('99', null);
            $this->fail('Expected InvalidArgumentException for tax_code=99 without codigoTarifaOtro');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('codigoTarifaOtro', $exception->getMessage());
        }

        try {
            $this->service->serializeSnapshot([
                'taxes' => [
                    ['codigo' => '01', 'codigoTarifa' => '08', 'tarifa' => 13.0],
                    ['codigo' => '99'],
                ],
            ]);
            $this->fail('Expected InvalidArgumentException for tax_code=99 without CodigoImpuestoOTRO');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('codigoTarifaOtro', $exception->getMessage());
        }

        $serialized = $this->service->serializeSnapshot([
            'taxes' => [
                ['codigo' => '01', 'codigoTarifa' => '08', 'tarifa' => 13.0],
                ['codigo' => '99', 'codigoTarifaOtro' => 'OTRO-IMP-01'],
            ],
        ]);

        $this->assertSame('OTRO-IMP-01', $serialized[1]['codigoTarifaOtro']);
    }

    public function test_specific_taxes_require_datos_impuesto_especifico(): void
    {
        foreach (['03', '04', '05', '06'] as $taxCode) {
            try {
                $this->service->serializeSnapshot([
                    'taxes' => [
                        ['codigo' => '01', 'codigoTarifa' => '08', 'tarifa' => 13.0],
                        ['codigo' => $taxCode],
                    ],
                ]);
                $this->fail('Expected InvalidArgumentException for tax_code=' . $taxCode . ' without datosImpuestoEspecifico');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('datosImpuestoEspecifico', $exception->getMessage());
            }

            $serialized = $this->service->serializeSnapshot([
                'taxes' => [
                    ['codigo' => '01', 'codigoTarifa' => '08', 'tarifa' => 13.0],
                    [
                        'codigo' => $taxCode,
                        'datosImpuestoEspecifico' => ['montoImpuestoEspecifico' => 15.0],
                    ],
                ],
            ]);

            $this->assertSame($taxCode, $serialized[1]['codigo']);
            $this->assertSame(['montoImpuestoEspecifico' => 15.0], $serialized[1]['datosImpuestoEspecifico']);
        }
    }

    public function test_structured_exemption_is_preserved_in_snapshot(): void
    {
        $exoneracion = [
            'tipoDocumento' => '01',
            'numeroDocumento' => 'EX-2026-001',
            'nombreInstitucion' => 'Ministerio de Hacienda',
            'fechaEmision' => '2026-01-15',
            'porcentajeExencion' => 100.0,
            'montoExoneracion' => 113.0,
        ];

        $item = $this->makeSaleItem([
            'tax_rate' => 13,
            'fiscal_snapshot' => [
                'source' => 'Hacienda v4.4 / Facturaencr OpenAPI',
                'source_version' => 'v4.4',
                'taxes' => [
                    [
                        'codigo' => '01',
                        'codigoTarifa' => '08',
                        'tarifa' => 13.0,
                        'exoneracion' => $exoneracion,
                    ],
                ],
            ],
        ]);

        $snapshot = $this->service->snapshotForSaleItem($item);
        $this->assertSame(array_keys($exoneracion), array_keys($snapshot['taxes'][0]['exoneracion']));
        $this->assertEquals($exoneracion, $snapshot['taxes'][0]['exoneracion']);

        $serialized = $this->service->serializeSnapshot($snapshot);
        $this->assertSame(array_keys($exoneracion), array_keys($serialized[0]['exoneracion']));
        $this->assertEquals($exoneracion, $serialized[0]['exoneracion']);
    }

    public function test_fiscal_snapshot_carries_source_and_source_version(): void
    {
        $profile = $this->service->resolveProfile('01', '08');
        $snapshot = $this->service->snapshotFromProfile($profile);

        $this->assertSame('Hacienda v4.4 / Facturaencr OpenAPI', $snapshot['source']);
        $this->assertSame('v4.4', $snapshot['source_version']);
        $this->assertNotEmpty($profile->catalogVersion->source);
        $this->assertNotEmpty($profile->catalogVersion->source_version);
    }

    public function test_fiscal_versioning_prefers_latest_active_catalog_version(): void
    {
        $original = $this->service->resolveProfile('01', '08');
        $this->assertSame('v4.4', $original->catalogVersion->source_version);

        $newVersion = FiscalCatalogVersion::create([
            'source' => 'Hacienda v4.5 / Facturaencr OpenAPI',
            'source_version' => 'v4.5',
            'status' => 'active',
            'imported_at' => now(),
        ]);

        $newProfile = FiscalProfile::create([
            'fiscal_catalog_version_id' => $newVersion->id,
            'tax_code' => '01',
            'tax_rate_code' => '08',
            'name' => 'IVA 13% v4.5',
            'treatment' => 'taxable',
            'rate' => 13,
            'is_active' => true,
        ]);

        $resolved = $this->service->resolveProfile('01', '08');
        $this->assertSame($newProfile->id, $resolved->id);

        $snapshot = $this->service->snapshotFromProfile($resolved);
        $this->assertSame('v4.5', $snapshot['source_version']);

        $newVersion->update(['status' => 'inactive']);

        $fallback = $this->service->resolveProfile('01', '08');
        $this->assertSame($original->id, $fallback->id);
        $this->assertSame('v4.4', $this->service->snapshotFromProfile($fallback)['source_version']);
    }

    public function test_profile_vigencia_is_versioned_per_catalog(): void
    {
        $version = FiscalCatalogVersion::create([
            'source' => 'Hacienda v4.6',
            'source_version' => 'v4.6',
            'status' => 'active',
            'valid_from' => '2026-01-01',
            'valid_until' => '2026-12-31',
            'imported_at' => now(),
        ]);

        $profile = FiscalProfile::create([
            'fiscal_catalog_version_id' => $version->id,
            'tax_code' => '01',
            'tax_rate_code' => '04',
            'name' => 'IVA 4% con vigencia',
            'treatment' => 'reduced_rate',
            'rate' => 4,
            'valid_from' => '2026-01-01',
            'valid_until' => '2026-12-31',
            'is_active' => true,
        ]);

        $this->assertSame('2026-01-01', $profile->fresh()->valid_from->toDateString());
        $this->assertSame('2026-12-31', $profile->fresh()->valid_until->toDateString());
        $this->assertSame('2026-01-01', $version->fresh()->valid_from->toDateString());
        $this->assertSame('2026-12-31', $version->fresh()->valid_until->toDateString());

        $seeded = FiscalProfile::query()->where('tax_code', '01')->where('tax_rate_code', '08')->first();
        $this->assertNull($seeded->valid_from);
        $this->assertNull($seeded->valid_until);
    }

    public function test_historical_sale_item_snapshots_are_immutable(): void
    {
        $historical = [
            'source' => 'Hacienda v4.4 / Facturaencr OpenAPI',
            'source_version' => 'v4.4',
            'taxes' => [
                ['codigo' => '01', 'codigoTarifa' => '03', 'tarifa' => 2.0],
            ],
        ];

        $item = $this->makeSaleItem([
            'tax_rate' => 2,
            'tax_code' => '01',
            'tax_rate_code' => '03',
            'fiscal_source' => 'Hacienda v4.4 / Facturaencr OpenAPI',
            'fiscal_source_version' => 'v4.4',
            'fiscal_snapshot' => $historical,
        ]);

        $newVersion = FiscalCatalogVersion::create([
            'source' => 'Hacienda v9.9',
            'source_version' => 'v9.9',
            'status' => 'active',
            'imported_at' => now(),
        ]);

        FiscalProfile::create([
            'fiscal_catalog_version_id' => $newVersion->id,
            'tax_code' => '01',
            'tax_rate_code' => '03',
            'name' => 'IVA 2% cambiado',
            'treatment' => 'reduced_rate',
            'rate' => 25,
            'is_active' => true,
        ]);

        FiscalProfile::query()
            ->where('tax_code', '01')
            ->where('tax_rate_code', '03')
            ->where('fiscal_catalog_version_id', '!=', $newVersion->id)
            ->update(['is_active' => false]);

        $first = $this->service->snapshotForSaleItem($item);
        $second = $this->service->snapshotForSaleItem($item);

        $this->assertEquals($historical, $first);
        $this->assertSame($first, $second);
        $this->assertSame(2.0, (float) $first['taxes'][0]['tarifa']);
        $this->assertSame('v4.4', $first['source_version']);
    }

    public function test_sale_item_resolves_from_explicit_fiscal_codes(): void
    {
        $item = $this->makeSaleItem([
            'tax_rate' => 13,
            'tax_code' => '01',
            'tax_rate_code' => '10',
        ]);

        $snapshot = $this->service->snapshotForSaleItem($item);

        $this->assertSame('exempt', $snapshot['taxes'][0]['treatment']);
        $this->assertSame('10', $snapshot['taxes'][0]['codigoTarifa']);
        $this->assertSame(0.0, (float) $snapshot['taxes'][0]['tarifa']);
    }

    private function makeSaleItem(array $attributes = []): SaleItem
    {
        return new SaleItem(array_merge([
            'sale_id' => 1,
            'product_code' => 'P01',
            'cabys_code' => '5060101000000',
            'description' => 'Producto',
            'unit_code' => 'un',
            'quantity' => 1,
            'unit_price' => 1000,
            'gross_total' => 1000,
            'subtotal' => 1000,
            'discount_total' => 0,
            'tax_rate' => 13,
            'tax_total' => 117,
            'total' => 1017,
            'unit_cost' => 500,
        ], $attributes));
    }
}
