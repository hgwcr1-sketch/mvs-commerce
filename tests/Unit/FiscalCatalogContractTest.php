<?php

namespace Tests\Unit;

use App\Contracts\Fiscal\FiscalCabysCatalogInterface;
use App\Contracts\Fiscal\FiscalProviderInterface;
use App\Contracts\Fiscal\FiscalTaxpayerLookupInterface;
use App\DTOs\Fiscal\FiscalCabysEntry;
use App\DTOs\Fiscal\FiscalCabysSearchResult;
use App\DTOs\Fiscal\FiscalDocumentStatus;
use App\DTOs\Fiscal\FiscalEmissionRequest;
use App\DTOs\Fiscal\FiscalEmissionResult;
use App\DTOs\Fiscal\FiscalTaxpayerInfo;
use App\Models\Cabys;
use App\Models\ElectronicDocument;
use App\Services\Facturaencr\FacturaencrProvider;
use App\Services\Fiscal\FiscalManager;
use App\Services\Fiscal\LocalCabysCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

class FiscalCatalogContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_local_cabys_catalog_searches_bccr_catalog_with_neutral_entries(): void
    {
        Cabys::create([
            'code' => '5060101000000',
            'description' => 'Venta al por menor de mercancías en general',
            'tax_rate' => 13,
            'is_active' => true,
        ]);
        Cabys::create([
            'code' => '5061234567890',
            'description' => 'Productos de limpieza',
            'tax_rate' => 13,
            'is_active' => true,
        ]);
        Cabys::create([
            'code' => '9999999999990',
            'description' => 'Inactivo',
            'tax_rate' => 13,
            'is_active' => false,
        ]);

        $catalog = new LocalCabysCatalog();

        $result = $catalog->search('5060101');

        $this->assertInstanceOf(FiscalCabysSearchResult::class, $result);
        $this->assertTrue($result->found);
        $this->assertCount(1, $result->entries);
        $entry = $result->entries[0];
        $this->assertInstanceOf(FiscalCabysEntry::class, $entry);
        $this->assertSame('5060101000000', $entry->code);
        $this->assertStringContainsString('mercancías', $entry->description);
        $this->assertSame('13.00', $entry->taxRate);
        $this->assertTrue($entry->active);

        $byDescription = $catalog->search('limpieza');
        $this->assertTrue($byDescription->found);
        $this->assertSame('5061234567890', $byDescription->entries[0]->code);

        $inactive = $catalog->search('9999999999990');
        $this->assertFalse($inactive->found);

        $missing = $catalog->search('zzz-sin-coincidencias');
        $this->assertFalse($missing->found);
        $this->assertSame([], $missing->entries);
    }

    public function test_local_cabys_catalog_validates_codes_locally(): void
    {
        Cabys::create([
            'code' => '5060101000000',
            'description' => 'Venta al por menor',
            'tax_rate' => 13,
            'is_active' => true,
        ]);
        Cabys::create([
            'code' => '5061111111111',
            'description' => 'Inactivo',
            'tax_rate' => 13,
            'is_active' => false,
        ]);

        $catalog = new LocalCabysCatalog();

        $entry = $catalog->validate('5060101000000');
        $this->assertInstanceOf(FiscalCabysEntry::class, $entry);
        $this->assertSame('5060101000000', $entry->code);

        $this->assertNull($catalog->validate('0000000000000'));
        $this->assertNull($catalog->validate('5061111111111'));
    }

    public function test_manager_cabys_uses_local_catalog_without_remote_calls(): void
    {
        Cabys::create([
            'code' => '5060101000000',
            'description' => 'Venta al por menor',
            'tax_rate' => 13,
            'is_active' => true,
        ]);

        $this->assertSame(LocalCabysCatalog::class, config('fiscal.cabys_catalog'));

        $manager = new FiscalManager();
        $this->assertInstanceOf(FiscalCabysCatalogInterface::class, $manager->cabysCatalog());
        $this->assertInstanceOf(LocalCabysCatalog::class, $manager->cabysCatalog());

        $result = $manager->searchCabys('5060101');
        $this->assertTrue($result->found);
        $this->assertInstanceOf(FiscalCabysEntry::class, $manager->validateCabys('5060101000000'));
        Http::assertNothingSent();
    }

    public function test_facturaencr_provider_translates_taxpayer_response_to_neutral_dto(): void
    {
        Http::fake([
            'api.facturaencr.com/v2/efactura/contribuyentes/3101000000/regimen' => Http::response([
                'encontrado' => true,
                'contribuyente' => true,
                'regimen' => [
                    'codigo' => 2,
                    'clave' => 'simplificado',
                    'descripcion' => 'Régimen simplificado',
                    'simplificado' => true,
                    'trasladaIva' => false,
                ],
                'actividadesEconomicas' => [
                    ['codigo' => '4711.2', 'descripcion' => 'Ventas al por menor de alimentos'],
                ],
            ], 200),
        ]);

        $info = (new FacturaencrProvider())->lookup('3101000000');

        $this->assertInstanceOf(FiscalTaxpayerInfo::class, $info);
        $this->assertTrue($info->found);
        $this->assertTrue($info->taxpayer);
        $this->assertSame('2', $info->regimeCode);
        $this->assertSame('simplificado', $info->regimeKey);
        $this->assertSame('Régimen simplificado', $info->regimeDescription);
        $this->assertTrue($info->regimeSimplified);
        $this->assertFalse($info->regimeTransfersTax);
        $this->assertNull($info->error);
        $this->assertCount(1, $info->activities);
        $this->assertSame('4711.2', $info->activities[0]['code']);
        $this->assertStringContainsString('alimentos', $info->activities[0]['description']);
        $this->assertArrayNotHasKey('codigo', $info->activities[0]);
        $this->assertArrayNotHasKey('descripcion', $info->activities[0]);
    }

    public function test_taxpayer_lookup_failure_returns_neutral_error(): void
    {
        Http::fake([
            'api.facturaencr.com/v2/efactura/contribuyentes/3101000000/regimen' => Http::response([
                'message' => 'Internal error',
            ], 500),
        ]);

        $info = (new FacturaencrProvider())->lookup('3101000000');

        $this->assertFalse($info->found);
        $this->assertNull($info->regimeKey);
        $this->assertNotNull($info->error);
        $this->assertSame('HTTP_500', $info->error->code);
        $this->assertSame('server', $info->error->category);
        $this->assertTrue($info->error->retryable);
    }

    public function test_manager_lookup_taxpayer_routes_through_configured_provider(): void
    {
        Http::fake([
            'api.facturaencr.com/v2/efactura/contribuyentes/3101000000/regimen' => Http::response([
                'encontrado' => true,
                'contribuyente' => true,
                'regimen' => ['codigo' => 1, 'clave' => 'comun', 'descripcion' => 'Régimen comun', 'simplificado' => false, 'trasladaIva' => true],
                'actividadesEconomicas' => [],
            ], 200),
        ]);

        $info = (new FiscalManager())->lookupTaxpayer('3101000000');

        $this->assertTrue($info->found);
        $this->assertSame('comun', $info->regimeKey);
        $this->assertTrue($info->regimeTransfersTax);
    }

    public function test_manager_lookup_taxpayer_rejects_provider_without_capability(): void
    {
        Config::set('fiscal.provider', 'sin-capacidad');
        Config::set('fiscal.providers.sin-capacidad', CatalogFakeProvider::class);

        try {
            (new FiscalManager())->lookupTaxpayer('3101000000');
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('contribuyentes', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_alternate_taxpayer_lookup_swaps_without_consumer_changes(): void
    {
        Config::set('fiscal.provider', 'contribuyente-fake');
        Config::set('fiscal.providers.contribuyente-fake', TaxpayerCapableFakeProvider::class);

        $manager = new FiscalManager();
        $provider = $manager->provider();

        $this->assertNotInstanceOf(FacturaencrProvider::class, $provider);
        $this->assertInstanceOf(FiscalTaxpayerLookupInterface::class, $provider);

        $info = $manager->lookupTaxpayer('3101000000');

        $this->assertTrue($info->found);
        $this->assertSame('simplificado', $info->regimeKey);
        $this->assertSame('9999.9', $info->activities[0]['code']);
        Http::assertNothingSent();
    }
}

class CatalogFakeProvider implements FiscalProviderInterface
{
    public function providerCode(): string
    {
        return 'sin-capacidad';
    }

    public function emit(FiscalEmissionRequest $request): FiscalEmissionResult
    {
        return new FiscalEmissionResult(state: FiscalEmissionResult::STATE_QUEUED);
    }

    public function fetchStatus(ElectronicDocument $document): FiscalDocumentStatus
    {
        return new FiscalDocumentStatus(state: FiscalEmissionResult::STATE_QUEUED);
    }
}

class TaxpayerCapableFakeProvider implements FiscalProviderInterface, FiscalTaxpayerLookupInterface
{
    public function providerCode(): string
    {
        return 'contribuyente-fake';
    }

    public function emit(FiscalEmissionRequest $request): FiscalEmissionResult
    {
        return new FiscalEmissionResult(state: FiscalEmissionResult::STATE_QUEUED);
    }

    public function fetchStatus(ElectronicDocument $document): FiscalDocumentStatus
    {
        return new FiscalDocumentStatus(state: FiscalEmissionResult::STATE_QUEUED);
    }

    public function lookup(string $identification): FiscalTaxpayerInfo
    {
        return new FiscalTaxpayerInfo(
            found: true,
            taxpayer: true,
            regimeKey: 'simplificado',
            activities: [['code' => '9999.9', 'description' => 'Actividad demo']],
        );
    }
}
