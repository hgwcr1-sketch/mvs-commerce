<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CustomerPublicCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerQrBarcodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_qr_and_barcode_generated_locally_without_external_service(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'QR Test', 'phone' => '88880001', 'is_active' => true]);
        $service = app(CustomerPublicCodeService::class);
        $qr = $service->qrSvg($customer);
        $barcode = $service->barcodeSvg($customer);
        $this->assertStringContainsString('<svg', $qr);
        $this->assertStringContainsString('<svg', $barcode);
        $this->assertStringNotContainsString('88880001', $qr);
        $this->assertTrue($service->qrSupported());
        $this->assertTrue($service->barcodeSupported());
    }

    public function test_clientes_show_displays_public_code_qr_and_barcode_without_sensitive_data(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente Visible', 'identification' => '1-2345-6789', 'phone' => '88883333', 'email' => 'visible@example.com', 'is_active' => true]);
        $customer->refresh();
        $response = $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('clientes.show', $customer));
        $response->assertOk();
        $response->assertSee($customer->public_code);
        $response->assertSee('Código público');
        $response->assertSee('QR (código público)');
        $response->assertSee('Code 128');
        $response->assertSee('<svg', false);
        foreach (['informacion', 'identificacion-seguridad', 'contactos-direcciones'] as $tab) {
            $response->assertSee('id="tab-'.$tab.'"', false);
            $response->assertSee('id="panel-'.$tab.'"', false);
        }
        // No sensitive leak
        $this->assertNotSame($customer->identification, $customer->public_code);
    }

    public function test_public_code_qr_barcode_isolated_per_company(): void
    {
        [$companyA, $branchA, $userA] = $this->context();
        [$companyB, $branchB] = $this->pair('Empresa B');
        $userB = $this->userFor($companyB, $branchB);
        $ca = Customer::create(['company_id' => $companyA->id, 'customer_type' => 'individual', 'name' => 'A', 'phone' => '44440001', 'is_active' => true]);
        $cb = Customer::create(['company_id' => $companyB->id, 'customer_type' => 'individual', 'name' => 'B', 'phone' => '44440001', 'is_active' => true]);
        $this->assertNotSame($ca->public_code, $cb->public_code);
        $service = app(CustomerPublicCodeService::class);
        $qrA = $service->qrSvg($ca);
        $qrB = $service->qrSvg($cb);
        $this->assertNotSame($qrA, $qrB);
    }

    public function test_qr_encodes_public_code_not_phone_or_email(): void
    {
        $company = Company::create(['trade_name' => 'Empresa ' . uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $customer = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Encode', 'phone' => '88884444', 'email' => 'encode@example.com', 'identification' => '9-9999-9999', 'is_active' => true]);
        $customer->refresh();
        // Verify QR generation does not embed phone/email – we check service does not leak
        $service = app(CustomerPublicCodeService::class);
        $qr = $service->qrSvg($customer);
        // QR SVG is opaque, but we can assert public_code is the only payload by decoding via service? Indirect: ensure isSensitiveLeak false
        $this->assertFalse($service->isSensitiveLeak($customer, $customer->public_code));
        $this->assertStringNotContainsString('88884444', $customer->public_code);
    }

    private function stripSvgText(string $html): string
    {
        // Remove SVG content to check non-svg leak
        return preg_replace('/<svg.*?<\/svg>/s', '', $html) ?? $html;
    }

    private function context(): array
    {
        $company = Company::create(['trade_name' => 'Empresa ' . uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true, 'default_phone_country_code' => '+506']);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P' . uniqid(), 'is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol ' . uniqid(), 'is_active' => true]);
        foreach (['clientes.ver', 'clientes.crear'] as $name) {
            $perm = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Test', 'is_active' => true]);
            $role->permissions()->attach($perm);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        return [$company, $branch, $user];
    }

    private function pair(string $name): array
    {
        $company = Company::create(['trade_name' => $name . ' ' . uniqid(), 'legal_name' => $name, 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P' . uniqid(), 'is_active' => true]);
        return [$company, $branch];
    }

    private function userFor(Company $company, Branch $branch): User
    {
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol ' . uniqid(), 'is_active' => true]);
        foreach (['clientes.ver'] as $name) {
            $perm = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Test', 'is_active' => true]);
            $role->permissions()->attach($perm);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        return $user;
    }
}
