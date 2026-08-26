<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CompanyOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_onboards_complete_company_with_multiple_branches_admin_modules_and_defaults(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        Permission::create(['name' => 'pos.acceder', 'label' => 'POS', 'module' => 'POS', 'is_active' => true]);

        $response = $this->actingAs($admin)->post(route('platform.companies.store'), $this->payload([
            'logo' => UploadedFile::fake()->image('logo.png'),
        ]));

        $company = Company::where('identification_number', '3101123456')->firstOrFail();
        $response->assertRedirect(route('platform.companies.show', $company));
        $this->assertCount(2, $company->branches);
        $this->assertSame(['LIB', 'SJO'], $company->branches()->orderBy('code')->pluck('code')->all());
        $owner = User::where('email', 'tenant-admin@example.test')->firstOrFail();
        $this->assertSame($owner->id, $company->owner_user_id);
        $this->assertTrue($owner->hasPermission('pos.acceder', $company));
        $this->assertTrue($company->isModuleEnabled('sales'));
        $this->assertFalse($company->isModuleEnabled('loyalty'));
        $this->assertDatabaseHas('company_cash_settings', ['company_id' => $company->id]);
        $this->assertDatabaseCount('payment_methods', 5);
        Storage::disk('public')->assertExists($company->logo);
    }

    public function test_onboarding_rejects_duplicate_company_identity_admin_email_and_branch_codes(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        Company::create(['trade_name' => 'Existente', 'identification_number' => '3101123456', 'is_active' => true]);
        User::factory()->create(['email' => 'tenant-admin@example.test']);
        $payload = $this->payload();
        $payload['branches'][1]['code'] = 'SJO';

        $this->actingAs($admin)->from(route('platform.companies.create'))->post(route('platform.companies.store'), $payload)
            ->assertRedirect(route('platform.companies.create'))
            ->assertSessionHasErrors(['identification_number', 'administrator.email', 'branches.1.code']);
        $this->assertSame(1, Company::count());
    }

    public function test_onboarding_rolls_back_database_and_logo_when_provisioning_fails(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);

        $this->actingAs($admin)->post(route('platform.companies.store'), $this->payload([
            'logo' => UploadedFile::fake()->image('rollback.png'),
        ]))->assertSessionHasErrors('permissions');

        $this->assertDatabaseMissing('companies', ['identification_number' => '3101123456']);
        $this->assertDatabaseMissing('users', ['email' => 'tenant-admin@example.test']);
        $this->assertSame([], Storage::disk('public')->allFiles('companies'));
    }

    public function test_onboarding_requires_platform_admin_and_is_mobile_first(): void
    {
        $regular = User::factory()->create(['is_active' => true]);
        $this->actingAs($regular)->get(route('platform.companies.create'))->assertForbidden();
        $regular->update(['is_platform_admin' => true]);
        $this->actingAs($regular->fresh())->get(route('platform.companies.create'))->assertOk()
            ->assertSee('grid grid-cols-4 gap-2', false)
            ->assertSee('sm:grid-cols-7', false)->assertSee('md:grid-cols-2', false)
            ->assertSee('min-h-11', false)->assertSee('Nada se guarda hasta finalizar');
    }

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'trade_name' => 'Nueva Empresa', 'legal_name' => 'Nueva Empresa S.A.',
            'identification_type' => '02', 'identification_number' => '3101123456',
            'email' => 'info@tenant.test', 'phone' => '2222-2222', 'address' => 'Costa Rica',
            'currency' => 'CRC', 'timezone' => 'America/Costa_Rica',
            'branches' => [
                ['name' => 'Principal', 'code' => 'SJO', 'phone' => '2222-1111', 'address' => 'San José'],
                ['name' => 'Segunda', 'code' => 'LIB', 'phone' => '2666-1111', 'address' => 'Liberia'],
            ],
            'administrator' => [
                'name' => 'Admin Tenant', 'email' => 'tenant-admin@example.test', 'phone' => '8888-8888',
                'password' => 'SecureAdmin9', 'password_confirmation' => 'SecureAdmin9',
            ],
            'modules' => ['sales', 'inventory', 'administration'],
        ], $overrides);
    }
}
