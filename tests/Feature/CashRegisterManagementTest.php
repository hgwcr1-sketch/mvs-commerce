<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\CompanyCashSetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CashRegisterManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_lists_only_registers_from_active_company(): void
    {
        [$company, $branch] = $this->context('Empresa activa');
        [$otherCompany, $otherBranch] = $this->context('Empresa ajena');
        $user = $this->userWithPermissions($company, ['caja.administrar']);
        $this->register($company, $branch, ['name' => 'Caja visible']);
        $this->register($otherCompany, $otherBranch, ['name' => 'Caja secreta']);

        $this->getAs($user, $company, route('settings.cash-registers.index'))
            ->assertOk()->assertSee('Caja visible')->assertDontSee('Caja secreta');
    }

    public function test_user_without_permission_receives_forbidden_by_url(): void
    {
        [$company] = $this->context('Empresa');
        $user = $this->userWithPermissions($company, []);
        $this->getAs($user, $company, route('settings.cash-registers.index'))->assertForbidden();
    }

    public function test_foreign_branch_cannot_be_assigned(): void
    {
        [$company] = $this->context('Empresa');
        [, $foreignBranch] = $this->context('Otra empresa');
        $user = $this->userWithPermissions($company, ['caja.administrar']);

        $this->postAs($user, $company, route('settings.cash-registers.store'), $this->payload($foreignBranch))
            ->assertSessionHasErrors('branch_id');
        $this->assertSame(0, CashRegister::forCompany($company->id)->count());
    }

    public function test_creates_caja_liberia_with_normalized_code_and_ignores_company_id(): void
    {
        [$company, $branch] = $this->context('Empresa', 'Liberia');
        [$otherCompany] = $this->context('Ajena');
        $user = $this->userWithPermissions($company, ['caja.administrar']);

        $this->postAs($user, $company, route('settings.cash-registers.store'), $this->payload($branch, [
            'company_id' => $otherCompany->id, 'name' => 'Caja Liberia 1', 'code' => 'Caja Liberia 1',
        ]))->assertRedirect(route('settings.cash-registers.index'))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('cash_registers', ['company_id' => $company->id, 'branch_id' => $branch->id, 'name' => 'Caja Liberia 1', 'code' => 'caja_liberia_1']);
        $this->assertDatabaseMissing('cash_registers', ['company_id' => $otherCompany->id, 'name' => 'Caja Liberia 1']);
    }

    public function test_code_is_unique_per_company_and_branch_but_allowed_in_another_branch(): void
    {
        [$company, $firstBranch] = $this->context('Empresa');
        $secondBranch = $this->branch($company, 'Segunda');
        $user = $this->userWithPermissions($company, ['caja.administrar']);
        $this->register($company, $firstBranch, ['code' => 'caja_1', 'is_active' => false]);

        $this->postAs($user, $company, route('settings.cash-registers.store'), $this->payload($firstBranch, ['code' => 'caja_1', 'is_active' => false]))->assertSessionHasErrors('code');
        $this->postAs($user, $company, route('settings.cash-registers.store'), $this->payload($secondBranch, ['code' => 'caja_1']))->assertSessionHasNoErrors();
        $this->assertSame(2, CashRegister::forCompany($company->id)->where('code', 'caja_1')->count());
    }

    public function test_only_one_default_register_remains_per_branch(): void
    {
        [$company, $branch] = $this->context('Empresa');
        $user = $this->userWithPermissions($company, ['caja.administrar']);
        $first = $this->register($company, $branch, ['is_default' => true, 'is_active' => false]);

        $this->postAs($user, $company, route('settings.cash-registers.store'), $this->payload($branch, ['code' => 'segunda', 'is_default' => true]))->assertSessionHasNoErrors();
        $this->assertFalse($first->fresh()->is_default);
        $this->assertSame(1, CashRegister::forBranch($branch->id)->where('is_default', true)->count());
    }

    public function test_single_register_setting_rejects_two_active_registers(): void
    {
        [$company, $branch] = $this->context('Empresa');
        $this->settings($company, false);
        $user = $this->userWithPermissions($company, ['caja.administrar']);
        $this->register($company, $branch);

        $this->postAs($user, $company, route('settings.cash-registers.store'), $this->payload($branch, ['code' => 'segunda']))
            ->assertSessionHasErrors('is_active');
        $this->assertSame(1, CashRegister::forBranch($branch->id)->active()->count());
    }

    public function test_multiple_register_setting_allows_two_active_registers(): void
    {
        [$company, $branch] = $this->context('Empresa');
        $this->settings($company, true);
        $user = $this->userWithPermissions($company, ['caja.administrar']);
        $this->register($company, $branch);
        $this->postAs($user, $company, route('settings.cash-registers.store'), $this->payload($branch, ['code' => 'segunda']))->assertSessionHasNoErrors();
        $this->assertSame(2, CashRegister::forBranch($branch->id)->active()->count());
    }

    public function test_open_session_prevents_deactivation(): void
    {
        [$company, $branch] = $this->context('Empresa');
        $user = $this->userWithPermissions($company, ['caja.administrar']);
        $register = $this->register($company, $branch);
        $this->cashSession($company, $branch, $register, $user, CashSession::STATUS_OPEN);

        $this->actingAs($user)->withSession(['active_company_id' => $company->id])
            ->patch(route('settings.cash-registers.toggle-status', $register))
            ->assertSessionHasErrors('cash_register');
        $this->assertTrue($register->fresh()->is_active);
    }

    public function test_historical_session_prevents_branch_change_but_allows_other_updates(): void
    {
        [$company, $branch] = $this->context('Empresa');
        $otherBranch = $this->branch($company, 'Otra');
        $user = $this->userWithPermissions($company, ['caja.administrar']);
        $register = $this->register($company, $branch);
        $this->cashSession($company, $branch, $register, $user, CashSession::STATUS_CLOSED);

        $this->putAs($user, $company, route('settings.cash-registers.update', $register), $this->payload($otherBranch, ['name' => 'Alterada']))
            ->assertSessionHasErrors('branch_id');
        $this->assertSame($branch->id, $register->fresh()->branch_id);

        $this->putAs($user, $company, route('settings.cash-registers.update', $register), $this->payload($branch, ['name' => 'Nombre nuevo']))->assertSessionHasNoErrors();
        $this->assertSame('Nombre nuevo', $register->fresh()->name);
    }

    public function test_foreign_register_cannot_be_opened_modified_or_toggled(): void
    {
        [$company] = $this->context('Empresa');
        [$otherCompany, $otherBranch] = $this->context('Ajena');
        $user = $this->userWithPermissions($company, ['caja.administrar']);
        $foreign = $this->register($otherCompany, $otherBranch);

        $this->getAs($user, $company, route('settings.cash-registers.edit', $foreign))->assertNotFound();
        $this->putAs($user, $company, route('settings.cash-registers.update', $foreign), $this->payload($otherBranch))->assertForbidden();
        $this->actingAs($user)->withSession(['active_company_id' => $company->id])->patch(route('settings.cash-registers.toggle-status', $foreign))->assertNotFound();
        $this->assertTrue($foreign->fresh()->is_active);
    }

    public function test_toggle_changes_status_without_deleting_history(): void
    {
        [$company, $branch] = $this->context('Empresa');
        $user = $this->userWithPermissions($company, ['caja.administrar']);
        $register = $this->register($company, $branch, ['is_active' => false]);
        $this->cashSession($company, $branch, $register, $user, CashSession::STATUS_CLOSED);

        $this->actingAs($user)->withSession(['active_company_id' => $company->id])->patch(route('settings.cash-registers.toggle-status', $register))->assertSessionHasNoErrors();
        $this->assertTrue($register->fresh()->is_active);
        $this->assertSame(1, $register->sessions()->count());
    }

    public function test_destroy_route_does_not_exist(): void
    {
        $this->assertFalse(Route::has('settings.cash-registers.destroy'));
        $this->assertFalse(collect(Route::getRoutes())->contains(fn ($route) => str_contains($route->uri(), 'configuracion/caja/cajas') && in_array('DELETE', $route->methods(), true)));
    }

    public function test_sidebar_shows_each_configuration_link_only_with_its_permission(): void
    {
        [$company] = $this->context('Empresa');
        $cashUser = $this->userWithPermissions($company, ['caja.administrar']);
        $this->getAs($cashUser, $company, route('settings.cash-registers.index'))->assertSee('Configuración')->assertSee('Cajas')->assertDontSee('Formas de pago');

        $paymentUser = $this->userWithPermissions($company, ['formas_pago.administrar']);
        $this->getAs($paymentUser, $company, route('settings.pos.payment-methods.index'))->assertSee('Configuración')->assertSee('Formas de pago')->assertDontSee('>Cajas<', false);
    }

    public function test_configuration_dropdown_uses_alpine_cloak_and_preserves_other_dropdowns(): void
    {
        [$company] = $this->context('Empresa');
        $user = $this->userWithPermissions($company, ['caja.administrar', 'productos.ver']);
        $this->getAs($user, $company, route('settings.cash-registers.index'))
            ->assertSee('x-data="{ open: false }"', false)
            ->assertSee('x-cloak', false)->assertSee('x-show="open"', false)
            ->assertSee('Productos')->assertSee('Configuración');
    }

    public function test_index_create_and_edit_views_have_top_back_button(): void
    {
        [$company, $branch] = $this->context('Empresa');
        $user = $this->userWithPermissions($company, ['caja.administrar']);
        $register = $this->register($company, $branch);
        $this->getAs($user, $company, route('settings.cash-registers.index'))->assertSee('Volver');
        $this->getAs($user, $company, route('settings.cash-registers.create'))->assertSee('Volver');
        $this->getAs($user, $company, route('settings.cash-registers.edit', $register))->assertSee('Volver');
    }

    private function context(string $companyName, string $branchName = 'Principal'): array
    {
        $company = Company::create(['trade_name' => $companyName, 'is_active' => true]);
        return [$company, $this->branch($company, $branchName)];
    }

    private function branch(Company $company, string $name): Branch
    {
        return Branch::create(['company_id' => $company->id, 'name' => $name, 'code' => strtoupper(substr($name, 0, 3)).'-'.uniqid(), 'is_active' => true]);
    }

    private function settings(Company $company, bool $multiple): CompanyCashSetting
    {
        return CompanyCashSetting::create(['company_id' => $company->id, 'allow_multiple_registers' => $multiple]);
    }

    private function userWithPermissions(Company $company, array $names): User
    {
        $user = User::factory()->create();
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($names as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Pruebas', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        return $user;
    }

    private function register(Company $company, Branch $branch, array $attributes = []): CashRegister
    {
        return CashRegister::create(array_merge(['company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'caja_'.uniqid(), 'name' => 'Caja', 'is_active' => true, 'is_default' => false], $attributes));
    }

    private function payload(Branch $branch, array $attributes = []): array
    {
        return array_merge(['branch_id' => $branch->id, 'code' => 'caja_1', 'name' => 'Caja 1', 'is_active' => '1', 'is_default' => '0'], $attributes);
    }

    private function cashSession(Company $company, Branch $branch, CashRegister $register, User $user, string $status): CashSession
    {
        return CashSession::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'cash_register_id' => $register->id, 'session_number' => 'CAJA-'.uniqid(), 'opened_by' => $user->id, 'status' => $status, 'open_guard' => $status === CashSession::STATUS_OPEN ? CashSession::OPEN_GUARD : null, 'opening_amount' => 0, 'opened_at' => now(), 'closed_at' => $status === CashSession::STATUS_CLOSED ? now() : null]);
    }

    private function getAs(User $user, Company $company, string $url) { return $this->actingAs($user)->withSession(['active_company_id' => $company->id])->get($url); }
    private function postAs(User $user, Company $company, string $url, array $data) { return $this->actingAs($user)->withSession(['active_company_id' => $company->id])->post($url, $data); }
    private function putAs(User $user, Company $company, string $url, array $data) { return $this->actingAs($user)->withSession(['active_company_id' => $company->id])->put($url, $data); }
}
