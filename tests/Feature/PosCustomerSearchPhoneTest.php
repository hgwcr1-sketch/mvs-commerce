<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyCashSettingsProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosCustomerSearchPhoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_search_matches_multiple_words_in_any_order_case_and_accents_insensitive(): void
    {
        [$company, $branch, $user] = $this->context(true);
        $customer = $this->customer($company, ['name' => 'María Fernanda López González']);

        foreach (['gonzález maría', 'MARÍA LOPEZ', 'maria gonzalez', 'María'] as $term) {
            $this->searchCustomers($user, $company, $branch, $term)
                ->assertOk()
                ->assertJsonPath('0.id', $customer->id);
        }

        $this->searchCustomers($user, $company, $branch, 'maría inexistente')
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_customer_search_matches_partial_surname_and_identification(): void
    {
        [$company, $branch, $user] = $this->context(true);
        $bySurname = $this->customer($company, ['name' => 'Juan Ramírez Solís', 'identification' => '1-2345-6789']);

        $this->searchCustomers($user, $company, $branch, 'ramirez')
            ->assertOk()
            ->assertJsonPath('0.id', $bySurname->id);

        $this->searchCustomers($user, $company, $branch, '2345')
            ->assertOk()
            ->assertJsonPath('0.id', $bySurname->id);
    }

    public function test_customer_search_matches_phone_digits_without_separators(): void
    {
        [$company, $branch, $user] = $this->context(true);
        $customer = $this->customer($company, ['name' => 'Cliente Teléfono', 'phone' => '2222-3333', 'mobile' => '8888 9999']);

        foreach (['22223333', '2222 3333', '88889999', '3333'] as $term) {
            $this->searchCustomers($user, $company, $branch, $term)
                ->assertOk()
                ->assertJsonPath('0.id', $customer->id);
        }
    }

    public function test_customer_search_keeps_company_scope_inactive_exclusion_and_permission(): void
    {
        [$company, $branch, $user] = $this->context(true);
        [$otherCompany] = $this->context(false);

        $visible = $this->customer($company, ['name' => 'Ana Torres Vargas', 'phone' => '2200-1100']);
        $this->customer($company, ['name' => 'Ana Torres Inactiva', 'is_active' => false]);
        $this->customer($otherCompany, ['name' => 'Ana Torres Ajena']);

        $this->searchCustomers($user, $company, $branch, 'torres ANA')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $visible->id)
            ->assertJsonPath('0.phone', '2200-1100');

        [$denyCompany, $denyBranch, $denyUser] = $this->context(false);

        $this->actingAs($denyUser)
            ->withSession($this->activeSession($denyCompany, $denyBranch))
            ->getJson(route('pos.customers.search', ['q' => 'Ana Torres']))
            ->assertForbidden();
    }

    public function test_pos_customer_phone_endpoint_updates_phone_within_company(): void
    {
        [$company, $branch, $user] = $this->context(true);
        $this->grantPermission($user, $company, 'clientes.editar');
        $customer = $this->customer($company, ['name' => 'Cliente sin teléfono']);

        $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->patchJson(route('pos.customers.update-phone', ['cliente' => $customer->id]), ['phone' => '2222 3333'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('customer.phone', '22223333');

        $this->assertSame('22223333', $customer->fresh()->phone);
    }

    public function test_pos_customer_phone_endpoint_rejects_invalid_phone_and_enforces_permissions_and_company(): void
    {
        [$company, $branch, $user] = $this->context(true);
        $this->grantPermission($user, $company, 'clientes.editar');
        $customer = $this->customer($company, ['name' => 'Cliente Validado']);

        $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->patchJson(route('pos.customers.update-phone', ['cliente' => $customer->id]), ['phone' => '12'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);

        $this->assertNull($customer->fresh()->phone);

        [$otherCompany] = $this->context(false);
        $foreign = $this->customer($otherCompany, ['name' => 'Cliente ajeno']);

        $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->patchJson(route('pos.customers.update-phone', ['cliente' => $foreign->id]), ['phone' => '22223333'])
            ->assertNotFound();

        [$denyCompany, $denyBranch, $denyUser] = $this->context(true);

        $this->actingAs($denyUser)
            ->withSession($this->activeSession($denyCompany, $denyBranch))
            ->patchJson(route('pos.customers.update-phone', ['cliente' => $customer->id]), ['phone' => '22223333'])
            ->assertForbidden();
    }

    public function test_pos_screen_exposes_phone_capture_flow_without_blocking_generic_customer(): void
    {
        [$company, $branch, $user] = $this->context(true);
        $this->paymentMethod($company, 'Efectivo', 'cash', true);
        $this->grantPermission($user, $company, 'clientes.editar');

        $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->get(route('pos.index'))
            ->assertOk()
            ->assertSee('Agregar teléfono')
            ->assertSee('phoneCaptureRequired()', false)
            ->assertSee('openPhoneModal(', false)
            ->assertSee('Consumidor Final');
    }

    private function context(bool $withPermission): array
    {
        $company = Company::create(['trade_name' => 'Empresa '.uniqid(), 'is_active' => true]);
        $branch = Branch::create([
            'company_id' => $company->id,
            'name' => 'Principal '.$company->id,
            'code' => 'P-'.$company->id,
            'is_active' => true,
        ]);
        $user = User::factory()->create();
        $role = Role::create([
            'company_id' => $company->id,
            'name' => 'Rol '.uniqid(),
            'is_active' => true,
        ]);
        if ($withPermission) {
            $permission = Permission::firstOrCreate(
                ['name' => 'pos.acceder'],
                ['label' => 'Acceder al POS', 'module' => 'POS', 'is_active' => true],
            );
            $role->permissions()->attach($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        app(CompanyCashSettingsProvisioner::class)->provision($company);
        if ($withPermission) {
            $register = CashRegister::create([
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'code' => 'CAJA-'.$company->id,
                'name' => 'Caja principal',
                'is_active' => true,
            ]);
            CashSession::create([
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'cash_register_id' => $register->id,
                'session_number' => 'CAJA-'.$company->id,
                'opened_by' => $user->id,
                'status' => CashSession::STATUS_OPEN,
                'open_guard' => CashSession::OPEN_GUARD,
                'opening_amount' => 0,
                'opened_at' => now(),
            ]);
        }

        return [$company, $branch, $user];
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }

    private function searchCustomers(User $user, Company $company, Branch $branch, string $query)
    {
        return $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->getJson(route('pos.customers.search', ['q' => $query]));
    }

    private function grantPermission(User $user, Company $company, string $name): void
    {
        $permission = Permission::firstOrCreate(
            ['name' => $name],
            ['label' => $name, 'module' => 'Clientes', 'is_active' => true],
        );
        $user->roleInCompany($company)->permissions()->syncWithoutDetaching($permission);
    }

    private function paymentMethod(Company $company, string $name, string $code, bool $active): PaymentMethod
    {
        return PaymentMethod::create([
            'company_id' => $company->id,
            'name' => $name,
            'code' => $code,
            'type' => PaymentMethod::TYPE_OTHER,
            'is_system' => false,
            'is_active' => $active,
            'affects_cash' => false,
            'requires_reference' => false,
            'allows_change' => false,
            'sort_order' => 10,
        ]);
    }

    private function customer(Company $company, array $attributes = []): Customer
    {
        $suffix = uniqid();

        return Customer::create(array_merge([
            'company_id' => $company->id,
            'customer_type' => 'individual',
            'identification_type' => '01',
            'identification' => 'ID-'.$suffix,
            'name' => 'Cliente '.$suffix,
            'phone' => null,
            'mobile' => null,
            'email' => null,
            'credit_limit' => 0,
            'credit_days' => 0,
            'is_active' => true,
        ], $attributes));
    }
}
