<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\PhoneNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternationalPhoneAndWhatsAppSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_customer_uses_company_default_and_normalizes_phone(): void
    {
        [$company, $branch, $user] = $this->context('+506');

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('clientes.store'), $this->customerPayload([
                'phone' => '8352-6142',
            ]))
            ->assertRedirect(route('clientes.index'));

        $customer = Customer::query()->where('company_id', $company->id)->firstOrFail();
        $this->assertSame('+506', $customer->phone_country_code);
        $this->assertSame('83526142', $customer->phone);
    }

    public function test_foreign_phone_can_be_saved_and_editing_preserves_values(): void
    {
        [$company, $branch, $user] = $this->context('+506');
        $customer = Customer::create($this->customerPayload([
            'company_id' => $company->id,
            'phone_country_code' => '+34',
            'phone' => '612345678',
        ]));

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->put(route('clientes.update', $customer), $this->customerPayload([
                'name' => 'Cliente editado',
                'phone_country_code' => '+34',
                'phone' => '612 345 678',
            ]))
            ->assertRedirect(route('clientes.index'));

        $customer->refresh();
        $this->assertSame('+34', $customer->phone_country_code);
        $this->assertSame('612345678', $customer->phone);
    }

    public function test_customer_without_phone_is_valid_and_legacy_phone_is_not_migrated(): void
    {
        [$company, $branch, $user] = $this->context('+506');
        $legacy = Customer::create($this->customerPayload([
            'company_id' => $company->id,
            'phone' => '2222-3333',
        ]));

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('clientes.store'), $this->customerPayload())
            ->assertRedirect(route('clientes.index'));

        $this->assertSame('2222-3333', $legacy->fresh()->phone);
        $this->assertNull($legacy->fresh()->phone_country_code);
        $this->assertDatabaseHas('customers', [
            'company_id' => $company->id,
            'phone' => null,
            'phone_country_code' => null,
        ]);
    }

    public function test_phone_number_service_builds_whatsapp_numbers(): void
    {
        $service = app(PhoneNumberService::class);
        $customer = new Customer(['phone_country_code' => '+506', 'phone' => '8352-6142']);

        $this->assertSame('50683526142', $service->forWhatsApp('+506', '8352-6142'));
        $this->assertSame('50683526142', $service->forCustomer($customer));
        $this->assertSame('34612345678', $service->forWhatsApp('+34', '(612) 345 678'));
        $this->assertNull($service->forWhatsApp('+506', null));
    }

    public function test_whatsapp_settings_are_normalized_and_isolated_by_company(): void
    {
        [$companyA, $branchA, $userA] = $this->context('+506');
        [$companyB] = $this->context('+34');

        $this->actingAs($userA)->withSession($this->activeSession($companyA, $branchA))
            ->put(route('configuracion.whatsapp.update'), [
                'whatsapp_enabled' => '1',
                'default_phone_country_code' => '+1',
                'whatsapp_phone_country_code' => '+506',
                'whatsapp_phone' => '2222-3333',
            ])
            ->assertRedirect(route('configuracion.index'));

        $companyA->refresh();
        $this->assertTrue($companyA->whatsapp_enabled);
        $this->assertSame('+1', $companyA->default_phone_country_code);
        $this->assertSame('+506', $companyA->whatsapp_phone_country_code);
        $this->assertSame('22223333', $companyA->whatsapp_phone);
        $this->assertSame('+34', $companyB->fresh()->default_phone_country_code);
        $this->assertFalse($companyB->fresh()->whatsapp_enabled);
    }

    public function test_whatsapp_can_be_disabled_and_invalid_numbers_are_rejected(): void
    {
        [$company, $branch, $user] = $this->context('+506');
        $session = $this->activeSession($company, $branch);

        $this->actingAs($user)->withSession($session)
            ->put(route('configuracion.whatsapp.update'), [
                'whatsapp_enabled' => '0',
                'default_phone_country_code' => '+506',
                'whatsapp_phone_country_code' => '+506',
                'whatsapp_phone' => '8352-6142',
            ])->assertRedirect(route('configuracion.index'));

        $this->assertFalse($company->fresh()->whatsapp_enabled);

        $this->actingAs($user)->withSession($session)
            ->from(route('configuracion.index'))
            ->put(route('configuracion.whatsapp.update'), [
                'whatsapp_enabled' => '1',
                'default_phone_country_code' => '506x',
                'whatsapp_phone_country_code' => '+12345',
                'whatsapp_phone' => '12AB',
            ])->assertSessionHasErrors([
                'default_phone_country_code',
                'whatsapp_phone_country_code',
                'whatsapp_phone',
            ]);
    }

    private function context(string $defaultCountryCode): array
    {
        $company = Company::create([
            'trade_name' => 'Empresa '.uniqid(),
            'default_phone_country_code' => $defaultCountryCode,
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
        $branch = Branch::create([
            'company_id' => $company->id,
            'name' => 'Principal',
            'code' => strtoupper(substr(uniqid(), -6)),
            'is_active' => true,
        ]);
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Configurador '.uniqid(), 'is_active' => true]);

        foreach (['configuracion.ver', 'configuracion.editar'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Configuración', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }

        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return [$company, $branch, $user];
    }

    private function customerPayload(array $overrides = []): array
    {
        return array_merge([
            'customer_type' => 'individual',
            'name' => 'Cliente prueba',
            'credit_limit' => 0,
            'price_level' => 'normal',
            'is_active' => true,
        ], $overrides);
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
