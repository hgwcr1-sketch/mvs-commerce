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
 * Flujo HACIENDA completo, por capas y con la forma REAL de la respuesta.
 *
 * Cubre lo que los tests de servicios aislados no cubrían: el JSON que
 * devuelve /clientes/contribuyente, el payload que el formulario de Cliente
 * envía al guardar y el payload que el POS envía en su cliente rápido.
 *
 * La respuesta falsa replica la estructura REAL de api.hacienda.go.cr/fe/ae:
 * actividades[] = {estado, tipo, codigo, descripcion}, regimen = {codigo,
 * descripcion} y situacion = {moroso, omiso, estado, ...}.
 */
class HaciendaActivityFlowTest extends TestCase
{
    use RefreshDatabase;

    /** Respuesta real de Hacienda para un contribuyente con actividad. */
    private function haciendaConActividad(): array
    {
        return [
            'nombre' => 'COMERCIAL EJEMPLO SOCIEDAD ANONIMA',
            'tipoIdentificacion' => '02',
            'regimen' => ['codigo' => 1, 'descripcion' => 'Impuesto sobre las Utilidades'],
            'situacion' => [
                'moroso' => 'N', 'omiso' => 'N', 'estado' => 'Inscrito',
                'administracionTributaria' => 'N', 'mensaje' => '',
            ],
            'actividades' => [
                [
                    'estado' => 'A', 'tipo' => 'P', 'codigo' => '960113',
                    'descripcion' => 'Actividades de contratado de servicios',
                ],
                [
                    'estado' => 'A', 'tipo' => 'P', 'codigo' => '461010',
                    'descripcion' => 'Venta al por menor en comercios no especializados',
                ],
            ],
        ];
    }

    public function test_la_vista_real_expone_los_hooks_del_flujo_hacienda(): void
    {
        [$company, $branch, $user] = $this->context();

        $html = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('clientes.create'))
            ->assertOk()
            ->getContent();

        // El JS de identification.js se engancha a estos ids y a ninguno más.
        foreach (['id="identification_type"', 'id="identification"', 'id="identification_status"'] as $id) {
            $this->assertStringContainsString($id, $html, 'Falta el hook '.$id.' en /clientes/create');
        }

        // Contenedores donde el JS pinta propuesta, régimen, situación y actividades.
        foreach ([
            'id="taxpayer_proposal"',
            'id="taxpayer_meta"',
            'id="taxpayer_activities_box"',
            'id="taxpayer_activities_list"',
            'id="taxpayer_activities_inputs"',
            'id="taxpayer_proposal_seed"',
            'id="taxpayer_apply_all"',
            'id="taxpayer_clear"',
        ] as $id) {
            $this->assertStringContainsString($id, $html, 'Falta el contenedor '.$id.' en /clientes/create');
        }

        // El bundle de Vite debe servirse: sin él no arranca nada de lo anterior.
        $this->assertStringContainsString('/build/assets/', $html, 'La vista no está cargando el bundle de Vite');
    }

    public function test_endpoint_devuelve_el_json_real_consumido_por_cliente_y_pos(): void
    {
        Http::fake(['api.hacienda.go.cr/*' => Http::response($this->haciendaConActividad(), 200)]);
        [$company, $branch, $user] = $this->context();

        $respuesta = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->getJson(route('clientes.contribuyente', ['tipo' => '02', 'identificacion' => '3-101-000000']));

        $respuesta->assertOk()
            // Claves exactas que leen identificacion.js y el componente Alpine del POS.
            ->assertJsonStructure(['status', 'name', 'type', 'regime', 'situation', 'activities'])
            ->assertJsonPath('status', 'found')
            ->assertJsonPath('name', 'COMERCIAL EJEMPLO SOCIEDAD ANONIMA')
            ->assertJsonPath('regime', 'Impuesto sobre las Utilidades')
            ->assertJsonPath('situation', 'Inscrito')
            ->assertJsonPath('activities.0.code', '960113')
            ->assertJsonPath('activities.0.description', 'Actividades de contratado de servicios')
            ->assertJsonPath('activities.1.code', '461010');

        // El código y la descripción viajan con los nombres que consume la UI.
        $this->assertSame(
            ['code', 'description'],
            array_keys($respuesta->json('activities.0')),
            'La UI lee `code` y `description`: el endpoint no puede cambiar esos nombres.'
        );
    }

    public function test_cliente_nuevo_muestra_aplica_y_persiste_actividades(): void
    {
        Http::fake(['api.hacienda.go.cr/*' => Http::response($this->haciendaConActividad(), 200)]);
        [$company, $branch, $user] = $this->context();

        $lookup = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->getJson(route('clientes.contribuyente', ['tipo' => '02', 'identificacion' => '3-101-000000']));
        $lookup->assertOk();

        // El formulario marca las actividades y las envía con estos nombres exactos.
        $payload = ['code' => '960113', 'description' => 'Actividades de contratado de servicios'];

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('clientes.store'), $this->payload([
                'identification_type' => '02',
                'identification' => '3-101-000000',
                'name' => 'COMERCIAL EJEMPLO SOCIEDAD ANONIMA',
                'taxpayer_activities' => [$payload],
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $cliente = Customer::query()->where('identification', '3-101-000000')->firstOrFail();

        $this->assertDatabaseHas('customer_taxpayer_activities', [
            'company_id' => $company->id,
            'customer_id' => $cliente->id,
            'code' => '960113',
        ]);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('clientes.edit', $cliente))
            ->assertOk()
            ->assertSee('960113')
            ->assertSee('Actividades de contratado de servicios');
    }

    public function test_pos_muestra_aplica_nombre_oficial_y_persiste_actividades(): void
    {
        Http::fake(['api.hacienda.go.cr/*' => Http::response($this->haciendaConActividad(), 200)]);
        [$company, $branch, $user] = $this->context();

        $lookup = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->getJson(route('clientes.contribuyente', ['tipo' => '02', 'identificacion' => '3-101-000000']));
        $lookup->assertOk()->assertJsonPath('status', 'found');

        // Payload que construye storeQuickCustomer() desde quickCustomer.ident.applied.
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->postJson(route('pos.customers.quick-store'), [
                'name' => $lookup->json('name'),
                'customer_type' => 'company',
                'identification_type' => '02',
                'identification' => '3-101-000000',
                'taxpayer_activities' => [
                    ['code' => '960113', 'description' => 'Actividades de contratado de servicios'],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('customer.name', 'COMERCIAL EJEMPLO SOCIEDAD ANONIMA');

        $cliente = Customer::query()->where('identification', '3-101-000000')->firstOrFail();
        $this->assertDatabaseHas('customer_taxpayer_activities', [
            'company_id' => $company->id,
            'customer_id' => $cliente->id,
            'code' => '960113',
        ]);

        // Cliente creado en POS muestra su actividad en /clientes.
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('clientes.show', $cliente))
            ->assertOk()
            ->assertSee('960113');
    }

    public function test_contribuyente_no_inscrito_no_inventa_actividades(): void
    {
        // Forma REAL de Hacienda cuando no hay información: HTTP 200 con `status` textual.
        Http::fake(['api.hacienda.go.cr/*' => Http::response([
            'status' => 'Information no available on this system, please try again with new values',
        ], 200)]);
        [$company, $branch, $user] = $this->context();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->getJson(route('clientes.contribuyente', ['tipo' => '01', 'identificacion' => '1-0987-0988']))
            ->assertOk()
            ->assertJsonPath('status', 'not_found')
            ->assertJsonPath('activities', []);
    }

    public function test_fallo_de_hacienda_no_bloquea_el_registro(): void
    {
        Http::fake(fn () => throw new ConnectionException('Hacienda no responde'));
        [$company, $branch, $user] = $this->context();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->getJson(route('clientes.contribuyente', ['tipo' => '01', 'identificacion' => '1-0987-0988']))
            ->assertOk()
            ->assertJsonPath('status', 'unavailable')
            ->assertJsonPath('activities', []);

        // El cliente se sigue registrando sin actividades.
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('clientes.store'), $this->payload([
                'identification_type' => '01',
                'identification' => '1-0987-0988',
                'name' => 'Cliente Sin Hacienda',
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(0, CustomerTaxpayerActivity::query()->count());
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'customer_type' => 'company',
            'name' => 'Cliente',
            'credit_limit' => 0,
            'price_level' => 'normal',
        ], $overrides);
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
        $role = Role::create(['company_id' => $company->id, 'name' => 'Flujo '.uniqid(), 'is_active' => true]);

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

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
