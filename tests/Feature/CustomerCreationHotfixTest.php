<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerTaxpayerActivity;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * HOTFIX — Cliente nuevo y alta desde POS.
 *
 * Reproduce el 500 de /clientes/nuevo: la vista compartida de Clientes
 * leía $canManageCredit sin garantía de que la asignación previa existiera
 * en el ámbito real de la vista renderizada.
 */
class CustomerCreationHotfixTest extends TestCase
{
    use RefreshDatabase;

    public function test_clientes_nuevo_abre_sin_error_de_servidor(): void
    {
        [$company, $branch, $user] = $this->context();

        $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('clientes.create'));

        $this->assertSame(200, $response->getStatusCode(), 'GET /clientes/nuevo debe responder 200.');
    }

    public function test_clientes_editar_abre_sin_error_de_servidor(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customer($company);

        $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('clientes.edit', $customer));

        $this->assertSame(200, $response->getStatusCode(), 'GET /clientes/{id}/edit debe responder 200.');
    }

    public function test_guardar_cliente_desde_el_formulario_no_devuelve_500(): void
    {
        [$company, $branch, $user] = $this->context();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('clientes.store'), $this->payload())
            ->assertRedirect(route('clientes.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('customers', [
            'company_id' => $company->id,
            'identification' => '1-0987-0988',
        ]);
    }

    public function test_actividad_aplicada_desde_hacienda_se_persiste_y_se_ve_en_clientes(): void
    {
        [$company, $branch, $user] = $this->context();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('clientes.store'), $this->payload([
                'taxpayer_activities' => [
                    ['code' => '469000000', 'description' => 'Comercio al por mayor'],
                ],
            ]))
            ->assertRedirect(route('clientes.index'));

        $this->assertSame(['469000000'], CustomerTaxpayerActivity::query()->pluck('code')->all());

        $customer = Customer::query()->where('company_id', $company->id)->firstOrFail();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('clientes.show', $customer))
            ->assertOk()
            ->assertSee('469000000');
    }

    public function test_pos_cedula_valida_devuelve_nombre_oficial_por_la_misma_ruta(): void
    {
        Http::fake(['api.hacienda.go.cr/*' => Http::response([
            'nombre' => 'COMERCIAL EJEMPLO SOCIEDAD ANONIMA',
            'regimen' => ['descripcion' => 'Impuesto sobre las Utilidades'],
            'situacion' => ['estado' => 'Inscrito'],
            'actividades' => [['codigo' => '461010000', 'descripcion' => 'Venta al por menor']],
        ], 200)]);

        [$company, $branch, $user] = $this->context();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->getJson(route('clientes.contribuyente', ['tipo' => '02', 'identificacion' => '3-101-000000']))
            ->assertOk()
            ->assertJsonPath('status', 'found')
            ->assertJsonPath('name', 'COMERCIAL EJEMPLO SOCIEDAD ANONIMA')
            ->assertJsonPath('activities.0.code', '461010000');

        Http::assertSentCount(1);
    }

    public function test_identificacion_incompleta_no_consulta_hacienda(): void
    {
        Http::fake(['api.hacienda.go.cr/*' => Http::response([], 200)]);

        [$company, $branch, $user] = $this->context();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->getJson(route('clientes.contribuyente', ['tipo' => '01', 'identificacion' => '123']))
            ->assertStatus(422)
            ->assertJsonPath('status', 'invalid');

        Http::assertNothingSent();
    }

    public function test_fallo_de_hacienda_no_impide_crear_cliente_desde_pos(): void
    {
        Http::fake(fn () => throw new ConnectionException('Hacienda no responde'));

        [$company, $branch, $user] = $this->context();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->postJson(route('pos.customers.quick-store'), [
                'name' => 'Cliente Con Venta Sin Red',
                'customer_type' => 'individual',
                'identification_type' => '01',
                'identification' => '1-0098-7008',
            ])
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('customers', [
            'company_id' => $company->id,
            'identification' => '1-0098-7008',
        ]);
    }

    public function test_cliente_creado_en_pos_muestra_actividades_en_clientes(): void
    {
        [$company, $branch, $user] = $this->context();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->postJson(route('pos.customers.quick-store'), [
                'name' => 'Cliente POS Con Actividades',
                'customer_type' => 'company',
                'identification_type' => '02',
                'identification' => '3-101-000000',
                'taxpayer_activities' => [
                    ['code' => '461010000', 'description' => 'Venta al por menor'],
                    ['code' => '469000000', 'description' => 'Comercio al por mayor'],
                ],
            ])
            ->assertCreated();

        $customer = Customer::query()->where('company_id', $company->id)->firstOrFail();

        $this->assertSame(
            ['461010000', '469000000'],
            CustomerTaxpayerActivity::query()->where('customer_id', $customer->id)->orderBy('code')->pluck('code')->all()
        );

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('clientes.show', $customer))
            ->assertOk()
            ->assertSee('461010000')
            ->assertSee('469000000');
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

        foreach (['clientes.crear', 'clientes.ver', 'clientes.editar', 'pos.acceder'] as $name) {
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

    private function customer(Company $company): Customer
    {
        return Customer::create([
            'company_id' => $company->id,
            'customer_type' => 'individual',
            'identification_type' => '01',
            'identification' => '1-0456-7890',
            'name' => 'Cliente Existente',
            'is_active' => true,
        ]);
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
