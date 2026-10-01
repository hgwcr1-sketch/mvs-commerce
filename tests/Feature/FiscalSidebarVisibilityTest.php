<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyLicense;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyLicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalSidebarVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $company = Company::create([
            'trade_name' => 'FE' . uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
        $branch = Branch::create([
            'company_id' => $company->id,
            'name' => 'Principal',
            'code' => 'P' . uniqid(),
            'is_active' => true,
        ]);

        return [$company, $branch];
    }

    private function actingUser(Company $company, Branch $branch, array $permissions, bool $superAdmin = false): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create([
            'company_id' => $company->id,
            'name' => 'R' . uniqid(),
            'is_active' => true,
            'is_super_admin' => $superAdmin,
        ]);

        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['label' => $name, 'module' => 'Facturación Electrónica', 'is_active' => true],
            );
            $role->permissions()->attach($permission->id);
        }

        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        $this->actingAs($user)->withSession([
            'active_company_id' => $company->id,
            'active_branch_id' => $branch->id,
        ]);

        return $user;
    }

    private function enableFiscal(Company $company, bool $enabled = true): void
    {
        app(CompanyLicenseService::class)->ensure($company);
        CompanyLicense::query()->where('company_id', $company->id)
            ->update(['fiscal_enabled' => $enabled, 'fiscal_monthly_quota' => 50]);
    }

    public function test_sidebar_shows_fiscal_when_company_enabled_and_user_has_permission(): void
    {
        [$company, $branch] = $this->context();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch, ['fiscal.ver']);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Facturación Electrónica');
    }

    public function test_sidebar_shows_fiscal_for_super_admin_role(): void
    {
        [$company, $branch] = $this->context();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch, [], true);

        $this->assertTrue(
            Permission::query()->where('name', 'fiscal.ver')->where('is_active', true)->exists(),
            'Las migraciones deben garantir el permiso fiscal.ver en la base'
        );

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Facturación Electrónica');
    }

    public function test_sidebar_hides_fiscal_without_permission(): void
    {
        [$company, $branch] = $this->context();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch, ['dashboard.ver']);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Facturación Electrónica');
    }

    public function test_sidebar_hides_fiscal_when_company_disabled(): void
    {
        [$company, $branch] = $this->context();
        $this->enableFiscal($company, false);
        $this->actingUser($company, $branch, ['fiscal.ver']);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Facturación Electrónica');
    }
}
