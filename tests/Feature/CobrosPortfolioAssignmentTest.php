<?php

namespace Tests\Feature;

use App\Models\AccountReceivable;
use App\Models\AccountReceivablePayment;
use App\Models\Branch;
use App\Models\CobrosAssignment;
use App\Models\CobrosAssignmentLog;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\CompanyLicenseService;
use App\Services\Cobros\CollectorManagementService;
use App\Services\Cobros\PortfolioAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CobrosPortfolioAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function ownerContext(array $extra = ['cobros.acceder', 'cobros.portal_dueno', 'cobros.asignar']): array
    {
        return $this->context($extra, 'Dueño');
    }

    private function supervisorContext(): array
    {
        return $this->context(['cobros.acceder', 'cobros.supervisar', 'cobros.asignar'], 'Supervisor');
    }

    private function context(array $permissions, string $roleName): array
    {
        $c = Company::create(['trade_name' => 'Cartera '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $b = Branch::create(['company_id' => $c->id, 'name' => 'Principal', 'code' => 'P', 'is_active' => true]);
        $u = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $c->id, 'name' => $roleName, 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $u->companies()->attach($c->id, ['role_id' => $role->id]);
        $u->branches()->attach($b->id);
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyLicenseService::class)->updateModules($c, $admin, ['cobros']);

        return [$c->fresh(), $b, $u];
    }

    private function makeCollector(Company $c, array $data = []): User
    {
        $service = app(CollectorManagementService::class);
        $actor = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        $result = $service->createCollector($c, $data + [
            'name' => 'Cobrador '.uniqid(),
            'email' => 'cob'.uniqid().'@test.test',
            'password' => 'password123',
        ], $actor, true);
        $result['user']->update(['must_change_password' => false]);

        return $result['user'];
    }

    private function makeCustomer(Company $c): Customer
    {
        return Customer::create([
            'company_id' => $c->id,
            'customer_type' => 'individual',
            'name' => 'Cliente '.uniqid(),
            'is_active' => true,
        ]);
    }

    private function arWithPayment(Company $c, Branch $b, User $actor, Customer $customer, string $balance = '5000.00'): AccountReceivable
    {
        $sale = Sale::create([
            'company_id' => $c->id,
            'branch_id' => $b->id,
            'user_id' => $actor->id,
            'customer_id' => $customer->id,
            'sale_number' => 'ASG-'.uniqid(),
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        return AccountReceivable::create([
            'company_id' => $c->id,
            'branch_id' => $b->id,
            'customer_id' => $customer->id,
            'sale_id' => $sale->id,
            'issued_at' => today(),
            'due_date' => today()->addDays(10),
            'original_amount' => $balance,
            'balance_due' => $balance,
            'status' => 'pending',
            'currency_code' => 'CRC',
        ]);
    }

    private function financialSnapshot(): array
    {
        return [
            DB::table('accounts_receivable')->orderBy('id')->get()->toJson(),
            DB::table('accounts_receivable_payments')->orderBy('id')->get()->toJson(),
            DB::table('sales')->orderBy('id')->get()->toJson(),
            DB::table('customers')->orderBy('id')->get()->toJson(),
        ];
    }

    public function test_owner_can_assign_customer_to_collector(): void
    {
        [$c, $b, $owner] = $this->ownerContext();
        $collector = $this->makeCollector($c);
        $customer = $this->makeCustomer($c);

        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.owner.assignments.store'), [
                'customer_id' => $customer->id,
                'collector_user_id' => $collector->id,
            ])->assertRedirect();

        $this->assertDatabaseHas('cobros_assignments', [
            'company_id' => $c->id,
            'customer_id' => $customer->id,
            'collector_user_id' => $collector->id,
            'assigned_by' => $owner->id,
        ]);
        $this->assertDatabaseHas('cobros_assignment_logs', [
            'company_id' => $c->id,
            'customer_id' => $customer->id,
            'action' => CobrosAssignmentLog::ACTION_ASSIGN,
            'to_collector_user_id' => $collector->id,
            'from_collector_user_id' => null,
            'actor_user_id' => $owner->id,
        ]);
    }

    public function test_supervisor_with_permission_can_assign(): void
    {
        [$c, $b, $supervisor] = $this->supervisorContext();
        $collector = $this->makeCollector($c);
        $customer = $this->makeCustomer($c);

        $this->actingAs($supervisor)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.owner.assignments.store'), [
                'customer_id' => $customer->id,
                'collector_user_id' => $collector->id,
            ])->assertRedirect();

        $this->assertDatabaseHas('cobros_assignments', ['customer_id' => $customer->id, 'collector_user_id' => $collector->id]);
    }

    public function test_collector_without_asignar_gets_403(): void
    {
        [$c2, $b2, $user] = $this->context(['cobros.acceder', 'cobros.cobrar'], 'Solo cobrar');
        $customer = $this->makeCustomer($c2);

        $this->actingAs($user)->withSession(['active_company_id' => $c2->id])
            ->get(route('cobros.owner.assignments'))->assertForbidden();
        $this->actingAs($user)->withSession(['active_company_id' => $c2->id])
            ->post(route('cobros.owner.assignments.store'), [
                'customer_id' => $customer->id,
                'collector_user_id' => $user->id,
            ])->assertForbidden();
    }

    public function test_reassign_updates_collector_and_logs(): void
    {
        [$c, $b, $owner] = $this->ownerContext();
        $mario = $this->makeCollector($c, ['name' => 'Mario']);
        $juan = $this->makeCollector($c, ['name' => 'Juan']);
        $customer = $this->makeCustomer($c);

        $service = app(PortfolioAssignmentService::class);
        $service->assign($c, $customer, $mario, $owner);
        $service->reassign($c, $customer, $juan, $owner, 'Rotación');

        $assignment = CobrosAssignment::where('customer_id', $customer->id)->first();
        $this->assertSame($juan->id, (int) $assignment->collector_user_id);

        $this->assertDatabaseHas('cobros_assignment_logs', [
            'action' => CobrosAssignmentLog::ACTION_REASSIGN,
            'from_collector_user_id' => $mario->id,
            'to_collector_user_id' => $juan->id,
            'reason' => 'Rotación',
        ]);
    }

    public function test_unassign_removes_and_logs_history_preserved(): void
    {
        [$c, $b, $owner] = $this->ownerContext();
        $collector = $this->makeCollector($c);
        $customer = $this->makeCustomer($c);

        $service = app(PortfolioAssignmentService::class);
        $service->assign($c, $customer, $collector, $owner);
        $service->unassign($c, $customer, $owner);

        $this->assertDatabaseMissing('cobros_assignments', ['customer_id' => $customer->id]);
        $this->assertSame(2, CobrosAssignmentLog::where('customer_id', $customer->id)->count());
        $this->assertDatabaseHas('cobros_assignment_logs', [
            'action' => CobrosAssignmentLog::ACTION_UNASSIGN,
            'from_collector_user_id' => $collector->id,
            'to_collector_user_id' => null,
        ]);
    }

    public function test_cross_tenant_customer_rejected(): void
    {
        [$cA, $bA, $ownerA] = $this->ownerContext();
        [$cB] = $this->ownerContext();
        $collector = $this->makeCollector($cA);
        $foreign = $this->makeCustomer($cB);

        $this->actingAs($ownerA)->withSession(['active_company_id' => $cA->id])
            ->post(route('cobros.owner.assignments.store'), [
                'customer_id' => $foreign->id,
                'collector_user_id' => $collector->id,
            ])->assertNotFound();

        $this->assertDatabaseMissing('cobros_assignments', ['customer_id' => $foreign->id]);
    }

    public function test_cross_tenant_collector_rejected(): void
    {
        [$cA, $bA, $ownerA] = $this->ownerContext();
        [$cB] = $this->ownerContext();
        $foreignCollector = $this->makeCollector($cB);
        $customer = $this->makeCustomer($cA);

        $this->actingAs($ownerA)->withSession(['active_company_id' => $cA->id])
            ->post(route('cobros.owner.assignments.store'), [
                'customer_id' => $customer->id,
                'collector_user_id' => $foreignCollector->id,
            ])->assertSessionHasErrors('collector_user_id');
    }

    public function test_inactive_collector_rejected(): void
    {
        [$c, $b, $owner] = $this->ownerContext();
        $collector = $this->makeCollector($c);
        $collector->update(['is_active' => false]);
        $customer = $this->makeCustomer($c);

        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.owner.assignments.store'), [
                'customer_id' => $customer->id,
                'collector_user_id' => $collector->id,
            ])->assertSessionHasErrors('collector_user_id');
    }

    public function test_user_without_cobrar_permission_rejected(): void
    {
        [$c, $b, $owner] = $this->ownerContext();
        $notCollector = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $c->id, 'name' => 'Empleado', 'is_active' => true]);
        $permission = Permission::firstOrCreate(['name' => 'clientes.ver'], ['label' => 'x', 'module' => 'customers', 'is_active' => true]);
        $role->permissions()->attach($permission);
        $notCollector->companies()->attach($c->id, ['role_id' => $role->id]);
        $customer = $this->makeCustomer($c);

        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.owner.assignments.store'), [
                'customer_id' => $customer->id,
                'collector_user_id' => $notCollector->id,
            ])->assertSessionHasErrors('collector_user_id');
    }

    public function test_non_collector_role_with_cobrar_flag_alone_is_enough_only_if_role_has_permission(): void
    {
        [$c, $b, $owner] = $this->ownerContext();
        // Role with cobros.cobrar attached but not via ensureCollectorRole still valid
        $pseudo = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $c->id, 'name' => 'Cobrador manual', 'is_active' => true]);
        foreach (['cobros.acceder', 'cobros.cobrar'] as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]));
        }
        $pseudo->companies()->attach($c->id, ['role_id' => $role->id]);
        $customer = $this->makeCustomer($c);

        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.owner.assignments.store'), [
                'customer_id' => $customer->id,
                'collector_user_id' => $pseudo->id,
            ])->assertRedirect();
    }

    public function test_missing_customer_404(): void
    {
        [$c, $b, $owner] = $this->ownerContext();
        $collector = $this->makeCollector($c);

        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.owner.assignments.store'), [
                'customer_id' => 999999,
                'collector_user_id' => $collector->id,
            ])->assertSessionHasErrors('customer_id');
    }

    public function test_unique_constraint_blocks_double_assignment(): void
    {
        [$c, $b, $owner] = $this->ownerContext();
        $collector = $this->makeCollector($c);
        $customer = $this->makeCustomer($c);

        $service = app(PortfolioAssignmentService::class);
        $service->assign($c, $customer, $collector, $owner);

        $exception = null;
        try {
            DB::table('cobros_assignments')->insert([
                'company_id' => $c->id,
                'customer_id' => $customer->id,
                'collector_user_id' => $collector->id,
                'assigned_by' => $owner->id,
                'assigned_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            $exception = $e;
        }

        $this->assertNotNull($exception);
        $this->assertSame(1, CobrosAssignment::where('customer_id', $customer->id)->count());
    }

    public function test_new_customer_starts_unassigned(): void
    {
        [$c, $b, $owner] = $this->ownerContext();
        $customer = $this->makeCustomer($c);

        $this->assertDatabaseMissing('cobros_assignments', ['customer_id' => $customer->id]);

        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.owner.assignments'))
            ->assertOk()
            ->assertSee($customer->name)
            ->assertSee('Sin asignar');
    }

    public function test_navigation_shows_only_with_asignar_permission(): void
    {
        [$c, $b, $owner] = $this->ownerContext();
        [$c2, $b2, $collectorUser] = $this->context(['cobros.acceder', 'cobros.cobrar', 'cobros.portal_dueno'], 'Dueño sin asignar');

        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.owner.dashboard'))
            ->assertOk()
            ->assertSee('Asignar cartera');

        $this->actingAs($collectorUser)->withSession(['active_company_id' => $c2->id])
            ->get(route('cobros.owner.dashboard'))
            ->assertOk()
            ->assertDontSee('>Asignar cartera<', false);
    }

    public function test_assignments_never_modify_financial_records(): void
    {
        [$c, $b, $owner] = $this->ownerContext();
        $mario = $this->makeCollector($c);
        $juan = $this->makeCollector($c);
        $customer = $this->makeCustomer($c);
        $this->arWithPayment($c, $b, $owner, $customer);

        $before = $this->financialSnapshot();
        $service = app(PortfolioAssignmentService::class);
        $service->assign($c, $customer, $mario, $owner);
        $service->reassign($c, $customer, $juan, $owner);
        $service->unassign($c, $customer, $owner);
        $after = $this->financialSnapshot();

        $this->assertSame($before, $after);
    }

    public function test_index_shows_balance_readonly_and_current_collector(): void
    {
        [$c, $b, $owner] = $this->ownerContext();
        $collector = $this->makeCollector($c, ['name' => 'Mario Vega']);
        $customer = $this->makeCustomer($c);
        $this->arWithPayment($c, $b, $owner, $customer, '1500.00');
        app(PortfolioAssignmentService::class)->assign($c, $customer, $collector, $owner);

        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.owner.assignments'))
            ->assertOk()
            ->assertSee($customer->name)
            ->assertSee('Mario Vega')
            ->assertSee('1,500.00');
    }

    public function test_filters_by_assignment_status(): void
    {
        [$c, $b, $owner] = $this->ownerContext();
        $collector = $this->makeCollector($c);
        $assigned = $this->makeCustomer($c);
        $free = $this->makeCustomer($c);
        app(PortfolioAssignmentService::class)->assign($c, $assigned, $collector, $owner);

        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.owner.assignments', ['assignment_status' => 'unassigned']))
            ->assertOk()
            ->assertSee($free->name)
            ->assertDontSee($assigned->name);

        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.owner.assignments', ['assignment_status' => 'assigned', 'collector_user_id' => $collector->id]))
            ->assertOk()
            ->assertSee($assigned->name)
            ->assertDontSee($free->name);
    }

    public function test_assignment_selectors_are_searchable_comboboxes_not_native_selects(): void
    {
        [$c, $b, $owner] = $this->ownerContext();
        $juan = $this->makeCollector($c, ['name' => 'JUAN PRUEBA']);
        $assigned = $this->makeCustomer($c);
        $free = $this->makeCustomer($c);
        app(PortfolioAssignmentService::class)->assign($c, $assigned, $juan, $owner);

        $html = $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.owner.assignments'))
            ->assertOk()
            ->assertSee('role="combobox"', false)
            ->assertSee('Buscar cobrador', false)
            ->assertSee('Buscar sucursal', false)
            ->assertSee('__cobrosCollectors', false)
            ->assertSee('JUAN PRUEBA')
            ->assertSee($b->name)
            ->getContent();

        $this->assertDoesNotMatchRegularExpression('/<select[^>]*name="collector_user_id"/', $html);
        $this->assertDoesNotMatchRegularExpression('/<select[^>]*name="branch_id"/', $html);
        $this->assertMatchesRegularExpression('/<select[^>]*name="assignment_status"/', $html);
        $this->assertSame(1, substr_count($html, 'name="assignment_status"'));
        $this->assertGreaterThanOrEqual(3, substr_count($html, 'role="combobox"'));
    }

    public function test_ensure_collector_role_still_only_acceder_and_cobrar(): void
    {
        [$c, $b, $owner] = $this->ownerContext();
        $collector = $this->makeCollector($c);
        $role = $collector->roleInCompany($c);

        $names = $role->permissions()->pluck('permissions.name')->sort()->values()->all();
        $this->assertSame(['cobros.acceder', 'cobros.cobrar'], $names);
        $this->assertNotContains('cobros.asignar', $names);
        $this->assertFalse($collector->hasPermission('cobros.asignar', $c));
    }

    public function test_destroy_requires_customer_in_company(): void
    {
        [$c, $b, $owner] = $this->ownerContext();
        [$cB] = $this->ownerContext();
        $foreign = $this->makeCustomer($cB);

        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->delete(route('cobros.owner.assignments.destroy', $foreign))
            ->assertNotFound();
    }
}
