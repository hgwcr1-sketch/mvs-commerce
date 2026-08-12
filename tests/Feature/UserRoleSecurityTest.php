<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserRoleSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_view_permission_cannot_create_edit_or_remove_users(): void
    {
        [$company, $branch] = $this->companyContext('Empresa uno');
        $viewer = $this->userWithPermissions($company, ['usuarios.ver']);
        $target = $this->companyUser($company, $branch, 'Objetivo');
        $session = ['active_company_id' => $company->id];

        $this->actingAs($viewer)->withSession($session)
            ->post('/usuarios', $this->userPayload($branch))
            ->assertForbidden();

        $this->actingAs($viewer)->withSession($session)
            ->get("/usuarios/{$target->id}/edit")
            ->assertForbidden();

        $this->actingAs($viewer)->withSession($session)
            ->delete("/usuarios/{$target->id}")
            ->assertForbidden();
    }

    public function test_role_from_another_company_cannot_be_assigned(): void
    {
        [$company, $branch] = $this->companyContext('Empresa uno');
        [$otherCompany] = $this->companyContext('Empresa dos');
        $administrator = $this->userWithPermissions($company, ['usuarios.crear']);
        $otherRole = $this->role($otherCompany, 'Rol ajeno');

        $payload = $this->userPayload($branch);
        $payload['role_id'] = $otherRole->id;

        $this->actingAs($administrator)
            ->withSession(['active_company_id' => $company->id])
            ->post('/usuarios', $payload)
            ->assertSessionHasErrors('role_id');
    }

    public function test_branch_from_another_company_cannot_be_assigned(): void
    {
        [$company] = $this->companyContext('Empresa uno');
        [, $otherBranch] = $this->companyContext('Empresa dos');
        $administrator = $this->userWithPermissions($company, ['usuarios.crear']);
        $role = $this->role($company, 'Operador');

        $payload = $this->userPayload($otherBranch);
        $payload['role_id'] = $role->id;

        $this->actingAs($administrator)
            ->withSession(['active_company_id' => $company->id])
            ->post('/usuarios', $payload)
            ->assertSessionHasErrors('branches.0');
    }

    public function test_removing_user_cleans_only_active_company_branches(): void
    {
        [$company, $branch] = $this->companyContext('Empresa uno');
        [$otherCompany, $otherBranch] = $this->companyContext('Empresa dos');
        $administrator = $this->userWithPermissions($company, ['usuarios.eliminar']);
        $target = User::factory()->create();
        $target->companies()->attach($company->id, [
            'role_id' => $this->role($company, 'Operador')->id,
        ]);
        $target->companies()->attach($otherCompany->id, [
            'role_id' => $this->role($otherCompany, 'Operador')->id,
        ]);
        $target->branches()->attach([$branch->id, $otherBranch->id]);

        $this->actingAs($administrator)
            ->withSession(['active_company_id' => $company->id])
            ->delete("/usuarios/{$target->id}")
            ->assertRedirect('/usuarios');

        $this->assertDatabaseMissing('company_user', [
            'company_id' => $company->id,
            'user_id' => $target->id,
        ]);
        $this->assertDatabaseMissing('branch_user', [
            'branch_id' => $branch->id,
            'user_id' => $target->id,
        ]);
        $this->assertDatabaseHas('company_user', [
            'company_id' => $otherCompany->id,
            'user_id' => $target->id,
        ]);
        $this->assertDatabaseHas('branch_user', [
            'branch_id' => $otherBranch->id,
            'user_id' => $target->id,
        ]);
    }

    public function test_role_in_company_rejects_inconsistent_company_role(): void
    {
        [$company] = $this->companyContext('Empresa uno');
        [$otherCompany] = $this->companyContext('Empresa dos');
        $user = User::factory()->create();
        $otherRole = $this->role($otherCompany, 'Rol ajeno');

        DB::table('company_user')->insert([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'role_id' => $otherRole->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNull($user->roleInCompany($company));
        $this->assertFalse($user->hasPermission('usuarios.ver', $company));
        $this->assertFalse($otherRole->users->contains($user));
    }

    public function test_company_administrator_cannot_change_global_multi_company_user_data(): void
    {
        [$company, $branch] = $this->companyContext('Empresa uno');
        [$otherCompany, $otherBranch] = $this->companyContext('Empresa dos');
        $administrator = $this->userWithPermissions($company, ['usuarios.editar']);
        $target = $this->companyUser($company, $branch, 'Usuario compartido');
        $target->companies()->attach($otherCompany->id, [
            'role_id' => $this->role($otherCompany, 'Operador')->id,
        ]);
        $target->branches()->attach($otherBranch->id);
        $originalEmail = $target->email;

        $payload = $this->updatePayload($target, $branch);
        $payload['email'] = 'cambio-global@example.test';

        $this->actingAs($administrator)
            ->withSession(['active_company_id' => $company->id])
            ->put("/usuarios/{$target->id}", $payload)
            ->assertSessionHasErrors('user');

        $this->assertSame($originalEmail, $target->fresh()->email);
    }

    public function test_last_active_administrator_cannot_be_removed(): void
    {
        [$company, $branch] = $this->companyContext('Empresa uno');
        $manager = $this->userWithPermissions($company, ['usuarios.eliminar']);
        $administrator = User::factory()->create(['is_active' => true]);
        $administratorRole = $this->role($company, 'Administrador');
        $administrator->companies()->attach($company->id, [
            'role_id' => $administratorRole->id,
        ]);
        $administrator->branches()->attach($branch->id);

        $this->actingAs($manager)
            ->withSession(['active_company_id' => $company->id])
            ->delete("/usuarios/{$administrator->id}")
            ->assertRedirect('/usuarios')
            ->assertSessionHas('error');

        $this->assertDatabaseHas('company_user', [
            'company_id' => $company->id,
            'user_id' => $administrator->id,
            'role_id' => $administratorRole->id,
        ]);
    }

    public function test_user_without_permissions_cannot_manage_roles_by_direct_url(): void
    {
        [$company] = $this->companyContext('Empresa uno');
        $user = $this->userWithPermissions($company, []);
        $role = $this->role($company, 'Operador');
        $session = ['active_company_id' => $company->id];

        $this->actingAs($user)->withSession($session)
            ->get('/roles')
            ->assertForbidden();

        $this->actingAs($user)->withSession($session)
            ->post('/roles', ['name' => 'Intruso'])
            ->assertForbidden();

        $this->actingAs($user)->withSession($session)
            ->put("/roles/{$role->id}", ['name' => 'Alterado'])
            ->assertForbidden();

        $this->actingAs($user)->withSession($session)
            ->delete("/roles/{$role->id}")
            ->assertForbidden();
    }

    private function companyContext(string $name): array
    {
        $company = Company::create([
            'trade_name' => $name,
            'is_active' => true,
        ]);
        $branch = Branch::create([
            'company_id' => $company->id,
            'name' => 'Principal',
            'code' => 'P-'.$company->id,
            'is_active' => true,
        ]);

        return [$company, $branch];
    }

    private function permission(string $name): Permission
    {
        return Permission::firstOrCreate(
            ['name' => $name],
            [
                'label' => $name,
                'module' => 'Pruebas',
                'is_active' => true,
            ]
        );
    }

    private function role(Company $company, string $name): Role
    {
        return Role::firstOrCreate(
            ['company_id' => $company->id, 'name' => $name],
            ['is_active' => true]
        );
    }

    private function userWithPermissions(Company $company, array $permissions): User
    {
        $user = User::factory()->create();
        $role = $this->role($company, 'Rol '.uniqid());
        $role->permissions()->sync(
            collect($permissions)->map(
                fn (string $permission) => $this->permission($permission)->id
            )
        );
        $user->companies()->attach($company->id, ['role_id' => $role->id]);

        return $user;
    }

    private function companyUser(Company $company, Branch $branch, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'is_active' => true,
        ]);
        $role = $this->role($company, 'Operador');
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return $user;
    }

    private function userPayload(Branch $branch): array
    {
        return [
            'name' => 'Usuario nuevo',
            'email' => 'nuevo-'.uniqid().'@example.test',
            'password' => 'password-seguro',
            'password_confirmation' => 'password-seguro',
            'is_active' => true,
            'role_id' => 999999,
            'branches' => [$branch->id],
        ];
    }

    private function updatePayload(User $user, Branch $branch): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'is_active' => $user->is_active,
            'role_id' => $user->roleInCompany($branch->company)->id,
            'branches' => [$branch->id],
        ];
    }
}
