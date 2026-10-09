<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\GeoCatalogImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BLOQUE 15B-2 fase 1 — pillo de formulario internacional en Clientes:
 * país + cascada territorial adaptativa, listas dependientes reales,
 * localización por configuración y compatibilidad con campos legacy.
 */
class GeoFormPilot15B2Test extends TestCase
{
    use RefreshDatabase;

    private function seedGeo(): void
    {
        $this->seed(\Database\Seeders\GeoCatalogSeeder::class);
    }

    private function import(string ...$countries): void
    {
        $importer = app(GeoCatalogImporter::class);

        foreach ($countries as $iso) {
            $importer->importCountry($iso);
        }
    }

    public function test_form_renders_adaptive_labels_country_options_and_utf8(): void
    {
        $this->seedGeo();
        $this->import('CR');

        [$company, $branch, $user] = $this->context();

        $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('clientes.create'));

        $response->assertOk()
            ->assertSee('País (catálogo internacional)')
            ->assertSee('Costa Rica')
            ->assertSee('Colombia')
            ->assertSee('Provincia')
            ->assertSee('Cantón')
            ->assertSee('Distrito')
            ->assertSee('San José')
            ->assertSee('Colón costarricense');
    }

    public function test_component_labels_adapt_to_country_and_avoid_missing_catalogs(): void
    {
        $this->seedGeo();
        $this->import('CR', 'CO');

        $this->blade('<x-geo.division-cascade country="CO" />')
            ->assertSee('Departamento')
            ->assertSee('Municipio')
            ->assertDontSee('Corregimiento')
            ->assertDontSee('Distrito');

        $this->blade('<x-geo.division-cascade country="VE" />')
            ->assertSee('aún no está cargado')
            ->assertDontSee('name="geo_division_id"');

        $this->blade('<x-geo.division-cascade country="CR" />')
            ->assertSee('Provincia')
            ->assertSee('Cantón')
            ->assertSee('Distrito');
    }

    public function test_dependent_endpoint_returns_real_hierarchies_with_utf8(): void
    {
        $this->seedGeo();
        $this->import('CR', 'CO');

        [$company, $branch, $user] = $this->context();

        $cr = $this->actingAs($user)->get(route('geo.divisiones') . '?pais=CR');
        $cr->assertOk();
        $this->assertTrue($cr->json('has_catalog'));
        $this->assertCount(7, $cr->json('divisions'));
        $this->assertSame('San José', $cr->json('divisions.0.name'));

        $provinceId = $cr->json('divisions.0.id');
        $children = $this->actingAs($user)->get(route('geo.divisiones') . "?pais=CR&padre={$provinceId}");
        $children->assertOk();
        $this->assertNotEmpty($children->json('divisions'));
        $this->assertSame('101', collect($children->json('divisions'))->first()['code']);
        $this->assertSame(2, (int) collect($children->json('divisions'))->first()['level']);

        $co = $this->actingAs($user)->get(route('geo.divisiones') . '?pais=CO');
        $co->assertOk();
        $this->assertCount(33, $co->json('divisions'));
        $antioquiaId = collect($co->json('divisions'))->firstWhere('code', '05')['id'];
        $medellin = $this->actingAs($user)->get(route('geo.divisiones') . "?pais=CO&padre={$antioquiaId}");
        $medellin->assertOk();
        $this->assertSame(
            'MEDELLÍN',
            collect($medellin->json('divisions'))->firstWhere('code', '05001')['name']
        );

        $ve = $this->actingAs($user)->get(route('geo.divisiones') . '?pais=VE');
        $ve->assertOk();
        $this->assertFalse($ve->json('has_catalog'));
        $this->assertSame([], $ve->json('divisions'));
        $this->assertSame([], $ve->json('levels'));

        $this->actingAs($user)->get(route('geo.divisiones') . '?pais=XX')->assertStatus(422);
    }

    public function test_dependent_endpoint_requires_authentication(): void
    {
        $this->seedGeo();

        $this->get(route('geo.divisiones') . '?pais=CR')->assertStatus(302);
    }

    public function test_store_rejects_nonexistent_and_cross_country_divisions(): void
    {
        $this->seedGeo();
        $this->import('CR', 'CO');

        [$company, $branch, $user] = $this->context();

        $coLeaf = \App\Models\GeoDivision::query()->forCountry('CO')->where('code', '05001')->sole();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('clientes.store'), $this->payload([
                'geo_country_iso' => 'CR', 'geo_division_id' => 999999,
            ]))->assertRedirect()->assertSessionHasErrors('geo_division_id');

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('clientes.store'), $this->payload([
                'geo_country_iso' => 'CR', 'geo_division_id' => $coLeaf->id,
            ]))->assertRedirect()->assertSessionHasErrors('geo_division_id');

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('clientes.store'), $this->payload([
                'geo_country_iso' => 'ZZ', 'geo_division_id' => null,
            ]))->assertRedirect()->assertSessionHasErrors('geo_country_iso');

        $this->assertSame(0, Customer::query()->count());
    }

    public function test_store_saves_international_fields_and_legacy_form_still_works(): void
    {
        $this->seedGeo();
        $this->import('CR');

        [$company, $branch, $user] = $this->context();
        $leaf = \App\Models\GeoDivision::query()->forCountry('CR')->where('code', '10101')->sole();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('clientes.store'), $this->payload([
                'identification' => 'GEO-001',
                'geo_country_iso' => 'CR', 'geo_division_id' => $leaf->id,
            ]))->assertRedirect(route('clientes.index'))->assertSessionHasNoErrors();

        $international = Customer::query()->where('identification', 'GEO-001')->firstOrFail();
        $this->assertSame('CR', $international->geo_country_iso);
        $this->assertSame($leaf->id, $international->geo_division_id);
        $this->assertNull($international->province_id);

        // Legacy: cliente sin campos internacionales sigue funcionando.
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('clientes.store'), $this->payload(['identification' => 'LEGACY-001']))
            ->assertRedirect(route('clientes.index'))->assertSessionHasNoErrors();

        $legacy = Customer::query()->where('identification', 'LEGACY-001')->firstOrFail();
        $this->assertNull($legacy->geo_country_iso);
        $this->assertNull($legacy->geo_division_id);

        // Update acepta el campo internacional con la misma validación.
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->put(route('clientes.update', $legacy), $this->payload([
                'identification' => 'LEGACY-001',
                'geo_country_iso' => 'CR', 'geo_division_id' => $leaf->id,
            ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($leaf->id, $legacy->fresh()->geo_division_id);
    }

    public function test_component_blade_includes_responsive_markup_and_js_hooks(): void
    {
        $this->seedGeo();
        $this->import('CR');
        view()->share('errors', new \Illuminate\Support\ViewErrorBag());

        $this->blade('<x-geo.division-cascade country="CR" />')
            ->assertSee('id="geo-cascade"', false)
            ->assertSee('md:grid-cols-2', false)
            ->assertSee('min-height:44px', false)
            ->assertSee('data-level', false)
            ->assertSee('/geo/divisiones', false)
            ->assertSee('geo_country_iso', false);

        $this->blade('<x-geo.country-select />')
            ->assertSee('min-height:44px', false)
            ->assertSee('geo-localization-hint', false)
            ->assertSee('geo_country_iso', false);
    }

    private function context(): array
    {
        $company = Company::create([
            'trade_name' => 'Empresa '.uniqid(), 'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica', 'default_phone_country_code' => '+506', 'is_active' => true,
        ]);
        $branch = Branch::create([
            'company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.uniqid(), 'is_active' => true,
        ]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Clientes '.uniqid(), 'is_active' => true]);
        foreach (['clientes.crear', 'clientes.ver', 'clientes.editar'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], [
                'label' => $name, 'module' => 'Clientes', 'is_active' => true,
            ]);
            $role->permissions()->attach($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return [$company, $branch, $user];
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'customer_type' => 'individual', 'identification_type' => '01',
            'identification' => uniqid('ID-'), 'name' => 'María Núñez',
            'phone_country_code' => '+506', 'phone' => '88881111',
            'credit_limit' => 0, 'credit_days' => 0, 'price_level' => 'normal', 'is_active' => '1',
        ], $overrides);
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
