<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanySequence;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CustomerPublicCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_codes_are_generated_sequentially_with_leading_zeroes(): void
    {
        [$company] = $this->context();

        $first = $this->customer($company);
        $second = $this->customer($company);

        $this->assertSame('000001', $first->customer_code);
        $this->assertSame('000002', $second->customer_code);
        $this->assertSame('000001', $first->formatted_customer_code);
        $this->assertDatabaseHas('company_sequences', [
            'company_id' => $company->id,
            'name' => CompanySequence::CUSTOMER_CODE,
            'current_value' => 2,
        ]);
    }

    public function test_customer_code_sequences_are_independent_per_company(): void
    {
        [$companyA] = $this->context();
        [$companyB] = $this->context();

        $firstA = $this->customer($companyA);
        $secondA = $this->customer($companyA);
        $firstB = $this->customer($companyB);

        $this->assertSame('000001', $firstA->customer_code);
        $this->assertSame('000002', $secondA->customer_code);
        $this->assertSame('000001', $firstB->customer_code);
        $this->assertDatabaseHas('customers', [
            'id' => $firstB->id, 'company_id' => $companyB->id, 'customer_code' => '000001',
        ]);
    }

    public function test_clientes_store_generates_a_code_when_no_manual_code_is_given(): void
    {
        [$company, $branch, $user] = $this->context();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('clientes.store'), $this->payload(['identification' => 'AUTO-001']))
            ->assertRedirect(route('clientes.index'))->assertSessionHasNoErrors();

        $customer = Customer::where('company_id', $company->id)
            ->where('identification', 'AUTO-001')->firstOrFail();
        $this->assertSame('000001', $customer->customer_code);
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', $customer->public_code);
    }

    public function test_clientes_store_preserves_a_manual_code_and_its_leading_zeroes(): void
    {
        [$company, $branch, $user] = $this->context();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('clientes.store'), $this->payload([
                'identification' => 'MANUAL-001', 'customer_code' => '000125',
            ]))->assertRedirect(route('clientes.index'))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('customers', [
            'company_id' => $company->id, 'identification' => 'MANUAL-001', 'customer_code' => '000125',
        ]);
    }

    public function test_duplicate_manual_code_is_rejected_within_the_active_company(): void
    {
        [$company, $branch, $user] = $this->context();
        $original = $this->customer($company, ['customer_code' => 'LEGACY-001']);
        $before = $original->fresh()->getAttributes();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('clientes.store'), $this->payload(['customer_code' => 'LEGACY-001']))
            ->assertRedirect()->assertSessionHasErrors('customer_code');

        $this->assertSame(1, Customer::where('company_id', $company->id)->count());
        $this->assertSame($before, $original->fresh()->getAttributes());
    }

    public function test_the_same_manual_code_is_allowed_in_a_different_company(): void
    {
        [$companyA] = $this->context();
        [$companyB, $branchB, $userB] = $this->context();
        $original = $this->customer($companyA, ['customer_code' => 'LEGACY-001']);
        $before = $original->fresh()->getAttributes();

        $this->actingAs($userB)->withSession($this->activeSession($companyB, $branchB))
            ->post(route('clientes.store'), $this->payload(['customer_code' => 'LEGACY-001']))
            ->assertRedirect(route('clientes.index'))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('customers', ['company_id' => $companyB->id, 'customer_code' => 'LEGACY-001']);
        $this->assertSame($before, $original->fresh()->getAttributes());
    }

    public function test_editing_a_customer_accepts_its_own_code_and_preserves_public_code(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customer($company, ['customer_code' => 'EDIT-001']);
        $publicCode = $customer->public_code;

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->put(route('clientes.update', $customer), $this->payload([
                'name' => 'María Núñez actualizada',
                'identification' => $customer->identification,
                'customer_code' => 'EDIT-001',
            ]))->assertRedirect()->assertSessionHasNoErrors();

        $customer->refresh();
        $this->assertSame('María Núñez actualizada', $customer->name);
        $this->assertSame('EDIT-001', $customer->customer_code);
        $this->assertSame($publicCode, $customer->public_code);
    }

    public function test_editing_cannot_take_another_customer_code(): void
    {
        [$company, $branch, $user] = $this->context();
        $this->customer($company, ['customer_code' => 'TAKEN-001']);
        $customer = $this->customer($company, ['customer_code' => 'OWN-001']);
        $before = $customer->fresh()->getAttributes();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->put(route('clientes.update', $customer), $this->payload([
                'identification' => $customer->identification, 'customer_code' => 'TAKEN-001',
            ]))->assertRedirect()->assertSessionHasErrors('customer_code');

        $this->assertSame($before, $customer->fresh()->getAttributes());
    }

    public function test_changing_commercial_code_does_not_change_public_qr_or_barcode(): void
    {
        [$company] = $this->context();
        $customer = $this->customer($company, ['customer_code' => 'COMMERCIAL-001']);
        $service = app(CustomerPublicCodeService::class);
        $publicCode = $customer->public_code;
        $qr = $service->qrSvg($customer);
        $barcode = $service->barcodeSvg($customer);

        $customer->update(['customer_code' => 'COMMERCIAL-002']);
        $customer->refresh();

        $this->assertSame($publicCode, $customer->public_code);
        $this->assertSame($qr, $service->qrSvg($customer));
        $this->assertSame($barcode, $service->barcodeSvg($customer));
    }

    private function context(): array
    {
        $company = Company::create([
            'trade_name' => 'Empresa '.uniqid(), 'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica', 'default_phone_country_code' => '+506', 'is_active' => true,
        ]);
        $branch = Branch::create([
            'company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.uniqid(), 'is_active' => true,
        ]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Clientes '.uniqid(), 'is_active' => true]);
        foreach (['clientes.crear', 'clientes.ver', 'clientes.editar'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], [
                'label' => $name, 'module' => 'Clientes', 'is_active' => true,
            ]);
            $role->permissions()->attach($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return [$company, $branch, $user];
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'customer_type' => 'individual', 'identification_type' => '01',
            'identification' => uniqid('ID-'), 'name' => 'María Núñez',
            'phone_country_code' => '+506', 'phone' => '88881111',
            'credit_limit' => 0, 'credit_days' => 0, 'price_level' => 'normal', 'is_active' => '1',
        ], $overrides);
    }

    private function customer(Company $company, array $overrides = []): Customer
    {
        return Customer::create(['company_id' => $company->id, ...$this->payload($overrides)]);
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
