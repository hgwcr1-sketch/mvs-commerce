<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\CobrosCredit;
use App\Models\CobrosCreditRequest;
use App\Models\CobrosRoute;
use App\Models\CobrosRouteUser;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Cobros\CobrosModalityCatalog;
use App\Services\Cobros\CobrosModalityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BLOQUE 13-2B — cambio seguro de empresa/prestamista sin cerrar sesión.
 * Selector de membresías autorizadas, limpieza de contexto, validación del
 * destino, operaciones sensibles, aislamiento de clientes/créditos y login.
 */
class CompanySwitchTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Company, 1: Branch} */
    private function company(string $suffix, bool $active = true): array
    {
        $company = Company::create([
            'trade_name' => 'Switch '.$suffix.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => $active,
        ]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'S'.uniqid(), 'is_active' => true]);

        return [$company, $branch];
    }

    private function member(Company $company, array $permissions, ?User $user = null, bool $roleActive = true): User
    {
        $user ??= User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => $roleActive]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'General', 'is_active' => true]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $branch = Branch::where('company_id', $company->id)->first();
        if ($branch) {
            $user->branches()->attach($branch->id);
        }

        return $user;
    }

    public function test_selector_lista_solo_membresias_activas_y_autorizadas(): void
    {
        [$companyA] = $this->company('a');
        [$companyB] = $this->company('b');
        [$companyInactive] = $this->company('c', false);
        [$companyWithoutRole] = $this->company('d');
        $user = $this->member($companyA, ['clientes.ver']);
        $this->member($companyB, ['clientes.ver'], $user);
        $this->member($companyInactive, ['clientes.ver'], $user);
        $this->member($companyWithoutRole, ['clientes.ver'], $user, false);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $companyA->id])
            ->get(route('company.switch'))
            ->assertOk()
            ->assertSee($companyA->trade_name)
            ->assertSee($companyB->trade_name)
            ->assertDontSee($companyInactive->trade_name)
            ->assertDontSee($companyWithoutRole->trade_name);
    }

    public function test_cambio_autorizado_actualiza_empresa_sin_cerrar_sesion_y_limpia_contexto(): void
    {
        [$companyA, $branchA] = $this->company('a');
        [$companyB] = $this->company('b');
        $user = $this->member($companyA, ['clientes.ver']);
        $this->member($companyB, ['clientes.ver'], $user);

        $this->actingAs($user)->withSession([
            'active_company_id' => $companyA->id,
            'active_branch_id' => $branchA->id,
            'active_cobros_route_id' => 999,
        ])
            ->post(route('company.switch.store'), ['company_id' => $companyB->id])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasNoErrors();

        $this->assertSame($companyB->id, (int) session('active_company_id'));
        $this->assertNull(session('active_branch_id'), 'La sucursal de la empresa anterior debe limpiarse.');
        $this->assertNull(session('active_cobros_route_id'), 'La ruta CobrOS anterior debe limpiarse.');

        // Sigue autenticada y operando en la nueva empresa, sin volver a login.
        $this->get(route('company.switch'))->assertOk()->assertSee($companyB->trade_name);
        $this->get(route('dashboard'))->assertOk();
        $this->assertSame($companyB->id, (int) session('active_company_id'));
    }

    public function test_cambio_a_empresa_ajena_o_inactiva_es_rechazado_sin_tocar_la_sesion(): void
    {
        [$companyA] = $this->company('a');
        [$companyForeign] = $this->company('x');
        [$companyInactive] = $this->company('c', false);
        [$companyInactiveRole] = $this->company('d');
        $user = $this->member($companyA, ['clientes.ver']);
        $this->member($companyInactive, ['clientes.ver'], $user);
        $this->member($companyInactiveRole, ['clientes.ver'], $user, false);

        $session = [
            'active_company_id' => $companyA->id,
            'active_cobros_route_id' => 777,
        ];

        // Empresa ajena: sin membresía.
        $this->actingAs($user)->withSession($session)
            ->post(route('company.switch.store'), ['company_id' => $companyForeign->id])
            ->assertSessionHasErrors('company_id');
        $this->assertSame($companyA->id, (int) session('active_company_id'));
        $this->assertSame(777, (int) session('active_cobros_route_id'));

        // Empresa inactiva: membresía existe pero la empresa no está activa.
        $this->actingAs($user)->withSession($session)
            ->post(route('company.switch.store'), ['company_id' => $companyInactive->id])
            ->assertSessionHasErrors('company_id');
        $this->assertSame($companyA->id, (int) session('active_company_id'));

        // Empresa activa pero con rol de membresía inactivo (acceso revocado).
        $this->actingAs($user)->withSession($session)
            ->post(route('company.switch.store'), ['company_id' => $companyInactiveRole->id])
            ->assertSessionHasErrors('company_id');
        $this->assertSame($companyA->id, (int) session('active_company_id'));
        $this->assertSame(777, (int) session('active_cobros_route_id'));
    }

    public function test_no_hereda_roles_rutas_ni_modalidad_de_la_empresa_anterior(): void
    {
        [$companyA] = $this->company('a');
        [$companyB] = $this->company('b');
        $user = $this->member($companyA, ['cobros.acceder', 'cobros.cobrar', 'clientes.ver']);
        $this->member($companyB, ['clientes.ver'], $user);

        // Ruta CobrOS asignada en A + modalidad propia de la membresía A.
        $route = CobrosRoute::create(['company_id' => $companyA->id, 'name' => 'Ruta A', 'active' => true]);
        $route->users()->attach($user->id, ['active' => true]);
        app(CobrosModalityService::class)->assign($companyA, $user, CobrosModalityCatalog::COBRADOR_VINCULADO);

        $this->assertTrue($user->fresh()->hasPermission('cobros.cobrar', $companyA));
        $this->assertFalse($user->fresh()->hasPermission('cobros.cobrar', $companyB));

        $this->actingAs($user)->withSession([
            'active_company_id' => $companyA->id,
            'active_cobros_route_id' => $route->id,
        ])->post(route('company.switch.store'), ['company_id' => $companyB->id])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasNoErrors();

        $user = $user->fresh();
        // Permisos y modalidad se resuelven por empresa destino: no hereda.
        $this->assertTrue($user->hasPermission('clientes.ver', $companyB));
        $this->assertFalse($user->hasPermission('cobros.cobrar', $companyB));
        $this->assertNull(app(CobrosModalityService::class)->currentModality($companyB, $user));
        $this->assertSame(CobrosModalityCatalog::COBRADOR_VINCULADO, app(CobrosModalityService::class)->currentModality($companyA, $user));

        // Ruta activa limpia en sesión; asignación e historial de A intactos.
        $this->assertNull(session('active_cobros_route_id'));
        // Desde la empresa B la ruta de A no es visible (alcance tenant)…
        $this->assertSame(0, CobrosRoute::count());
        // …pero el historial de A sigue intacto en su empresa.
        $this->assertSame(1, CobrosRouteUser::withoutGlobalScope('cobros_company')
            ->where('company_id', $companyA->id)
            ->where('user_id', $user->id)
            ->where('cobros_route_id', $route->id)
            ->where('active', true)
            ->count());
        $this->assertTrue(CobrosRoute::withoutGlobalScope('cobros_company')
            ->where('company_id', $companyA->id)
            ->whereKey($route->id)
            ->exists());
    }

    public function test_aislamiento_de_clientes_y_creditos_tras_el_cambio(): void
    {
        [$companyA, $branchA] = $this->company('a');
        [$companyB] = $this->company('b');
        $user = $this->member($companyA, ['clientes.ver']);
        $this->member($companyB, ['clientes.ver'], $user);

        Customer::create(['company_id' => $companyA->id, 'customer_type' => 'individual', 'name' => 'Alpha '.uniqid(), 'is_active' => true]);
        $beta = Customer::create(['company_id' => $companyB->id, 'customer_type' => 'individual', 'name' => 'Beta '.uniqid(), 'is_active' => true]);

        // Crédito histórico en A (solicitud + crédito sobre su ruta).
        $route = CobrosRoute::create(['company_id' => $companyA->id, 'name' => 'Ruta Crédito', 'active' => true]);
        $request = CobrosCreditRequest::create([
            'company_id' => $companyA->id,
            'cobros_route_id' => $route->id,
            'collector_user_id' => $user->id,
            'identification' => '101010101',
            'name' => 'Solicitante Alpha',
            'amount_requested' => 1500,
        ]);
        CobrosCredit::create([
            'company_id' => $companyA->id,
            'credit_request_id' => $request->id,
            'collector_user_id' => $user->id,
            'cobros_route_id' => $route->id,
            'principal' => 1500,
            'interest_type' => 'flat',
            'total_due' => 1600,
            'installment_count' => 1,
            'frequency' => 'monthly',
            'first_due_date' => now()->addMonth()->toDateString(),
            'terms_snapshot' => [],
        ]);

        $this->actingAs($user)->withSession([
            'active_company_id' => $companyA->id,
            'active_branch_id' => $branchA->id,
        ])->post(route('company.switch.store'), ['company_id' => $companyB->id])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasNoErrors();

        // Clientes: en B solo se ve Beta; Alpha sigue en A.
        $this->getJson(route('clientes.search', ['search' => $beta->name]))
            ->assertOk()
            ->assertJsonFragment(['name' => $beta->name]);
        $this->getJson(route('clientes.search', ['search' => 'Alpha']))
            ->assertOk()
            ->assertJsonCount(0);

        // Créditos: el alcance tenant de la sesión B no ve créditos de A…
        $this->assertSame(0, CobrosCredit::count());

        // …y al volver a A el historial sigue intacto en su empresa.
        $this->actingAs($user)->post(route('company.switch.store'), ['company_id' => $companyA->id])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, CobrosCredit::count());
        $this->assertSame($companyA->id, (int) session('active_company_id'));
    }

    public function test_cambio_bloqueado_durante_sesion_de_caja_abierta(): void
    {
        [$companyA, $branchA] = $this->company('a');
        [$companyB] = $this->company('b');
        $user = $this->member($companyA, ['clientes.ver']);
        $this->member($companyB, ['clientes.ver'], $user);

        $register = CashRegister::create(['company_id' => $companyA->id, 'branch_id' => $branchA->id, 'code' => 'CP'.uniqid(), 'name' => 'Caja', 'is_active' => true]);
        $cashSession = CashSession::create([
            'company_id' => $companyA->id,
            'branch_id' => $branchA->id,
            'cash_register_id' => $register->id,
            'session_number' => 'CP-1',
            'opened_by' => $user->id,
            'status' => CashSession::STATUS_OPEN,
            'opening_amount' => 0,
            'opened_at' => now(),
        ]);

        $this->actingAs($user)->withSession(['active_company_id' => $companyA->id])
            ->post(route('company.switch.store'), ['company_id' => $companyB->id])
            ->assertSessionHasErrors('company_id');
        $this->assertSame($companyA->id, (int) session('active_company_id'));

        $cashSession->update(['status' => CashSession::STATUS_CLOSED, 'closed_at' => now()]);

        $this->actingAs($user)->withSession(['active_company_id' => $companyA->id])
            ->post(route('company.switch.store'), ['company_id' => $companyB->id])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasNoErrors();
        $this->assertSame($companyB->id, (int) session('active_company_id'));
    }

    public function test_regresion_login_asigna_empresa_y_permite_cambiar(): void
    {
        [$companyA] = $this->company('a');
        [$companyB] = $this->company('b');
        $user = $this->member($companyA, ['clientes.ver']);
        $this->member($companyB, ['clientes.ver'], $user);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/dashboard');

        $this->get(route('dashboard'))->assertOk();
        $this->assertSame($companyA->id, (int) session('active_company_id'));

        $this->post(route('company.switch.store'), ['company_id' => $companyB->id])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasNoErrors();
        $this->assertSame($companyB->id, (int) session('active_company_id'));
        $this->assertTrue(auth()->check(), 'El cambio no debe cerrar la sesión.');
    }
}
