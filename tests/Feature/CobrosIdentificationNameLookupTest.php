<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Country;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyLicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Autocompletar Nombre desde identificación (integración común, solo lectura).
 *
 * Reglas certificadas:
 * - encontrada => devuelve el nombre (no guarda nada);
 * - no encontrada / país sin fuente => responde y permite manual;
 * - DIMEX resuelve por MF03 (Hacienda); Pasaporte sin fuente => supported=false;
 * - aislamiento por empresa + permiso CobrOS + throttle.
 */
class CobrosIdentificationNameLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // MF03 activo por defecto (igual que mvs-fiscal): la red se fakea a 404
        // para certificar el fallback local y nunca tocar api.hacienda.go.cr.
        Http::fake(['api.hacienda.go.cr/*' => Http::response([], 404)]);
    }

    public function test_found_identification_returns_name_without_writing(): void
    {
        [$c, $u] = $this->context();
        Customer::create(['company_id' => $c->id, 'customer_type' => 'individual', 'identification' => '123456789', 'name' => 'María Solís Ramírez', 'is_active' => true]);
        $before = Customer::count();

        $res = $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->getJson(route('cobros.customers.identification-name', [
                'identification_type' => 'cedula', 'identification' => '123456789',
            ]));

        $res->assertOk()->assertExactJson(['supported' => true, 'found' => true, 'name' => 'María Solís Ramírez']);
        $this->assertSame($before, Customer::count(), 'La consulta no guarda ni duplica clientes.');
    }

    public function test_not_found_or_bad_format_returns_no_name_and_allows_manual(): void
    {
        [$c, $u] = $this->context();

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->getJson(route('cobros.customers.identification-name', [
                'identification_type' => 'cedula', 'identification' => '999999999',
            ]))
            ->assertOk()->assertExactJson(['supported' => true, 'found' => false, 'name' => null]);

        // Formato inválido para CR (no es cédula de 9 dígitos): no consulta, manual.
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->getJson(route('cobros.customers.identification-name', [
                'identification_type' => 'cedula', 'identification' => '12A',
            ]))
            ->assertOk()->assertExactJson(['supported' => true, 'found' => false, 'name' => null]);

        $this->assertSame(0, Customer::count());
    }

    public function test_unsupported_types_and_countries_do_not_query(): void
    {
        [$c, $u] = $this->context();
        Customer::create(['company_id' => $c->id, 'customer_type' => 'individual', 'identification' => '123456789', 'name' => 'Existe Pero No Consulta', 'is_active' => true]);

        // DIMEX: fuente MF03 disponible (falso 404 arriba) => supported=true,
        // sin nombre y sin fallback local. Pasaporte: sin fuente => manual.
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->getJson(route('cobros.customers.identification-name', [
                'identification_type' => 'dimex', 'identification' => '123456789',
            ]))
            ->assertOk()->assertExactJson(['supported' => true, 'found' => false, 'name' => null]);

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->getJson(route('cobros.customers.identification-name', [
                'identification_type' => 'pasaporte', 'identification' => '123456789',
            ]))
            ->assertOk()->assertExactJson(['supported' => false, 'found' => false, 'name' => null]);

        // Cuenta sin país con proveedor (moneda distinta de CRC, sin iso2) => sin consulta.
        $co = Company::create(['trade_name' => 'Sin Proveedor '.uniqid(), 'currency' => 'USD', 'timezone' => 'America/Bogota', 'is_active' => true]);
        \App\Models\Branch::create(['company_id' => $co->id, 'name' => 'S', 'code' => 'X'.random_int(1, 9), 'is_active' => true]);
        $u2 = $this->userIn($co, ['cobros.acceder', 'cobros.cobrar']);
        Customer::create(['company_id' => $co->id, 'customer_type' => 'individual', 'identification' => '123456789', 'name' => 'Cliente Colombia', 'is_active' => true]);

        $this->actingAs($u2)->withSession(['active_company_id' => $co->id])
            ->getJson(route('cobros.customers.identification-name', [
                'identification_type' => 'cedula', 'identification' => '123456789',
            ]))
            ->assertOk()->assertExactJson(['supported' => false, 'found' => false, 'name' => null]);
    }

    public function test_lookup_is_company_isolated_and_permission_protected(): void
    {
        [$c, $u] = $this->context();
        [$other] = $this->context();
        Customer::create(['company_id' => $other->id, 'customer_type' => 'individual', 'identification' => '123456789', 'name' => 'Cliente Ajeno', 'is_active' => true]);

        // Misma identificación pero en OTRA empresa: no se filtra (aislamiento).
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->getJson(route('cobros.customers.identification-name', [
                'identification_type' => 'cedula', 'identification' => '123456789',
            ]))
            ->assertOk()->assertExactJson(['supported' => true, 'found' => false, 'name' => null]);

        // Sin permiso CobrOS => 403.
        $noPerm = $this->userIn($c, []);
        $this->actingAs($noPerm)->withSession(['active_company_id' => $c->id])
            ->getJson(route('cobros.customers.identification-name', [
                'identification_type' => 'cedula', 'identification' => '123456789',
            ]))->assertForbidden();
    }

    public function test_guest_is_redirected_to_cobros_login(): void
    {
        $this->get(route('cobros.customers.identification-name', [
            'identification_type' => 'cedula', 'identification' => '123456789',
        ]))->assertRedirect(route('cobros.login'));
    }

    public function test_5b_form_renders_lookup_wiring_and_status(): void
    {
        [$c, $u] = $this->context();
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.start'))->assertRedirect();

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.initial-portfolio.client'))
            ->assertOk()
            ->assertSee('identificacion-nombre')
            ->assertSee('Buscando…')
            ->assertSee('id="name-lookup-status"', false);
    }

    // ------------------------------------------------------------------
    // Helpers

    private function context(array $permissions = ['cobros.acceder', 'cobros.cobrar']): array
    {
        $company = Company::create(['trade_name' => 'CR '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        \App\Models\Branch::create(['company_id' => $company->id, 'name' => 'Sucursal', 'code' => 'L'.random_int(1, 9), 'is_active' => true]);
        $user = $this->userIn($company, $permissions);
        app(CompanyLicenseService::class)->updateModules($company, User::factory()->create(['is_platform_admin' => true, 'is_active' => true]), ['cobros']);

        return [$company->fresh(), $user];
    }

    private function userIn(Company $company, array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Perfil '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]));
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($company->branches()->first()->id);

        return $user;
    }
}
