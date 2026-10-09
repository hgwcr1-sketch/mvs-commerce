<?php

namespace Tests\Feature;

use App\Models\Canton;
use App\Models\Company;
use App\Models\Country;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\District;
use App\Models\GeoCatalogLevel;
use App\Models\GeoDivision;
use App\Models\Province;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * BLOQUE 15A — arquitectura geográfica internacional configurable (10 países),
 * niveles territoriales variables, catálogo versionable y compatibilidad con
 * datos CR existentes. Sin importación masiva de divisiones.
 */
class GeoCatalogArchitecture15ATest extends TestCase
{
    use RefreshDatabase;

    private function seedGeo(): void
    {
        $this->seed(\Database\Seeders\GeoCatalogSeeder::class);
    }

    private function division(Country $country, int $level, string $code, string $name, ?int $parentId = null, string $version = 'v1'): GeoDivision
    {
        return GeoDivision::create([
            'country_id' => $country->id,
            'parent_id' => $parentId,
            'level' => $level,
            'code' => $code,
            'name' => $name,
            'catalog_version' => $version,
            'is_active' => true,
        ]);
    }

    public function test_supported_countries_and_levels_are_configured(): void
    {
        $this->seedGeo();

        $configured = config('geography.countries');
        $this->assertSame(
            ['CR', 'NI', 'PA', 'GT', 'SV', 'HN', 'CO', 'MX', 'DO', 'VE'],
            array_keys($configured)
        );

        $this->assertDatabaseCount('countries', 10);
        $this->assertSame(1, Country::query()->where('is_default', true)->count());
        $this->assertSame('CR', Country::query()->where('is_default', true)->sole()->iso2);
        $this->assertSame(10, Country::query()->where('is_active', true)->count());

        foreach (array_keys($configured) as $iso2) {
            $country = Country::query()->where('iso2', $iso2)->sole();
            $levels = GeoCatalogLevel::query()->forCountry($iso2)->active()->orderBy('level')->get();
            $this->assertGreaterThanOrEqual(1, $levels->count(), "País {$iso2} sin niveles.");
            $this->assertSame(1, (int) $levels->first()->level);
            foreach ($levels as $index => $level) {
                $expected = $index === 0 ? null : $index;
                $this->assertSame($expected, $level->parent_level === null ? null : (int) $level->parent_level);
                $this->assertSame(
                    $configured[$iso2]['levels'][$index]['name'],
                    $level->name
                );
            }
        }
    }

    public function test_territorial_levels_are_variable_by_country(): void
    {
        $this->seedGeo();

        $cr = GeoCatalogLevel::query()->forCountry('CR')->active()->count();
        $ni = GeoCatalogLevel::query()->forCountry('NI')->active()->count();
        $ve = GeoCatalogLevel::query()->forCountry('VE')->active()->count();

        $this->assertSame(3, $cr);
        $this->assertSame(2, $ni);
        $this->assertSame(3, $ve);
        $this->assertNotSame($cr, $ni);

        $this->assertSame(
            ['Provincia', 'Cantón', 'Distrito'],
            GeoCatalogLevel::query()->forCountry('CR')->active()->orderBy('level')->pluck('name')->all()
        );
        $this->assertSame(
            ['Estado', 'Municipio', 'Parroquia'],
            GeoCatalogLevel::query()->forCountry('VE')->active()->orderBy('level')->pluck('name')->all()
        );
    }

    public function test_hierarchies_support_variable_depth_without_forcing_three_levels(): void
    {
        $this->seedGeo();

        $cr = Country::query()->where('iso2', 'CR')->sole();
        $pa = Country::query()->where('iso2', 'PA')->sole();

        $provincia = $this->division($cr, 1, '1', 'San José');
        $canton = $this->division($cr, 2, '101', 'San José', $provincia->id);
        $distrito = $this->division($cr, 3, '10101', 'Carmen', $canton->id);

        $this->assertSame($provincia->id, $distrito->parent->parent->id);
        $this->assertSame([$canton->id], $provincia->children->pluck('id')->all());

        // País de 2 niveles funciona sin tercer nivel.
        $provinciaPa = $this->division($pa, 1, '1', 'Panamá');
        $distritoPa = $this->division($pa, 2, '1-1', 'San Miguelito', $provinciaPa->id);
        $this->assertSame(0, GeoDivision::query()->forCountry('PA')->where('level', 3)->count());
        $this->assertSame($provinciaPa->id, $distritoPa->parent->id);

        // Nivel no configurado para el país se rechaza (NI solo tiene 2 niveles).
        try {
            $this->division(Country::query()->where('iso2', 'NI')->sole(), 3, '030101', 'Inválido');
            $this->fail('Debió rechazar un nivel no configurado.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('level', $e->errors());
        }

        // Padre de nivel no inmediato superior se rechaza.
        try {
            $this->division($cr, 3, '999', 'Huérfano', $provincia->id);
            $this->fail('Debió rechazar padre de nivel incorrecto.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('parent_id', $e->errors());
        }

        // Raíz fuera de nivel 1 se rechaza.
        try {
            $this->division($cr, 2, '888', 'Sin padre');
            $this->fail('Debió rechazar raíz que no es nivel 1.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('level', $e->errors());
        }
    }

    public function test_division_catalog_is_versionable_by_official_code(): void
    {
        $this->seedGeo();

        $cr = Country::query()->where('iso2', 'CR')->sole();

        $first = $this->division($cr, 1, '1', 'San José', null, 'cat-2026.1');
        $this->assertSame('cat-2026.1', $first->catalog_version);

        $updated = GeoDivision::query()->updateOrCreate(
            ['country_id' => $cr->id, 'code' => '1'],
            ['name' => 'San José (actualizado)', 'catalog_version' => 'cat-2026.2', 'level' => 1]
        );

        $this->assertSame($first->id, $updated->id);
        $this->assertSame(1, GeoDivision::query()->forCountry('CR')->count());
        $this->assertSame('cat-2026.2', GeoDivision::query()->where('code', '1')->sole()->catalog_version);

        $updated->forceFill(['is_active' => false])->save();
        $this->assertSame(0, GeoDivision::query()->forCountry('CR')->active()->count());
        $this->assertSame(1, GeoDivision::query()->forCountry('CR')->count());
    }

    public function test_geo_catalog_is_isolated_by_country(): void
    {
        $this->seedGeo();

        $cr = Country::query()->where('iso2', 'CR')->sole();
        $ni = Country::query()->where('iso2', 'NI')->sole();

        $crDivision = $this->division($cr, 1, '101', 'Código compartido CR');
        $niDivision = $this->division($ni, 1, '101', 'Código compartido NI');

        $this->assertSame($crDivision->code, $niDivision->code);
        $this->assertNotSame($crDivision->id, $niDivision->id);
        $this->assertSame(
            [$crDivision->id],
            GeoDivision::query()->forCountry('CR')->active()->pluck('id')->all()
        );
        $this->assertSame(
            [$niDivision->id],
            GeoDivision::query()->forCountry('NI')->active()->pluck('id')->all()
        );

        $this->assertSame(3, GeoCatalogLevel::query()->forCountry('CR')->active()->count());
        $this->assertSame(2, GeoCatalogLevel::query()->forCountry('NI')->active()->count());
        $this->assertSame(0, GeoCatalogLevel::query()->forCountry('CR')->where('name', 'Departamento')->count());

        // El padre debe compartir país.
        try {
            $this->division($ni, 2, '0201', 'Municipio', $crDivision->id);
            $this->fail('Debió rechazar padre de otro país.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('parent_id', $e->errors());
        }
    }

    public function test_existing_cr_addresses_clients_and_routes_data_are_preserved(): void
    {
        // Datos previos "legados" que deben sobrevivir al nuevo catálogo.
        $legacyCr = Country::query()->create([
            'name' => 'Costa Rica', 'iso2' => 'CR', 'iso3' => 'CRI',
            'phone_code' => '+506', 'currency' => 'CRC', 'currency_symbol' => '₡',
            'is_default' => true, 'is_active' => true,
        ]);
        $province = Province::create(['country_id' => $legacyCr->id, 'name' => 'San José', 'code' => '1', 'is_active' => true]);
        $canton = Canton::create(['province_id' => $province->id, 'name' => 'San José', 'code' => '101', 'is_active' => true]);
        $district = District::create(['canton_id' => $canton->id, 'name' => 'Carmen', 'code' => '10101', 'is_active' => true]);
        $company = Company::create(['trade_name' => 'Compañía Legada', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $customer = Customer::withoutEvents(fn () => Customer::create([
            'company_id' => $company->id, 'name' => 'Cliente Legado',
            'customer_type' => 'individual', 'is_active' => true,
        ]));
        $address = CustomerAddress::create([
            'customer_id' => $customer->id, 'name' => 'Casa',
            'country_id' => $legacyCr->id, 'province_id' => $province->id,
            'canton_id' => $canton->id, 'district_id' => $district->id,
            'address' => 'Avenida 1, barrio céntrico', 'is_primary' => true,
        ]);

        $this->seedGeo();

        // Nuevos catálogos presentes y CR sigue siendo el único predeterminado.
        $this->assertDatabaseCount('countries', 10);
        $this->assertTrue(Country::query()->where('iso2', 'CR')->sole()->is_default);
        $this->assertSame($legacyCr->id, Country::query()->where('iso2', 'CR')->sole()->id);
        $this->assertSame(1, Country::query()->where('is_default', true)->count());

        // Datos y FK existentes intactos.
        $this->assertDatabaseHas('provinces', ['id' => $province->id, 'name' => 'San José']);
        $this->assertDatabaseHas('cantons', ['id' => $canton->id, 'province_id' => $province->id]);
        $this->assertDatabaseHas('districts', ['id' => $district->id, 'canton_id' => $canton->id]);
        $this->assertDatabaseHas('customer_addresses', ['id' => $address->id, 'district_id' => $district->id]);
        $this->assertSame($district->id, CustomerAddress::query()->sole()->district->id);
        $this->assertSame($address->id, Customer::query()->sole()->addresses()->sole()->id);

        // El nuevo catálogo no altera ni duplica divisiones legadas.
        $this->assertSame(0, GeoDivision::query()->count());
        $this->assertSame(1, Province::query()->count());
    }
}
