<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyPortalCentralTest extends TestCase
{
    use RefreshDatabase;

    public function test_central_shows_portal_url_qr_copy_and_preview(): void
    {
        [$company, $branch, $user] = $this->context(['fidelidad.portal.ver']);
        $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('loyalty.portal-management.index'));
        $response->assertOk();
        $portalUrl = route('loyalty.customer.login', $company);
        $response->assertSee($portalUrl);
        $response->assertSee('Acceso general al Portal');
        $response->assertSee('Copiar URL');
        $response->assertSee('Vista previa');
        $response->assertSee('<svg', false); // QR SVG
        $response->assertSee('QR del Portal');
        $response->assertSee('portal-clientes/' . $company->id . '/ingresar', false);
    }

    public function test_portal_url_isolated_per_company(): void
    {
        [$companyA, $branchA, $userA] = $this->context(['fidelidad.portal.ver']);
        [$companyB, $branchB] = $this->contextPair('Empresa B');
        $userB = $this->userForCompany($companyB, $branchB, ['fidelidad.portal.ver']);

        $respA = $this->actingAs($userA)->withSession($this->activeSession($companyA, $branchA))
            ->get(route('loyalty.portal-management.index'));
        $respB = $this->actingAs($userB)->withSession($this->activeSession($companyB, $branchB))
            ->get(route('loyalty.portal-management.index'));

        $urlA = route('loyalty.customer.login', $companyA);
        $urlB = route('loyalty.customer.login', $companyB);
        $this->assertNotSame($urlA, $urlB);
        $respA->assertSee($urlA);
        $respA->assertDontSee($urlB);
        $respB->assertSee($urlB);
        $respB->assertDontSee($urlA);
    }

    public function test_without_permission_cannot_see_central(): void
    {
        [$company, $branch, $user] = $this->context([]);
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('loyalty.portal-management.index'))->assertForbidden();
    }

    public function test_central_is_responsive_and_contains_company_id(): void
    {
        [$company, $branch, $user] = $this->context(['fidelidad.portal.ver']);
        $html = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('loyalty.portal-management.index'))->assertOk()->getContent();
        $this->assertStringContainsString('portal-clientes/' . $company->id . '/ingresar', $html);
        $this->assertStringContainsString('Imprimir QR', $html);
    }

    private function context(array $perms, ?Company $company = null, ?Branch $branch = null): array
    {
        $company ??= Company::create(['trade_name' => 'Empresa ' . uniqid(), 'legal_name' => 'Empresa', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch ??= Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P' . uniqid(), 'is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol ' . uniqid(), 'is_active' => true]);
        foreach ($perms as $name) {
            $perm = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Fidelidad', 'is_active' => true]);
            $role->permissions()->attach($perm);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        return [$company, $branch, $user];
    }

    private function contextPair(string $name): array
    {
        $company = Company::create(['trade_name' => $name . ' ' . uniqid(), 'legal_name' => $name, 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P' . uniqid(), 'is_active' => true]);
        return [$company, $branch];
    }

    private function userForCompany(Company $company, Branch $branch, array $perms): User
    {
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol ' . uniqid(), 'is_active' => true]);
        foreach ($perms as $name) {
            $perm = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Fidelidad', 'is_active' => true]);
            $role->permissions()->attach($perm);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        return $user;
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
