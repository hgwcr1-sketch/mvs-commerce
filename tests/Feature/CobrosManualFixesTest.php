<?php

namespace Tests\Feature;

use App\Models\AccountReceivable;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CobrosAssignment;
use App\Models\CobrosBureauEvent;
use App\Models\CobrosCreditRequest;
use App\Models\CobrosRoute;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\Cobros\CollectorLicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 9B — correcciones de prueba manual: Mi cartera solo deuda activa,
 * Mis clientes, buscadores, cliente existente/documentos, navegación,
 * copy de autorización y campana del dueño.
 * Ejecutar POR SEPARADO.
 */
class CobrosManualFixesTest extends TestCase
{
    use RefreshDatabase;

    private ?CobrosRoute $activeRoute = null;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response([], 404)]);
    }

    private function context(array $permissions = ['cobros.acceder', 'cobros.cobrar']): array
    {
        $company = Company::create(['trade_name' => 'Fix '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'C'.random_int(1, 99), 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(\App\Services\CompanyLicenseService::class)->updateModules($company, $admin, ['cobros', 'notifications']);

        // Ruta licenciada: el alta/nueva solicitud exige ruta operable.
        $route = CobrosRoute::create(['company_id' => $company->id, 'name' => 'Ruta '.uniqid(), 'active' => true]);
        $route->users()->attach($user->id, ['active' => true]);
        app(CollectorLicenseService::class)->renewForRoute($route, $company, $admin, now()->addMonth());
        $this->activeRoute = $route;

        return [$company, $branch, $user, $admin];
    }

    private function customer(Company $c, array $data = []): Customer
    {
        return Customer::create($data + [
            'company_id' => $c->id,
            'customer_type' => 'individual',
            'name' => 'Cliente '.uniqid(),
            'is_active' => true,
        ]);
    }

    private function assign(Company $c, Customer $customer, User $collector): void
    {
        CobrosAssignment::create([
            'company_id' => $c->id,
            'customer_id' => $customer->id,
            'collector_user_id' => $collector->id,
            'assigned_by' => $collector->id,
            'assigned_at' => now(),
        ]);
    }

    private function account(Company $c, Branch $b, User $u, Customer $customer, string $balance, string $status = 'pending'): AccountReceivable
    {
        $sale = Sale::create(['company_id' => $c->id, 'branch_id' => $b->id, 'user_id' => $u->id, 'customer_id' => $customer->id, 'sale_number' => 'CXC-'.strtoupper(substr(uniqid(), -8)), 'status' => 'completed', 'completed_at' => now()]);

        return AccountReceivable::create(['company_id' => $c->id, 'branch_id' => $b->id, 'customer_id' => $customer->id, 'sale_id' => $sale->id, 'issued_at' => today()->subMonth(), 'due_date' => today($c->timezone)->addDays(7), 'original_amount' => $balance, 'balance_due' => $balance, 'status' => $status, 'currency_code' => 'CRC']);
    }

    private function asCollector(Company $c, User $u): static
    {
        $session = ['active_company_id' => $c->id];
        if ($this->activeRoute !== null) {
            $session['active_cobros_route_id'] = $this->activeRoute->id;
        }

        return $this->actingAs($u)->withSession($session);
    }

    public function test_paid_off_customer_leaves_portfolio_but_is_kept_in_customers(): void
    {
        [$c, $b, $u] = $this->context();
        $paid = $this->customer($c, ['name' => 'Cliente de pruebas 1', 'identification' => '101010101', 'phone' => '88888888']);
        $this->assign($c, $paid, $u);
        $this->account($c, $b, $u, $paid, '25000.0000', 'paid');   // pagado todo
        $this->account($c, $b, $u, $paid, '0.0000', 'pending');     // saldo real 0

        $still = $this->customer($c, ['name' => 'Cliente con saldo']);
        $this->assign($c, $still, $u);
        $this->account($c, $b, $u, $still, '1500.0000', 'pending');

        // 1) saldo total 0 → fuera de Mi cartera (lógica, no CSS).
        $this->asCollector($c, $u);
        $this->get(route('cobros.collector.portfolio'))
            ->assertOk()
            ->assertDontSee('Cliente de pruebas 1')
            ->assertSee('Cliente con saldo');

        // 2) el cliente NO se elimina ni pierde su assignment/historial.
        $this->assertDatabaseHas('customers', ['id' => $paid->id]);
        $this->assertDatabaseHas('cobros_assignments', ['customer_id' => $paid->id, 'collector_user_id' => $u->id]);
        $this->assertSame(2, AccountReceivable::where('customer_id', $paid->id)->count());

        // 4) aparece en MIS CLIENTES como "Sin crédito activo".
        $this->get(route('cobros.collector.my-customers'))
            ->assertOk()
            ->assertSee('Cliente de pruebas 1')
            ->assertSee('Sin crédito activo')
            ->assertSee('NUEVA SOLICITUD');
    }

    public function test_other_open_cxc_keeps_customer_in_portfolio(): void
    {
        [$c, $b, $u] = $this->context();
        $customer = $this->customer($c, ['name' => 'Cliente mixto']);
        $this->assign($c, $customer, $u);
        $this->account($c, $b, $u, $customer, '900.0000', 'paid');
        $this->account($c, $b, $u, $customer, '450.5000', 'pending'); // otra CxC > 0

        $this->asCollector($c, $u);
        $this->get(route('cobros.collector.portfolio'))
            ->assertOk()
            ->assertSee('Cliente mixto')
            ->assertViewHas('totalBalance', '450.5000');
    }

    public function test_new_request_from_my_customers_reuses_existing_customer(): void
    {
        [$c, $b, $u] = $this->context();
        $customer = $this->customer($c, ['name' => 'Reutilizado Cliente', 'identification' => '303030303', 'phone' => '77777777']);
        $before = Customer::where('company_id', $c->id)->count();

        $view = $this->asCollector($c, $u)
            ->get(route('cobros.collector.credit-requests.create', ['customer' => $customer->id]))
            ->assertOk();

        $preselect = $view->viewData('preselect');
        $this->assertNotNull($preselect);
        $this->assertSame($customer->id, (int) $preselect->id);
        $this->assertSame($before, Customer::where('company_id', $c->id)->count(), 'no duplica Customer');
        $this->assertStringContainsString('existente', $view->getContent());
    }

    public function test_portfolio_search_finds_by_name_identification_and_phone(): void
    {
        [$c, $b, $u] = $this->context();
        $byName = $this->customer($c, ['name' => 'Maria Fernanda Solis Ramirez']);
        $this->assign($c, $byName, $u);
        $this->account($c, $b, $u, $byName, '100.0000');

        $byId = $this->customer($c, ['name' => 'Por Cedula', 'identification' => '505050505']);
        $this->assign($c, $byId, $u);
        $this->account($c, $b, $u, $byId, '100.0000');

        $byPhone = $this->customer($c, ['name' => 'Por Telefono', 'phone' => '8888-8888']);
        $this->assign($c, $byPhone, $u);
        $this->account($c, $b, $u, $byPhone, '100.0000');

        $this->asCollector($c, $u);
        // 6) nombre parcial + múltiples palabras.
        $this->get(route('cobros.collector.portfolio', ['q' => 'fernanda solis']))
            ->assertOk()->assertViewHas('customers', fn ($rows) => $rows->getCollection()->modelKeys() === [$byName->id]);
        // 7) identificación.
        $this->get(route('cobros.collector.portfolio', ['q' => '505050505']))
            ->assertOk()->assertViewHas('customers', fn ($rows) => $rows->getCollection()->modelKeys() === [$byId->id]);
        // 8) teléfono con guion.
        $this->get(route('cobros.collector.portfolio', ['q' => '8888-8888']))
            ->assertOk()->assertViewHas('customers', fn ($rows) => $rows->getCollection()->modelKeys() === [$byPhone->id]);
        // acentos/mayúsculas.
        $accent = $this->customer($c, ['name' => 'José Muñoz']);
        $this->assign($c, $accent, $u);
        $this->account($c, $b, $u, $accent, '50.0000');
        $this->get(route('cobros.collector.portfolio', ['q' => 'JOSE MUNOZ']))
            ->assertOk()->assertSee('José Muñoz');
    }

    public function test_existing_customer_typeahead_searches_name_id_and_phone(): void
    {
        // Aislamiento: la empresa ajena se CREA antes de sembrar la sesión
        // (el trait de tenant exige que los models coincidan con la empresa activa).
        [$other] = $this->context();
        $foreign = $this->customer($other, ['name' => 'Ajena Quiros', 'identification' => '909090909']);

        [$c, $b, $u] = $this->context();
        $customer = $this->customer($c, ['name' => 'Ana Maria Quiros', 'identification' => '606060606', 'phone' => '77771234']);

        $this->asCollector($c, $u);
        foreach (['quiros', '606060606', '7777-1234'] as $term) {
            $this->getJson(route('cobros.collector.credit-requests.customers', ['q' => $term]))
                ->assertOk()
                ->assertJsonFragment(['id' => $customer->id]);
        }

        // 10) aislamiento: otro tenant jamás aparece.
        $this->getJson(route('cobros.collector.credit-requests.customers', ['q' => 'quiros']))
            ->assertOk()
            ->assertJsonMissing(['id' => $foreign->id]);
    }

    public function test_existing_customer_with_registered_docs_is_not_forced_to_upload_again(): void
    {
        [$c, $b, $u] = $this->context();
        $customer = $this->customer($c, ['name' => 'Con Documentos', 'identification' => '707070707']);

        // Expediente legítimo de ESTA empresa con ambas caras registradas.
        $route = \App\Models\CobrosRoute::create(['company_id' => $c->id, 'name' => 'Ruta '.uniqid(), 'active' => true]);
        CobrosCreditRequest::create([
            'company_id' => $c->id,
            'cobros_route_id' => $route->id,
            'collector_user_id' => $u->id,
            'customer_id' => $customer->id,
            'bureau_identity_id' => null,
            'applicant_type' => 'existente',
            'identification_type' => 'cedula',
            'identification' => '707070707',
            'name' => $customer->name,
            'amount_requested' => '1000.0000',
            'status' => CobrosCreditRequest::STATUS_PENDING,
            'id_front_path' => 'cobros/credit_requests/1/1/frente.jpg',
            'id_back_path' => 'cobros/credit_requests/1/1/reverso.jpg',
            'submitted_at' => now(),
        ]);

        // 11) no exige resubir documentos.
        $this->asCollector($c, $u);
        $this->post(route('cobros.collector.credit-requests.review'), [
            'applicant_type' => 'existente',
            'customer_id' => $customer->id,
            'identification' => $customer->identification,
            'name' => $customer->name,
            'amount_requested' => '1000.0000',
        ])->assertOk();

        // 12) sin expediente → sí se solicita lo que falta.
        $empty = $this->customer($c, ['name' => 'Sin Documentos', 'identification' => '808080808']);
        $this->post(route('cobros.collector.credit-requests.review'), [
            'applicant_type' => 'existente',
            'customer_id' => $empty->id,
            'identification' => $empty->identification,
            'name' => $empty->name,
            'amount_requested' => '1000.0000',
        ])->assertSessionHasErrors('id_front');
    }

    public function test_authorization_copy_hides_internal_terms_and_keeps_audit(): void
    {
        [$c, $b, $u] = $this->context();

        // 9C.3: la consulta exige policy collector_authorized.
        \App\Models\CobrosCollectorAuthorityPolicy::set(
            (int) $c->id,
            (int) $u->id,
            \App\Models\CobrosCollectorAuthorityPolicy::COLLECTOR_AUTHORIZED
        );

        // 13) UI: sin "Buró" ni "versión v1".
        $html = $this->asCollector($c, $u)
            ->get(route('cobros.collector.credit-requests.create'))
            ->assertOk()
            ->assertSee('Autorización para consultar información')
            ->assertSee('El cliente autoriza la consulta de su información.')
            ->assertDontSee('Buró')
            ->assertDontSee('versión v1')
            ->getContent();

        // 14) propósito/versión internos siguen auditados.
        $service = app(\App\Services\Cobros\CobrosBureauService::class);
        $service->resolveIdentity($c, 'cedula', '111222333', $u, null, 'solicitud_credito');
        $this->get(route('cobros.collector.credit-requests.bureau-check', [
            'identification_type' => 'cedula',
            'identification' => '111222333',
            'autorizacion' => 'acepto',
        ]))->assertOk();

        $access = CobrosBureauEvent::where('event_type', CobrosBureauEvent::TYPE_IDENTITY_ACCESSED)->first();
        $this->assertNotNull($access);
        $this->assertStringContainsString('evaluacion_solicitud_credito', (string) $access->purpose);
        $this->assertStringContainsString('autorizacion_v1', (string) $access->purpose);
        $this->assertSame($u->id, (int) $access->actor_user_id);
        $this->assertNotNull($access->occurred_at ?? $access->created_at);
    }

    public function test_owner_attending_credit_marks_only_his_own_recipient_read(): void
    {
        [$c, $b, $u] = $this->context(['cobros.acceder', 'cobros.portal_dueno', 'notificaciones.cobros']);
        $owner = $u;
        // Los destinatarios se resuelven AL DISPARCHAR: crearlos antes.
        $otherOwner = $this->member($c, ['cobros.acceder', 'cobros.portal_dueno', 'notificaciones.cobros']);

        $dispatcher = app(\App\Services\Notifications\AlertDispatcher::class);
        $alert = $dispatcher->dispatch(
            \App\Services\Notifications\AlertTypeRegistry::TYPE_COBROS_CREDIT_REQUEST,
            [
                'company_id' => $c->id,
                'actor_id' => User::factory()->create(['is_active' => true])->id,
                'entity_type' => 'cobros_credit_request',
                'entity_id' => 4242,
                'link' => route('cobros.collector.credit-requests.index'),
                'dedupe_key' => 'prueba-'.uniqid(),
                'metadata' => ['bell_text' => 'Nuevo préstamo realizado · Cliente X'],
            ]
        );

        $ownerRow = $alert->recipients()->where('user_id', $owner->id)->first();
        $otherRow = $alert->recipients()->where('user_id', $otherOwner->id)->first();
        $this->assertNotNull($ownerRow);
        $this->assertNotNull($otherRow);

        // 15/16) abrir la notificación SOLO marca ese recipient y baja el badge.
        $this->actingAs($owner)->withSession(['active_company_id' => $c->id]);
        $this->get(route('cobros.notifications.open', $ownerRow->id))->assertRedirect();
        $this->assertNotNull($ownerRow->fresh()->read_at);
        $this->assertNull($otherRow->fresh()->read_at, 'no marca notificaciones de otros usuarios');

        $this->actingAs($owner)->withSession(['active_company_id' => $c->id]);
        $this->get(route('cobros.owner.solicitudes.index'))
            ->assertOk()->assertDontSee('data-bell-badge', false);
        $this->actingAs($otherOwner)->withSession(['active_company_id' => $c->id]);
        $this->get(route('cobros.owner.solicitudes.index'))
            ->assertOk()->assertSee('data-bell-badge', false);

        // Texto informativo del nuevo modelo (supervisión, no aprobación).
        $this->assertSame('Nuevo préstamo realizado · Cliente X', $alert->metadata['bell_text']);
    }

    public function test_portfolio_navigation_has_no_redundant_new_customer_entry(): void
    {
        [$c, $b, $u] = $this->context();
        $customer = $this->customer($c, ['name' => 'Navegacion Cliente']);
        $this->assign($c, $customer, $u);
        $this->account($c, $b, $u, $customer, '100.0000');

        $html = $this->asCollector($c, $u)
            ->get(route('cobros.collector.portfolio'))
            ->assertOk()
            ->assertSee('NUEVA SOLICITUD')
            ->assertSee('MIS SOLICITUDES')
            ->assertSee('MIS CLIENTES')
            ->assertDontSee('>Nuevo cliente<')
            ->getContent();

        // La ruta Core sigue existiendo (solo se retira la navegación redundante).
        $this->get(route('cobros.collector.customers.create'))->assertOk();
    }

    private function member(Company $company, array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);

        return $user;
    }
}
