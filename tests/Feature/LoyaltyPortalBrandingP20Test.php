<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\LoyaltyPortalSetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyPortalBrandingP20Test extends TestCase
{
    use RefreshDatabase;

    public function test_public_portal_shows_company_logo_name_colors_and_mvs_identity(): void
    {
        [$company] = $this->context();
        $company->update(['logo' => 'companies/demo-logo.png']);
        LoyaltyPortalSetting::create(['company_id' => $company->id, 'is_active' => true, 'show_active_offers' => true, 'brand_primary_color' => '#123456', 'brand_accent_color' => '#ABCDEF']);

        $response = $this->get(route('loyalty.customer.login', $company))->assertOk();
        $response->assertSee($company->trade_name);
        $response->assertSee('storage/companies/demo-logo.png', false);
        $response->assertSee('--portal-primary:#123456', false);
        $response->assertSee('--portal-accent:#ABCDEF', false);
        $response->assertSee('MVS Commerce');
    }

    public function test_brand_colors_are_validated_and_isolated_by_company(): void
    {
        [$companyA, $branchA, $userA] = $this->context(['fidelidad.portal.configurar']);
        [$companyB] = $this->context();
        LoyaltyPortalSetting::create(['company_id' => $companyB->id, 'is_active' => true, 'brand_primary_color' => '#111111', 'brand_accent_color' => '#222222']);
        $session = ['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id];

        $this->actingAs($userA)->withSession($session)->put(route('loyalty.portal-management.settings.update'), [
            'is_active' => 1, 'brand_primary_color' => '#334455', 'brand_accent_color' => '#DDAA00',
        ])->assertRedirect();
        $this->assertDatabaseHas('loyalty_portal_settings', ['company_id' => $companyA->id, 'brand_primary_color' => '#334455', 'brand_accent_color' => '#DDAA00']);
        $this->assertDatabaseHas('loyalty_portal_settings', ['company_id' => $companyB->id, 'brand_primary_color' => '#111111', 'brand_accent_color' => '#222222']);

        $this->put(route('loyalty.portal-management.settings.update'), ['brand_primary_color' => 'red', 'brand_accent_color' => '#000000'])->assertSessionHasErrors('brand_primary_color');
    }

    public function test_management_qr_card_contains_company_identity_and_brand_colors(): void
    {
        [$company, $branch, $user] = $this->context(['fidelidad.portal.ver']);
        LoyaltyPortalSetting::create(['company_id' => $company->id, 'is_active' => true, 'brand_primary_color' => '#102030', 'brand_accent_color' => '#F0A000']);

        $response = $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('loyalty.portal-management.index'))->assertOk();
        $response->assertSee('portal-qr-brand-card', false);
        $response->assertSee($company->trade_name);
        $response->assertSee('#102030', false);
        $response->assertSee('#F0A000', false);
        $response->assertSee('Imprimir QR');
    }

    public function test_default_branding_keeps_mvs_palette_when_company_has_no_custom_colors(): void
    {
        [$company] = $this->context();
        $response = $this->get(route('loyalty.customer.register', $company))->assertOk();
        $response->assertSee('--portal-primary:#0F172A', false);
        $response->assertSee('--portal-accent:#F59E0B', false);
        $response->assertSee('MVS Commerce');
    }

    private function context(array $permissions = []): array
    {
        $company = Company::create(['trade_name' => 'Empresa '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'BR'.uniqid(), 'is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Fidelidad', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return [$company, $branch, $user];
    }
}
