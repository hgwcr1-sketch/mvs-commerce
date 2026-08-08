<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyAllowance;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CompanyProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_provisions_an_administrator_role_permissions_and_primary_branch(): void
    {
        $permission = $this->createTransferPermission();
        $owner = User::factory()->create();

        $company = app(CompanyProvisioner::class)->provision(
            $owner,
            ['trade_name' => 'Empresa inicial'],
        );

        $role = Role::where('company_id', $company->id)
            ->where('name', 'Administrador')
            ->firstOrFail();
        $branch = Branch::where('company_id', $company->id)
            ->where('code', 'PRINCIPAL')
            ->firstOrFail();

        $this->assertSame($owner->id, $company->owner_user_id);
        $this->assertTrue($role->permissions->contains($permission));
        $this->assertSame($role->id, $company->users()->firstOrFail()->pivot->role_id);
        $this->assertTrue($owner->fresh()->branches->contains($branch));
        $this->assertTrue($owner->fresh()->hasPermission('inventario.transferir', $company));
        $this->assertSame(1, CompanyAllowance::where('user_id', $owner->id)->value('allowed_companies'));
    }

    public function test_initial_installation_creates_the_administrator_inside_the_provisioning_flow(): void
    {
        $this->createTransferPermission();

        $company = app(CompanyProvisioner::class)->install(
            [
                'name' => 'Administradora inicial',
                'email' => 'admin@example.test',
                'password' => 'segura-para-prueba',
            ],
            ['trade_name' => 'Empresa instalacion'],
        );

        $owner = User::where('email', 'admin@example.test')->firstOrFail();

        $this->assertSame($owner->id, $company->owner_user_id);
        $this->assertTrue($owner->hasPermission('inventario.transferir', $company));
        $this->assertDatabaseHas('branch_user', ['user_id' => $owner->id]);
    }

    public function test_administrator_roles_and_branch_assignments_are_isolated_per_company(): void
    {
        $this->createTransferPermission();
        $owner = User::factory()->create();
        $provisioner = app(CompanyProvisioner::class);

        $firstCompany = $provisioner->provision(
            $owner,
            ['trade_name' => 'Empresa uno'],
            'Principal uno',
            'PRINCIPAL-1',
            2,
        );
        $secondCompany = $provisioner->provision(
            $owner,
            ['trade_name' => 'Empresa dos'],
            'Principal dos',
            'PRINCIPAL-2',
        );

        $firstRoleId = $owner->companies()->findOrFail($firstCompany->id)->pivot->role_id;
        $secondRoleId = $owner->companies()->findOrFail($secondCompany->id)->pivot->role_id;

        $this->assertNotSame($firstRoleId, $secondRoleId);
        $this->assertSame($firstCompany->id, Role::findOrFail($firstRoleId)->company_id);
        $this->assertSame($secondCompany->id, Role::findOrFail($secondRoleId)->company_id);
        $this->assertCount(2, $owner->fresh()->branches);
    }

    public function test_provisioning_rejects_companies_over_the_owner_allowance(): void
    {
        $this->createTransferPermission();
        $owner = User::factory()->create();
        $provisioner = app(CompanyProvisioner::class);

        $provisioner->provision($owner, ['trade_name' => 'Empresa permitida']);

        $this->expectException(ValidationException::class);

        $provisioner->provision($owner, ['trade_name' => 'Empresa bloqueada']);
    }

    public function test_initial_installation_command_refuses_to_duplicate_an_existing_tenant(): void
    {
        Company::create([
            'trade_name' => 'Empresa existente',
            'is_active' => true,
        ]);

        $this->artisan('mvs:install')
            ->expectsOutput('La instalación inicial ya fue realizada. No se crearán empresas adicionales.')
            ->assertExitCode(1);

        $this->assertSame(1, Company::count());
    }

    public function test_provisioned_administrator_can_access_transfers_through_the_real_middleware(): void
    {
        $this->createTransferPermission();

        $company = app(CompanyProvisioner::class)->install(
            [
                'name' => 'Administrador de transferencias',
                'email' => 'transferencias@example.test',
                'password' => 'segura-para-prueba',
            ],
            ['trade_name' => 'Empresa transferencias'],
        );
        $administrator = User::where('email', 'transferencias@example.test')->firstOrFail();
        $branch = Branch::where('company_id', $company->id)->firstOrFail();

        $this->assertTrue($administrator->hasPermission('inventario.transferir', $company));

        $this->actingAs($administrator)
            ->withSession([
                'active_company_id' => $company->id,
                'active_branch_id' => $branch->id,
            ])
            ->get('/transferencias')
            ->assertOk();
    }

    private function createTransferPermission(): Permission
    {
        return Permission::create([
            'name' => 'inventario.transferir',
            'label' => 'Realizar transferencias',
            'module' => 'Inventario',
            'is_active' => true,
        ]);
    }
}
