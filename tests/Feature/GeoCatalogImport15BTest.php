<?php

namespace Tests\Feature;

use App\Models\Canton;
use App\Models\Company;
use App\Models\Country;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\District;
use App\Models\GeoCatalogSource;
use App\Models\GeoDivision;
use App\Models\Province;
use App\Services\GeoCatalogImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * BLOQUE 15B-1 — importación verificable de catálogos geográficos:
 * CR (repositorio), CO (DANE DIVIPOLA). Idempotencia, UTF-8, duplicados,
 * aislamiento por país, registro de fuente y compatibilidad con datos existentes.
 */
class GeoCatalogImport15BTest extends TestCase
{
    use RefreshDatabase;

    private function importer(): GeoCatalogImporter
    {
        return app(GeoCatalogImporter::class);
    }

    private function seedGeo(): void
    {
        $this->seed(\Database\Seeders\GeoCatalogSeeder::class);
    }

    public function test_cr_catalog_imports_complete_hierarchy_from_repository_sources(): void
    {
        $this->seedGeo();

        $summary = $this->importer()->importCountry('CR');

        $this->assertSame([1 => 7, 2 => 84, 3 => 492], $summary);
        $this->assertSame(583, GeoDivision::query()->forCountry('CR')->count());
        $this->assertSame([], $this->importer()->verifyIntegrity(Country::query()->where('iso2', 'CR')->sole()));

        $provincia = GeoDivision::query()->forCountry('CR')->where('code', '1')->sole();
        $this->assertSame(1, (int) $provincia->level);
        $this->assertSame('San José', $provincia->name);
        $this->assertTrue(mb_check_encoding($provincia->name, 'UTF-8'));

        $distrito = GeoDivision::query()->forCountry('CR')->where('code', '10101')->sole();
        $this->assertSame(3, (int) $distrito->level);
        $this->assertSame('101', $distrito->parent->code);
        $this->assertSame('1', $distrito->parent->parent->code);

        $this->assertSame(7, GeoDivision::query()->forCountry('CR')->roots()->where('level', 1)->count());
    }

    public function test_colombia_catalog_imports_from_dane_divipola_source(): void
    {
        $this->seedGeo();

        $summary = $this->importer()->importCountry('CO');

        $this->assertSame([1 => 33, 2 => 1122], $summary);
        $this->assertSame(1155, GeoDivision::query()->forCountry('CO')->count());
        $this->assertSame([], $this->importer()->verifyIntegrity(Country::query()->where('iso2', 'CO')->sole()));

        $antioquia = GeoDivision::query()->forCountry('CO')->where('code', '05')->sole();
        $this->assertSame('ANTIOQUIA', $antioquia->name);

        $medellin = GeoDivision::query()->forCountry('CO')->where('code', '05001')->sole();
        $this->assertSame(2, (int) $medellin->level);
        $this->assertSame($antioquia->id, $medellin->parent_id);
        $this->assertTrue(str_contains($medellin->name, 'MEDELLÍN'));
        $this->assertTrue(mb_check_encoding($medellin->name, 'UTF-8'));
    }

    public function test_import_is_idempotent_on_reload_and_preserves_existing_addresses(): void
    {
        $this->seedGeo();

        // Datos legados existentes que deben sobrevivir la importación.
        $legacyCr = Country::query()->where('iso2', 'CR')->sole();
        $province = Province::create(['country_id' => $legacyCr->id, 'name' => 'San José', 'code' => '1', 'is_active' => true]);
        $canton = Canton::create(['province_id' => $province->id, 'name' => 'San José', 'code' => '101', 'is_active' => true]);
        $district = District::create(['canton_id' => $canton->id, 'name' => 'Carmen', 'code' => '10101', 'is_active' => true]);
        $company = Company::create(['trade_name' => 'Compañía 15B', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $customer = Customer::withoutEvents(fn () => Customer::create([
            'company_id' => $company->id, 'name' => 'Cliente 15B',
            'customer_type' => 'individual', 'is_active' => true,
        ]));
        $address = CustomerAddress::create([
            'customer_id' => $customer->id, 'name' => 'Casa',
            'country_id' => $legacyCr->id, 'province_id' => $province->id,
            'canton_id' => $canton->id, 'district_id' => $district->id,
            'address' => 'Calle 1', 'is_primary' => true,
        ]);

        $this->importer()->importCountry('CR');
        $firstIds = GeoDivision::query()->forCountry('CR')->orderBy('id')->pluck('id', 'code')->all();
        $createdAt = GeoDivision::query()->forCountry('CR')->where('code', '1')->sole()->created_at;

        $second = $this->importer()->importCountry('CR');

        $this->assertSame([1 => 7, 2 => 84, 3 => 492], $second);
        $this->assertSame($firstIds, GeoDivision::query()->forCountry('CR')->orderBy('id')->pluck('id', 'code')->all());
        $this->assertTrue($createdAt->equalTo(GeoDivision::query()->forCountry('CR')->where('code', '1')->sole()->created_at));
        $this->assertSame(1, GeoCatalogSource::query()->where('country_id', $legacyCr->id)->count());

        // Tablas y direcciones legadas intactas (sin truncate ni reemplazo).
        $this->assertSame(1, Province::query()->count());
        $this->assertSame($district->id, CustomerAddress::query()->sole()->district->id);
    }

    public function test_duplicate_codes_in_source_are_rejected_without_writing(): void
    {
        $this->seedGeo();

        $country = Country::query()->where('iso2', 'CR')->sole();
        $path = tempnam(sys_get_temp_dir(), 'geo15b');

        file_put_contents($path, "codigo,nombre\n99,Valle\n99,Duplicado\n");

        try {
            $this->importer()->importFile($country, [
                'file' => $path,
                'level' => 1,
                'delimiter' => ',',
                'columns' => ['code' => 0, 'name' => 1],
            ], ['source' => 'prueba', 'version' => 'v-test']);
            $this->fail('Debió rechazar códigos duplicados.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('geo', $e->errors());
        } finally {
            @unlink($path);
        }

        $this->assertSame(0, GeoDivision::query()->count());
        $this->assertSame(0, GeoCatalogSource::query()->count());
    }

    public function test_import_is_isolated_by_country_and_registers_sources(): void
    {
        $this->seedGeo();

        $this->importer()->importCountry('CR');
        $this->importer()->importCountry('CO');

        $this->assertSame(583, GeoDivision::query()->forCountry('CR')->count());
        $this->assertSame(1155, GeoDivision::query()->forCountry('CO')->count());
        $this->assertSame(0, GeoDivision::query()->forCountry('NI')->count());
        $this->assertSame(0, GeoDivision::query()->forCountry('VE')->count());
        $this->assertSame(0, GeoDivision::query()->forCountry('PA')->count());
        $this->assertSame(1738, GeoDivision::query()->count());

        $this->assertSame(2, GeoCatalogSource::query()->count());

        $crSource = GeoCatalogSource::query()
            ->where('country_id', Country::query()->where('iso2', 'CR')->sole()->id)->sole();
        $this->assertSame('sin-version-declarada', $crSource->version);
        $this->assertSame(583, (int) $crSource->division_count);
        $this->assertNotNull($crSource->imported_at);

        $coSource = GeoCatalogSource::query()
            ->where('country_id', Country::query()->where('iso2', 'CO')->sole()->id)->sole();
        $this->assertSame('DANE vía datos.gov.co — DIVIPOLA', $coSource->source);
        $this->assertSame('corte 30-diciembre-2024', $coSource->version);
        $this->assertSame('2024-12-30', $coSource->source_date?->format('Y-m-d'));
        $this->assertSame(1155, (int) $coSource->division_count);
        $this->assertNotEmpty($coSource->source_url);
    }

    public function test_missing_source_blocks_import_instead_of_inventing_data(): void
    {
        $this->seedGeo();

        try {
            $this->importer()->importCountry('VE');
            $this->fail('Debió detenerse ante una fuente inexistente.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('VE', $e->getMessage());
        }

        $this->assertSame(0, GeoDivision::query()->count());
        $this->assertSame(0, GeoCatalogSource::query()->count());
    }
}
