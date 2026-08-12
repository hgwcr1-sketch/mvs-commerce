<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerCompanyIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_company_only_lists_its_own_customers(): void
    {
        [$user, $firstCompany, $secondCompany] = $this->tenantContext();
        $ownCustomer = $this->customer($firstCompany, 'Cliente propio', 'ID-1');
        $otherCustomer = $this->customer($secondCompany, 'Cliente ajeno', 'ID-2');

        $this->actingAs($user)
            ->withSession(['active_company_id' => $firstCompany->id])
            ->get('/clientes')
            ->assertOk()
            ->assertSee($ownCustomer->name)
            ->assertDontSee($otherCustomer->name);
    }

    public function test_a_company_cannot_open_or_modify_another_company_customer(): void
    {
        [$user, $firstCompany, $secondCompany] = $this->tenantContext();
        $otherCustomer = $this->customer($secondCompany, 'Cliente ajeno', 'ID-2');

        $session = ['active_company_id' => $firstCompany->id];

        $this->actingAs($user)
            ->withSession($session)
            ->get("/clientes/{$otherCustomer->id}")
            ->assertNotFound();

        $this->actingAs($user)
            ->withSession($session)
            ->put("/clientes/{$otherCustomer->id}", $this->customerPayload('ID-3'))
            ->assertNotFound();

        $this->assertSame('Cliente ajeno', $otherCustomer->fresh()->name);
    }

    public function test_identification_can_repeat_in_different_companies(): void
    {
        [, $firstCompany, $secondCompany] = $this->tenantContext();

        $this->customer($firstCompany, 'Cliente uno', 'REPETIDA');
        $this->customer($secondCompany, 'Cliente dos', 'REPETIDA');

        $this->assertSame(
            2,
            Customer::where('identification', 'REPETIDA')->count()
        );
    }

    public function test_identification_cannot_repeat_inside_the_same_company(): void
    {
        [$user, $company] = $this->tenantContext();
        $this->customer($company, 'Cliente existente', 'REPETIDA');

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id])
            ->post('/clientes', $this->customerPayload('REPETIDA'))
            ->assertSessionHasErrors('identification');

        $this->assertSame(
            1,
            Customer::forCompany($company->id)
                ->where('identification', 'REPETIDA')
                ->count()
        );
    }

    private function tenantContext(): array
    {
        $user = User::factory()->create();
        $firstCompany = Company::create([
            'trade_name' => 'Empresa uno',
            'is_active' => true,
        ]);
        $secondCompany = Company::create([
            'trade_name' => 'Empresa dos',
            'is_active' => true,
        ]);

        $user->companies()->attach([$firstCompany->id, $secondCompany->id]);

        return [$user, $firstCompany, $secondCompany];
    }

    private function customer(
        Company $company,
        string $name,
        string $identification
    ): Customer {
        return Customer::create([
            'company_id' => $company->id,
            'customer_type' => 'individual',
            'identification' => $identification,
            'name' => $name,
            'credit_limit' => 0,
            'is_active' => true,
        ]);
    }

    private function customerPayload(string $identification): array
    {
        return [
            'customer_type' => 'individual',
            'identification' => $identification,
            'name' => 'Cliente actualizado',
            'credit_limit' => 0,
            'is_active' => true,
        ];
    }
}
