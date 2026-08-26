<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Professional;
use App\Models\Role;
use App\Models\Specialty;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BeautyProfessionalManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_permission_seeder_provisions_professional_permissions_for_administrators(): void
    {
        [$company] = $this->companyContext('Permisos');
        $administrator = Role::create([
            'company_id' => $company->id,
            'name' => 'Administrador',
            'is_active' => true,
        ]);

        $this->seed(PermissionSeeder::class);

        $this->assertEqualsCanonicalizing(
            ['profesionales.ver', 'profesionales.crear', 'profesionales.editar', 'profesionales.eliminar'],
            $administrator->permissions()->where('module', 'BeautyOS')->pluck('name')->all()
        );
    }

    public function test_authorized_user_can_create_professional_with_company_branches_and_specialties(): void
    {
        [$company, $branch] = $this->companyContext('Beauty Uno');
        $secondBranch = $this->branch($company, 'Sucursal dos');
        $staff = $this->staff($company, $branch, ['profesionales.crear']);
        $professionalUser = $this->companyUser($company, $branch, 'Profesional Uno');
        $specialty = Specialty::create(['company_id' => $company->id, 'name' => 'Uñas']);

        $this->asCompany($staff, $company, $branch)
            ->post(route('professionals.store'), [
                'user_id' => $professionalUser->id,
                'branches' => [$branch->id, $secondBranch->id],
                'specialties' => [$specialty->id],
                'is_active' => '1',
            ])
            ->assertRedirect(route('professionals.index'));

        $professional = Professional::query()->where('company_id', $company->id)->sole();
        $this->assertSame($professionalUser->id, $professional->user_id);
        $this->assertEqualsCanonicalizing([$branch->id, $secondBranch->id], $professional->branches()->pluck('branches.id')->all());
        $this->assertSame([$specialty->id], $professional->specialties()->pluck('specialties.id')->all());
    }

    public function test_create_rejects_cross_company_user_branch_and_specialty(): void
    {
        [$company, $branch] = $this->companyContext('Beauty Uno');
        [$otherCompany, $otherBranch] = $this->companyContext('Beauty Dos');
        $staff = $this->staff($company, $branch, ['profesionales.crear']);
        $otherUser = $this->companyUser($otherCompany, $otherBranch, 'Usuario ajeno');
        $otherSpecialty = Specialty::create(['company_id' => $otherCompany->id, 'name' => 'Cabello']);

        $this->asCompany($staff, $company, $branch)
            ->from(route('professionals.create'))
            ->post(route('professionals.store'), [
                'user_id' => $otherUser->id,
                'branches' => [$otherBranch->id],
                'specialties' => [$otherSpecialty->id],
                'is_active' => '1',
            ])
            ->assertRedirect(route('professionals.create'))
            ->assertSessionHasErrors(['user_id', 'branches.0', 'specialties.0']);

        $this->assertDatabaseCount('professionals', 0);
    }

    public function test_index_is_company_scoped_and_can_filter_by_branch(): void
    {
        [$company, $branch] = $this->companyContext('Beauty Uno');
        $secondBranch = $this->branch($company, 'Sucursal dos');
        [$otherCompany, $otherBranch] = $this->companyContext('Beauty Dos');
        $staff = $this->staff($company, $branch, ['profesionales.ver']);

        $first = $this->professional($company, $branch, 'Ana Visible');
        $second = $this->professional($company, $secondBranch, 'Bea Oculta por filtro');
        $other = $this->professional($otherCompany, $otherBranch, 'Cora Otra Empresa');

        $this->asCompany($staff, $company, $branch)
            ->get(route('professionals.index', ['branch_id' => $branch->id]))
            ->assertOk()
            ->assertSee($first->user->name)
            ->assertDontSee($second->user->name)
            ->assertDontSee($other->user->name);
    }

    public function test_update_replaces_only_assignments_from_active_company(): void
    {
        [$company, $branch] = $this->companyContext('Beauty Uno');
        $secondBranch = $this->branch($company, 'Sucursal dos');
        $staff = $this->staff($company, $branch, ['profesionales.editar']);
        $professional = $this->professional($company, $branch, 'Profesional');
        $oldSpecialty = Specialty::create(['company_id' => $company->id, 'name' => 'Uñas']);
        $newSpecialty = Specialty::create(['company_id' => $company->id, 'name' => 'Pestañas']);
        $professional->assignSpecialty($oldSpecialty);

        $this->asCompany($staff, $company, $branch)
            ->put(route('professionals.update', $professional), [
                'user_id' => $professional->user_id,
                'branches' => [$secondBranch->id],
                'specialties' => [$newSpecialty->id],
                'is_active' => '0',
            ])
            ->assertRedirect(route('professionals.index'));

        $professional->refresh();
        $this->assertFalse($professional->is_active);
        $this->assertSame([$secondBranch->id], $professional->branches()->pluck('branches.id')->all());
        $this->assertSame([$newSpecialty->id], $professional->specialties()->pluck('specialties.id')->all());
    }

    public function test_cross_company_records_are_not_accessible(): void
    {
        [$company, $branch] = $this->companyContext('Beauty Uno');
        [$otherCompany, $otherBranch] = $this->companyContext('Beauty Dos');
        $staff = $this->staff($company, $branch, ['profesionales.ver', 'profesionales.editar', 'profesionales.eliminar']);
        $otherProfessional = $this->professional($otherCompany, $otherBranch, 'Profesional ajeno');

        $this->asCompany($staff, $company, $branch)
            ->get(route('professionals.show', $otherProfessional))
            ->assertNotFound();
        $this->asCompany($staff, $company, $branch)
            ->get(route('professionals.edit', $otherProfessional))
            ->assertNotFound();
        $this->asCompany($staff, $company, $branch)
            ->delete(route('professionals.destroy', $otherProfessional))
            ->assertNotFound();
    }

    public function test_destroy_removes_profile_but_preserves_core_user(): void
    {
        [$company, $branch] = $this->companyContext('Beauty Uno');
        $staff = $this->staff($company, $branch, ['profesionales.eliminar']);
        $professional = $this->professional($company, $branch, 'Profesional');
        $userId = $professional->user_id;

        $this->asCompany($staff, $company, $branch)
            ->delete(route('professionals.destroy', $professional))
            ->assertRedirect(route('professionals.index'));

        $this->assertDatabaseMissing('professionals', ['id' => $professional->id]);
        $this->assertDatabaseHas('users', ['id' => $userId]);
        $this->assertDatabaseHas('company_user', ['company_id' => $company->id, 'user_id' => $userId]);
    }

    public function test_permissions_protect_each_professional_operation(): void
    {
        [$company, $branch] = $this->companyContext('Beauty Uno');
        $staff = $this->staff($company, $branch, []);
        $professional = $this->professional($company, $branch, 'Profesional');

        $this->asCompany($staff, $company, $branch)->get(route('professionals.index'))->assertForbidden();
        $this->asCompany($staff, $company, $branch)->get(route('professionals.create'))->assertForbidden();
        $this->asCompany($staff, $company, $branch)->get(route('professionals.edit', $professional))->assertForbidden();
        $this->asCompany($staff, $company, $branch)->delete(route('professionals.destroy', $professional))->assertForbidden();
    }

    private function companyContext(string $name): array
    {
        $company = Company::create([
            'trade_name' => $name.' '.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);

        return [$company, $this->branch($company, 'Principal')];
    }

    private function branch(Company $company, string $name): Branch
    {
        return Branch::create([
            'company_id' => $company->id,
            'name' => $name,
            'code' => strtoupper(substr(uniqid(), -6)),
            'is_active' => true,
        ]);
    }

    private function companyUser(Company $company, Branch $branch, string $name): User
    {
        $user = User::factory()->create(['name' => $name, 'is_active' => true]);
        $user->companies()->attach($company->id);
        $user->branches()->attach($branch->id);

        return $user;
    }

    private function staff(Company $company, Branch $branch, array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create([
            'company_id' => $company->id,
            'name' => 'Rol '.uniqid(),
            'is_active' => true,
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::firstOrCreate(
                ['name' => $permissionName],
                ['label' => $permissionName, 'module' => 'BeautyOS', 'is_active' => true]
            );
            $role->permissions()->attach($permission);
        }

        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return $user;
    }

    private function professional(Company $company, Branch $branch, string $name): Professional
    {
        $user = $this->companyUser($company, $branch, $name);
        $professional = Professional::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'is_active' => true,
        ]);
        $professional->assignBranch($branch);

        return $professional->load('user');
    }

    private function asCompany(User $user, Company $company, Branch $branch): static
    {
        return $this->actingAs($user)->withSession([
            'active_company_id' => $company->id,
            'active_branch_id' => $branch->id,
        ]);
    }
}
