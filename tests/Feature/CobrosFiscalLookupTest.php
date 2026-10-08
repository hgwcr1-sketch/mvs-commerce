<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyLicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * MF03 portado de mvs-fiscal: Hacienda => nombre en Nueva solicitud.
 *
 * Reglas certificadas:
 * - cédula física, jurídica, DIMEX y NITE se resuelven con la fuente oficial;
 * - Hacienda not-found => fallback local company-scoped;
 * - Hacienda error/timeout => fallback/manual SIN bloquear el formulario;
 * - nunca escribe Customer; aislamiento por empresa; Buró sigue primero.
 */
class CobrosFiscalLookupTest extends TestCase
{
    use RefreshDatabase;

    /** MF03 activo directo (igual que mvs-fiscal, sin switch): solo se fakea la red. */
    private function enableFiscal(?callable $response = null): void
    {
        Http::fake([
            'api.hacienda.go.cr/*' => $response ?? Http::response($this->haciendaPayload('MARIA SOLIS RAMIREZ', '01'), 200),
        ]);
    }

    /** @return array<string, mixed> */
    private function haciendaPayload(string $name, string $typeCode = '01'): array
    {
        return [
            'nombre' => $name,
            'tipoIdentificacion' => $typeCode,
            'regimen' => ['codigo' => 1, 'descripcion' => 'Régimen General'],
            'situacion' => ['estado' => 'Activo'],
            'actividades' => ['641900'],
        ];
    }

    private function lookupQuery(User $user, Company $company, string $type, string $id)
    {
        return $this->actingAs($user)->withSession(['active_company_id' => $company->id])
            ->getJson(route('cobros.customers.identification-name', [
                'identification_type' => $type,
                'identification' => $id,
            ]));
    }

    public function test_cedula_fisica_resolved_by_mf03_without_writing_customer(): void
    {
        $this->enableFiscal();
        [$c, $u] = $this->context();
        $before = Customer::count();

        $this->lookupQuery($u, $c, 'cedula', '123456789')
            ->assertOk()
            ->assertExactJson(['supported' => true, 'found' => true, 'name' => 'MARIA SOLIS RAMIREZ']);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.hacienda.go.cr/fe/ae'));
        $this->assertSame($before, Customer::count(), 'MF03 es solo propuesta: nunca escribe Customer.');
    }

    public function test_juridica_resolved_by_mf03(): void
    {
        $this->enableFiscal(fn () => Http::response($this->haciendaPayload('COMERCIAL LA SABANA S.A.', '02'), 200));
        [$c, $u] = $this->context();

        $this->lookupQuery($u, $c, 'juridica', '3101234567')
            ->assertOk()
            ->assertExactJson(['supported' => true, 'found' => true, 'name' => 'COMERCIAL LA SABANA S.A.']);
    }

    public function test_dimex_resolved_by_mf03(): void
    {
        $this->enableFiscal(fn () => Http::response($this->haciendaPayload('JUAN PEREZ MORA', '03'), 200));
        [$c, $u] = $this->context();

        $this->lookupQuery($u, $c, 'dimex', '1020304050')
            ->assertOk()
            ->assertExactJson(['supported' => true, 'found' => true, 'name' => 'JUAN PEREZ MORA']);
    }

    public function test_nite_as_nit_resolved_by_mf03(): void
    {
        $this->enableFiscal(fn () => Http::response($this->haciendaPayload('INDUSTRIAS GUANACASTE LIMITADA', '04'), 200));
        [$c, $u] = $this->context();

        $this->lookupQuery($u, $c, 'nit', '5678901234')
            ->assertOk()
            ->assertExactJson(['supported' => true, 'found' => true, 'name' => 'INDUSTRIAS GUANACASTE LIMITADA']);
    }

    public function test_hacienda_not_found_falls_back_to_local_customer(): void
    {
        $this->enableFiscal(fn () => Http::response(['error' => 'no existe'], 404));
        [$c, $u] = $this->context();
        Customer::create(['company_id' => $c->id, 'customer_type' => 'individual', 'identification' => '123456789', 'name' => 'Local Cliente CRC', 'is_active' => true]);

        $this->lookupQuery($u, $c, 'cedula', '123456789')
            ->assertOk()
            ->assertExactJson(['supported' => true, 'found' => true, 'name' => 'Local Cliente CRC']);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.hacienda.go.cr/fe/ae'));
    }

    public function test_hacienda_error_or_timeout_never_blocks_the_form(): void
    {
        $this->enableFiscal(fn () => throw new ConnectionException('cURL error 28: timeout'));
        [$c, $u] = $this->context();

        // Red caída: responde OK y permite manual; jamás 500 ni excepción.
        $this->lookupQuery($u, $c, 'cedula', '123456789')
            ->assertOk()
            ->assertExactJson(['supported' => true, 'found' => false, 'name' => null]);

        $this->assertSame(0, Customer::count());
    }

    public function test_hacienda_rate_limit_falls_back_without_blocking(): void
    {
        $this->enableFiscal(fn () => Http::response(['message' => 'limit'], 429, ['Retry-After' => '30']));
        [$c, $u] = $this->context();

        $this->lookupQuery($u, $c, 'dimex', '1020304050')
            ->assertOk()
            ->assertExactJson(['supported' => true, 'found' => false, 'name' => null]);
    }

    public function test_local_fallback_keeps_tenant_isolation(): void
    {
        $this->enableFiscal(fn () => Http::response(['error' => 'no existe'], 404));
        [$c, $u] = $this->context();
        [$other] = $this->context();
        Customer::create(['company_id' => $other->id, 'customer_type' => 'individual', 'identification' => '123456789', 'name' => 'Cliente Ajeno', 'is_active' => true]);

        $this->lookupQuery($u, $c, 'cedula', '123456789')
            ->assertOk()
            ->assertExactJson(['supported' => true, 'found' => false, 'name' => null]);
    }

    public function test_buro_still_runs_before_fiscal_lookup_in_form(): void
    {
        Http::fake(); // red fakeada: Buró encadena ANTES del lookup de nombre

        [$c, $u, $route] = $this->contextWithRoute();

        $html = $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.credit-requests.create'))
            ->assertOk()
            ->getContent();

        $bureau = strpos($html, "fetch(bureau + '?identification_type=");
        $name = strpos($html, "fetch(endpoint + '?identification_type=");

        $this->assertNotFalse($bureau, 'El formulario debe encadenar Buró.');
        $this->assertNotFalse($name, 'El formulario debe encadenar el lookup de nombre.');
        $this->assertLessThan($name, $bureau, 'Buró debe ejecutarse ANTES que la fuente fiscal/lookup.');
    }

    // ------------------------------------------------------------------
    // Helpers

    private function context(array $permissions = ['cobros.acceder', 'cobros.cobrar']): array
    {
        $company = Company::create(['trade_name' => 'Fiscal '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        \App\Models\Branch::create(['company_id' => $company->id, 'name' => 'Sucursal', 'code' => 'F'.random_int(1, 9), 'is_active' => true]);
        $user = $this->userIn($company, $permissions);
        app(CompanyLicenseService::class)->updateModules($company, User::factory()->create(['is_platform_admin' => true, 'is_active' => true]), ['cobros']);

        return [$company->fresh(), $user];
    }

    /** Contexto con ruta activa para abrir el formulario de Nueva solicitud. */
    private function contextWithRoute(): array
    {
        [$c, $u] = $this->context();
        $route = \App\Models\CobrosRoute::create(['company_id' => $c->id, 'name' => 'Ruta Fiscal', 'active' => true]);
        $route->users()->attach($u->id, ['active' => true]);
        app(\App\Services\Cobros\CollectorLicenseService::class)->renewForRoute($route, $c, User::factory()->create(['is_platform_admin' => true, 'is_active' => true]), now()->addMonth());

        return [$c, $u, $route];
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
