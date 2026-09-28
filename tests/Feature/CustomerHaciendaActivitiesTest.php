<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerTaxpayerActivity;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CustomerTaxpayerActivityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A — Hacienda + actividades económicas del cliente.
 *
 * La consulta es UNA sola petición a la ruta existente
 * /clientes/contribuyente (sin segundo lookup ni segunda ruta) y la
 * persistencia respeta company_id.
 */
class CustomerHaciendaActivitiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_lookup_returns_regime_situation_and_activities_with_one_request(): void
    {
        Http::fake([
            'api.hacienda.go.cr/*' => Http::response([
                'nombre' => 'COMERCIAL EJEMPLO SOCIEDAD ANONIMA',
                'tipoIdentificacion' => '02',
                'regimen' => ['codigo' => 2, 'descripcion' => 'Impuesto sobre las Utilidades'],
                'situacion' => ['estado' => 'Inscrito', 'moroso' => false],
                'actividades' => [
                    ['codigo' => '461010000', 'descripcion' => 'Venta al por menor de mercancías en tiendas'],
                    ['codigo' => '469000000', 'descripcion' => 'Comercio al por mayor no especializado'],
                ],
            ], 200),
        ]);

        [$company, $branch, $user] = $this->context();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->getJson(route('clientes.contribuyente', ['tipo' => '02', 'identificacion' => '3-101-000000']))
            ->assertOk()
            ->assertJsonPath('status', 'found')
            ->assertJsonPath('name', 'COMERCIAL EJEMPLO SOCIEDAD ANONIMA')
            ->assertJsonPath('regime', 'Impuesto sobre las Utilidades')
            ->assertJsonPath('situation', 'Inscrito')
            ->assertJsonPath('activities.0.code', '461010000')
            ->assertJsonPath('activities.1.code', '469000000');

        Http::assertSentCount(1);
    }

    public function test_lookup_never_writes_customer_activities_by_itself(): void
    {
        Http::fake([
            'api.hacienda.go.cr/*' => Http::response([
                'nombre' => 'CLIENTE SIN APLICAR',
                'actividades' => [['codigo' => '461010000', 'descripcion' => 'Venta al por menor']],
            ], 200),
        ]);

        [$company, $branch, $user] = $this->context();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->getJson(route('clientes.contribuyente', ['tipo' => '02', 'identificacion' => '3-101-000000']))
            ->assertOk()
            ->assertJsonPath('status', 'found');

        $this->assertSame(0, CustomerTaxpayerActivity::query()->count());
    }

    public function test_customer_store_persists_only_applied_activities_with_company_isolation(): void
    {
        [$company, $branch, $user] = $this->context();

        $other = Company::create([
            'trade_name' => 'Otra '.uniqid(), 'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica', 'default_phone_country_code' => '+506', 'is_active' => true,
        ]);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('clientes.store'), $this->payload([
                'identification' => '1-0987-0988',
                'taxpayer_activities' => [
                    ['code' => '461010000', 'description' => 'Venta al por menor'],
                    ['code' => '469000000', 'description' => 'Comercio al por mayor'],
                ],
            ]))
            ->assertRedirect(route('clientes.index'))
            ->assertSessionHasNoErrors();

        $customer = Customer::forCompany($company->id)->firstOrFail();

        $activities = CustomerTaxpayerActivity::query()->where('customer_id', $customer->id)->get();

        $this->assertCount(2, $activities);
        $this->assertSame($company->id, (int) $activities->first()->company_id);
        $this->assertSame(
            ['461010000', '469000000'],
            $activities->pluck('code')->sort()->values()->all()
        );
        $this->assertFalse((bool) $activities->first()->is_primary, 'La fuente no marca actividad principal.');

        // Aislamiento: otra empresa no ve ni hereda esas filas.
        $this->assertSame(
            0,
            CustomerTaxpayerActivity::query()->where('company_id', $other->id)->count()
        );
        $this->assertSame(
            2,
            CustomerTaxpayerActivity::query()->forCompany($company->id)->count()
        );
    }

    public function test_customer_update_replaces_or_preserves_activities_only_when_sent(): void
    {
        [$company, $branch, $user] = $this->context();

        $customer = Customer::create([
            'company_id' => $company->id,
            'customer_type' => 'individual',
            'identification_type' => '01',
            'identification' => '100010001',
            'name' => 'Cliente Activo',
            'credit_limit' => 0,
            'credit_days' => 0,
            'price_level' => 'normal',
            'is_active' => true,
        ]);

        app(CustomerTaxpayerActivityService::class)->syncForCustomer($customer, [
            ['code' => '461010000', 'description' => 'Venta al por menor'],
        ]);

        // Sin `taxpayer_activities` en el payload: no se toca lo persistido.
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->put(route('clientes.update', $customer), $this->payload([
                'identification' => '100010001',
                'name' => 'Cliente Activo Editado',
            ]))
            ->assertRedirect(route('clientes.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ['461010000'],
            CustomerTaxpayerActivity::query()->where('customer_id', $customer->id)->pluck('code')->all()
        );

        // Con `taxpayer_activities`: reemplazo completo del set.
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->put(route('clientes.update', $customer), $this->payload([
                'identification' => '100010001',
                'taxpayer_activities' => [
                    ['code' => '469000000', 'description' => 'Comercio al por mayor'],
                ],
            ]))
            ->assertRedirect(route('clientes.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ['469000000'],
            CustomerTaxpayerActivity::query()->where('customer_id', $customer->id)->pluck('code')->all()
        );
    }

    public function test_activity_codes_must_be_numeric_official_codes(): void
    {
        [$company, $branch, $user] = $this->context();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('clientes.store'), $this->payload([
                'identification' => '1-0987-0999',
                'taxpayer_activities' => [
                    ['code' => 'no-oficial', 'description' => 'Intento de código libre'],
                ],
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors('taxpayer_activities.0.code');

        $this->assertSame(0, CustomerTaxpayerActivity::query()->count());
    }

    private function context(bool $withPermissions = true): array
    {
        $company = Company::create([
            'trade_name' => 'Empresa '.uniqid(), 'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica', 'default_phone_country_code' => '+506', 'is_active' => true,
        ]);
        $branch = Branch::create([
            'company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.uniqid(), 'is_active' => true,
        ]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Clientes '.uniqid(), 'is_active' => true]);

        if ($withPermissions) {
            foreach (['clientes.crear', 'clientes.ver', 'clientes.editar', 'pos.acceder'] as $name) {
                $permission = Permission::firstOrCreate(['name' => $name], [
                    'label' => $name, 'module' => 'Clientes', 'is_active' => true,
                ]);
                $role->permissions()->attach($permission);
            }
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
            'identification' => '1-0987-0988', 'name' => 'María Núñez',
            'phone_country_code' => '+506', 'phone' => '88881111',
            'credit_limit' => 0, 'credit_days' => 0, 'price_level' => 'normal', 'is_active' => '1',
        ], $overrides);
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
