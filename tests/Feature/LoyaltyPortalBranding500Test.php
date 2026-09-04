<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyPortalCredential;
use App\Models\LoyaltyPortalSetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoyaltyPortalBranding500Test extends TestCase
{
    use RefreshDatabase;

    public function test_login_renders_portal_without_undefined_branding(): void
    {
        [$company, $branch, $customer, $credential] = $this->portalContext();

        // Simula login real del portal
        $response = $this->post(route('loyalty.customer.login.store', $company), [
            'username' => $credential->username,
            'password' => 'Secret123',
        ]);
        $response->assertRedirect(route('loyalty.customer.home', $company));

        // Debe cargar el portal autenticado sin 500
        $this->get(route('loyalty.customer.home', $company))
            ->assertOk()
            ->assertSee($company->trade_name)
            ->assertSee('Programa de fidelización');
    }

    public function test_force_change_and_recovery_pages_have_branding(): void
    {
        [$company] = $this->companyContext();
        // Páginas públicas que antes fallaban sin portalBranding
        $this->get(route('loyalty.customer.password.request', $company))->assertOk()->assertSee($company->trade_name);
        $this->get(route('loyalty.customer.password.force', $company))->assertStatus(403); // sin sesión debe dar 403, no 500
    }

    private function portalContext(): array
    {
        [$company, $branch] = $this->companyContext();
        $customer = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente Portal', 'is_active' => true]);
        LoyaltyPortalSetting::firstOrCreate(['company_id' => $company->id], ['is_active' => true, 'show_active_offers' => true, 'brand_primary_color' => '#123456', 'brand_accent_color' => '#FEDCBA']);
        $credential = LoyaltyPortalCredential::create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'username' => 'cliente_portal',
            'email' => 'cliente@portal.test',
            'password' => Hash::make('Secret123'),
            'is_active' => true,
            'must_change_password' => false,
        ]);

        return [$company, $branch, $customer, $credential];
    }

    private function companyContext(): array
    {
        $company = Company::create(['trade_name' => 'Empresa '.uniqid(), 'legal_name' => 'Empresa', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.uniqid(), 'is_active' => true]);

        return [$company, $branch];
    }
}
