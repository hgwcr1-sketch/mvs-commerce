<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyCashSettingsProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class P08CashOpeningGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_without_cash_goes_to_opening_and_login_with_cash_reaches_dashboard(): void
    {
        [$company, $branch, $user] = $this->context(['pos.acceder', 'caja.abrir']);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertRedirect(route('cash.open.create'));

        $this->openSession($company, $branch, $user);
        $this->get('/dashboard')->assertOk();
    }

    public function test_direct_pos_and_checkout_are_blocked_without_cash(): void
    {
        [$company, $branch, $user] = $this->context(['pos.acceder', 'ventas.crear', 'caja.abrir']);
        $this->actingAs($user)->withSession($this->contextSession($company, $branch))
            ->get(route('pos.index'))->assertRedirect(route('cash.open.create'));
        $this->postJson(route('pos.checkout'), [
            'checkout_token' => (string) Str::uuid(),
            'items' => [['product_id' => 999999, 'quantity' => 1]],
            'payments' => [['payment_method_id' => 999999, 'amount' => 1]],
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Debe abrir una sesión de caja para realizar ventas.');
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_closing_and_branch_change_revalidate_cash_context(): void
    {
        [$company, $branch, $user] = $this->context(['pos.acceder', 'ventas.crear', 'caja.abrir']);
        $other = Branch::create(['company_id' => $company->id, 'name' => 'Otra', 'code' => 'O', 'is_active' => true]);
        $user->branches()->attach($other->id);
        $cash = $this->openSession($company, $branch, $user);

        $this->actingAs($user)->withSession($this->contextSession($company, $branch))->get(route('pos.index'))->assertOk();
        $cash->update(['status' => CashSession::STATUS_CLOSED, 'open_guard' => null, 'closed_at' => now()]);
        $this->get(route('pos.index'))->assertRedirect(route('cash.open.create'));

        $this->openSession($company, $branch, $user);
        $this->post(route('branch.active.update'), ['branch_id' => $other->id]);
        $this->get(route('pos.index'))->assertRedirect(route('cash.open.create'));
    }

    public function test_permissions_admin_and_tenant_isolation_have_no_implicit_bypass(): void
    {
        [$company, $branch, $admin] = $this->context(['pos.acceder'], true);
        [$other, $otherBranch, $otherUser] = $this->context(['pos.acceder', 'caja.abrir']);
        $this->openSession($other, $otherBranch, $otherUser);

        $this->actingAs($admin)->withSession($this->contextSession($company, $branch))
            ->get(route('pos.index'))->assertRedirect(route('cash.required'));
        $this->get(route('cash.required'))->assertOk()->assertSee('Solicite a un administrador');

        $withoutPos = $this->user($company, $branch, []);
        $this->actingAs($withoutPos)->withSession($this->contextSession($company, $branch))
            ->get(route('pos.index'))->assertForbidden();
    }

    public function test_required_cash_screen_is_mobile_first_and_separates_future_hr_attendance(): void
    {
        [$company, $branch, $user] = $this->context(['pos.acceder']);
        $this->actingAs($user)->withSession($this->contextSession($company, $branch))->get(route('cash.required'))
            ->assertOk()->assertSee('min-h-[65vh]', false)->assertSee('sm:p-8', false)
            ->assertSee('no registra una jornada laboral');
    }

    private function context(array $permissions, bool $platformAdmin = false): array
    {
        $company = Company::create(['trade_name' => 'Empresa '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.uniqid(), 'is_active' => true]);
        app(CompanyCashSettingsProvisioner::class)->provision($company);
        $user = $this->user($company, $branch, $permissions, $platformAdmin);

        return [$company, $branch, $user];
    }

    private function user(Company $company, Branch $branch, array $permissions, bool $platformAdmin = false): User
    {
        $user = User::factory()->create(['password' => 'password', 'is_active' => true, 'is_platform_admin' => $platformAdmin]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'POS', 'is_active' => true]);
            $role->permissions()->syncWithoutDetaching($permission->id);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return $user;
    }

    private function openSession(Company $company, Branch $branch, User $user): CashSession
    {
        $register = CashRegister::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'C'.uniqid(), 'name' => 'Caja', 'is_active' => true]);

        return CashSession::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'cash_register_id' => $register->id, 'session_number' => 'CAJA-'.uniqid(), 'opened_by' => $user->id, 'status' => CashSession::STATUS_OPEN, 'open_guard' => CashSession::OPEN_GUARD, 'opening_amount' => 0, 'opened_at' => now()]);
    }

    private function contextSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
