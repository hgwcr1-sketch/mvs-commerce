<?php

namespace Tests\Feature;

use App\Models\AccountReceivable;
use App\Models\Branch;
use App\Models\CobrosAssignment;
use App\Models\CobrosReceipt;
use App\Models\CobrosRoute;
use App\Models\CobrosRouteLicense;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\Cobros\CobrosRouteContext;
use App\Services\Cobros\CollectorLicenseService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CobrosRouteFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $company = Company::create(['trade_name' => 'Rutas', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $company->modules()->create(['module_key' => 'cobros', 'is_enabled' => true, 'license_mode' => 'company_cupo', 'collector_limit' => 1]);
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Cobrador', 'is_active' => true]);
        $permission = Permission::firstOrCreate(['name' => 'cobros.cobrar'], ['label' => 'Cobrar', 'module' => 'CobrOS', 'is_active' => true]);
        $role->permissions()->attach($permission);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        session(['active_company_id' => $company->id]);
        $a = CobrosRoute::create(['company_id' => $company->id, 'name' => 'A', 'active' => true]);
        $b = CobrosRoute::create(['company_id' => $company->id, 'name' => 'B', 'active' => true]);
        $a->users()->attach($user->id, ['active' => true]);
        $b->users()->attach($user->id, ['active' => true]);

        return [$company, $user, $a, $b];
    }

    private function grant(Company $company, CobrosRoute $route): CobrosRouteLicense
    {
        return app(CollectorLicenseService::class)->renewForRoute($route, $company,
            User::factory()->create(['is_platform_admin' => true, 'is_active' => true]), now()->addMonth());
    }

    private function assignment(Company $company, User $user, CobrosRoute $route): CobrosAssignment
    {
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Cliente '.uniqid(), 'customer_type' => 'individual', 'is_active' => true]);

        return CobrosAssignment::create(['company_id' => $company->id, 'cobros_route_id' => $route->id,
            'customer_id' => $customer->id, 'collector_user_id' => $user->id, 'assigned_by' => $user->id, 'assigned_at' => now()]);
    }

    public function test_one_person_has_multiple_routes_and_eager_relations_are_isolated(): void
    {
        [$company, $user, $a, $b] = $this->context();
        $first = $this->assignment($company, $user, $a);
        $second = $this->assignment($company, $user, $b);
        $this->assertCount(2, $user->cobrosRoutes);
        $this->assertCount(2, $company->cobrosRoutes);
        $this->assertSame([$first->id], CobrosAssignment::forRoute($a)->pluck('id')->all());
        $this->assertSame([$second->id], CobrosAssignment::forRoute($b)->pluck('id')->all());
        $routes = CobrosRoute::with(['users', 'assignments', 'license'])->orderBy('id')->get();
        $this->assertSame($user->id, $routes[0]->users->sole()->id);
        $this->assertSame($first->id, $routes[0]->assignments->sole()->id);
        $this->assertSame($second->id, $routes[1]->assignments->sole()->id);
        $this->assertSame($a->id, CobrosAssignment::with('cobrosRoute')->findOrFail($first->id)->cobrosRoute->id);
    }

    public function test_active_company_hides_other_routes_and_cannot_be_overridden_by_scope(): void
    {
        [$company, , $a] = $this->context();
        [$other] = $this->context();
        $this->assertNull(CobrosRoute::find($a->id));
        $this->assertSame(0, CobrosRoute::forCompany($company->id)->count());
        $this->assertSame(2, CobrosRoute::forCompany($other->id)->count());
        $this->expectException(ValidationException::class);
        CobrosRoute::create(['company_id' => $company->id, 'name' => 'Cross']);
    }

    public function test_route_name_is_unique_within_company(): void
    {
        [$company] = $this->context();
        $this->expectException(QueryException::class);
        CobrosRoute::create(['company_id' => $company->id, 'name' => 'A']);
    }

    public function test_linked_user_must_belong_to_company_even_when_attached_via_relation(): void
    {
        [, , $a] = $this->context();
        $outsider = User::factory()->create();
        $this->expectException(ValidationException::class);
        $a->users()->attach($outsider->id, ['active' => true]);
    }

    public function test_database_rejects_cross_company_route_even_without_model_events(): void
    {
        [, , $foreign] = $this->context();
        [$company, $user] = $this->context();
        $this->expectException(QueryException::class);
        DB::table('cobros_route_user')->insert(['company_id' => $company->id, 'cobros_route_id' => $foreign->id, 'user_id' => $user->id, 'active' => true]);
    }

    public function test_assignment_cannot_reference_another_company_route(): void
    {
        [, , $foreign] = $this->context();
        [$company, $user] = $this->context();
        $this->expectException(ValidationException::class);
        $this->assignment($company, $user, $foreign);
    }

    public function test_route_license_is_independent_and_never_auto_granted_or_exempted_by_cupo(): void
    {
        [$company, , $a, $b] = $this->context();
        $service = app(CollectorLicenseService::class);
        $this->assertFalse($service->hasValidRouteLicense($a, $company));
        $license = $this->grant($company, $a);
        $this->assertTrue($service->hasValidRouteLicense($a, $company));
        $this->assertFalse($service->hasValidRouteLicense($b, $company));
        $this->assertSame(1, CobrosRouteLicense::count());
        $this->assertSame($license->id, $a->fresh()->license->id);
        $this->assertSame(0, DB::table('cobros_collector_licenses')->count());
        $license->update(['status' => 'suspended']);
        $this->assertFalse($service->hasValidRouteLicense($a, $company));
        $license->update(['status' => 'active', 'expires_at' => now()->subSecond()]);
        $this->assertFalse($service->hasValidRouteLicense($a, $company));
        $this->grant($company, $a);
        $this->assertTrue($service->hasValidRouteLicense($a, $company));
        $this->assertSame(1, CobrosRouteLicense::count());
    }

    public function test_paid_license_cannot_be_transferred_to_another_route(): void
    {
        [$company, , $a, $b] = $this->context();
        $license = $this->grant($company, $a);
        $this->expectException(ValidationException::class);
        $license->update(['cobros_route_id' => $b->id]);
    }

    public function test_one_active_route_at_a_time_without_any_branch_assignment(): void
    {
        [$company, $user, $a, $b] = $this->context();
        $this->grant($company, $a);
        $this->grant($company, $b);
        $context = app(CobrosRouteContext::class);
        $this->assertSame(0, $user->branches()->count());
        $context->select($user, $a->id);
        $this->assertSame($a->id, $context->current($user)->id);
        $context->select($user, $b->id);
        $this->assertSame($b->id, $context->current($user)->id);
        $this->assertSame($b->id, session('active_cobros_route_id'));
        $this->assertCount(2, $user->cobrosRoutes);
        $b->users()->updateExistingPivot($user->id, ['active' => false]);
        $this->expectException(ValidationException::class);
        $context->current($user);
    }

    public function test_branch_membership_never_grants_route_access(): void
    {
        [$company, $user, $a] = $this->context();
        $this->grant($company, $a);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P', 'is_active' => true]);
        $user->branches()->attach($branch->id);
        session(['active_branch_id' => $branch->id]);
        $a->users()->detach($user->id);
        $this->expectException(ValidationException::class);
        app(CobrosRouteContext::class)->select($user, $a->id);
    }

    public function test_company_switch_revalidates_active_route(): void
    {
        [$company, $user, $a] = $this->context();
        $this->grant($company, $a);
        app(CobrosRouteContext::class)->select($user, $a->id);
        $this->context();
        $this->expectException(ValidationException::class);
        app(CobrosRouteContext::class)->current($user);
    }

    public function test_nullable_route_keeps_legacy_assignments_unchanged(): void
    {
        [$company, $user, $a] = $this->context();
        $assignment = $this->assignment($company, $user, $a);
        $assignment->update(['cobros_route_id' => null]);
        $this->assertNull($assignment->fresh()->cobros_route_id);
        $this->assertSame(0, CobrosAssignment::forRoute($a)->count());
        $this->assertSame(1, CobrosAssignment::forCompany($company->id)->count());
    }

    public function test_receivable_and_receipt_reject_foreign_route_before_writing(): void
    {
        [, , $foreign] = $this->context();
        [$company] = $this->context();
        foreach ([AccountReceivable::class, CobrosReceipt::class] as $model) {
            try {
                $model::create(['company_id' => $company->id, 'cobros_route_id' => $foreign->id]);
                $this->fail('Cross-company route accepted');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('cobros_route_id', $e->errors());
            }
        }
    }

    public function test_held_route_relations_are_empty_after_company_switch(): void
    {
        [$company, $user, $a] = $this->context();
        $this->assignment($company, $user, $a);
        $this->grant($company, $a);
        $this->context();
        $this->assertSame(0, $a->users()->count());
        $this->assertSame(0, $a->assignments()->count());
        $this->assertNull($a->license()->first());
        $this->assertSame(0, CobrosAssignment::forRoute($a)->count());
        $this->assertFalse(app(CollectorLicenseService::class)->hasValidRouteLicense($a, $company));
    }

    public function test_receivables_and_receipts_remain_separate_with_the_same_branch_and_collector(): void
    {
        [$company, $user, $a, $b] = $this->context();
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P', 'is_active' => true]);
        $method = PaymentMethod::create(['company_id' => $company->id, 'code' => 'CASH', 'name' => 'Efectivo', 'type' => 'cash', 'is_active' => true]);
        $accounts = [];
        $receipts = [];
        foreach ([$a, $b] as $route) {
            $assignment = $this->assignment($company, $user, $route);
            $sale = Sale::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $user->id,
                'customer_id' => $assignment->customer_id, 'sale_number' => 'ROUTE-'.$route->id, 'status' => 'completed']);
            $account = AccountReceivable::create(['company_id' => $company->id, 'branch_id' => $branch->id,
                'cobros_route_id' => $route->id, 'customer_id' => $assignment->customer_id, 'sale_id' => $sale->id,
                'issued_at' => today(), 'due_date' => today()->addMonth(), 'original_amount' => '100.0000',
                'balance_due' => '100.0000', 'status' => 'pending', 'currency_code' => 'CRC']);
            $receipt = CobrosReceipt::create(['company_id' => $company->id, 'branch_id' => $branch->id,
                'cobros_route_id' => $route->id, 'customer_id' => $assignment->customer_id, 'account_receivable_id' => $account->id,
                'collector_user_id' => $user->id, 'payment_method_id' => $method->id, 'amount' => '10.0000',
                'currency_code' => 'CRC', 'client_token' => 'route-'.$route->id, 'payload_hash' => str_repeat('a', 64), 'paid_at' => now()]);
            $accounts[] = $account;
            $receipts[] = $receipt;
        }
        $this->assertSame([$accounts[0]->id], AccountReceivable::forRoute($a)->pluck('id')->all());
        $this->assertSame([$receipts[1]->id], CobrosReceipt::forRoute($b)->pluck('id')->all());
        $routes = CobrosRoute::with(['accountsReceivable', 'receipts'])->orderBy('id')->get();
        $this->assertSame($accounts[0]->id, $routes[0]->accountsReceivable->sole()->id);
        $this->assertSame($receipts[1]->id, $routes[1]->receipts->sole()->id);
        $this->assertSame($branch->id, $accounts[0]->fresh()->branch_id);
        $this->expectException(ValidationException::class);
        $receipts[0]->update(['cobros_route_id' => $b->id]);
    }

    public function test_route_renewal_requires_platform_admin_and_cannot_target_foreign_company(): void
    {
        [$company, $user, $a] = $this->context();
        try {
            app(CollectorLicenseService::class)->renewForRoute($a, $company, $user, now()->addMonth());
            $this->fail('Tenant cannot issue a paid license');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        [$other] = $this->context();
        $this->expectException(ValidationException::class);
        $this->grant($other, $a);
    }

    public function test_disabled_module_and_company_license_block_even_a_paid_route(): void
    {
        [$company, , $a] = $this->context();
        $this->grant($company, $a);
        $service = app(CollectorLicenseService::class);
        $company->modules()->where('module_key', 'cobros')->update(['is_enabled' => false]);
        $this->assertFalse($service->hasValidRouteLicense($a, $company));
        $company->modules()->where('module_key', 'cobros')->update(['is_enabled' => true]);
        $company->license()->update(['status' => 'suspended']);
        $this->assertFalse($service->hasValidRouteLicense($a, $company));
        $this->assertDatabaseHas('company_license_events', ['company_id' => $company->id, 'action' => 'cobros_route_renewal']);
    }
}
