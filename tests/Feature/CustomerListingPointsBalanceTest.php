<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerListingPointsBalanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_listing_shows_real_loyalty_balance_not_legacy_points_column(): void
    {
        [$company, $branch, $user] = $this->context();

        $withBalance = $this->customer($company, ['name' => 'Cliente Con Saldo', 'identification' => '301110001', 'points' => 0]);
        LoyaltyAccount::create([
            'company_id' => $company->id,
            'customer_id' => $withBalance->id,
            'balance' => '1250.0000',
            'total_earned' => '1250.0000',
            'total_redeemed' => '0.0000',
            'total_expired' => '0.0000',
            'is_active' => true,
        ]);

        $withoutBalance = $this->customer($company, ['name' => 'Cliente Sin Saldo', 'identification' => '301110002', 'points' => 4321]);

        $html = $this->asContext($user, $company, $branch)
            ->get(route('clientes.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('1,250', $html, 'Debe mostrar el saldo real de loyalty_accounts');
        $this->assertStringContainsString('Cliente Con Saldo', $html);
        $this->assertStringContainsString('Cliente Sin Saldo', $html);
        $this->assertStringNotContainsString('4,321', $html, 'No debe usarse la columna legada customers.points');
        $this->assertMatchesRegularExpression('/Cliente Sin Saldo[\s\S]{0,600}?>\s*0\s*</', $html, 'Cliente sin cuenta debe mostrar 0 en Puntos');
        $this->assertNull(LoyaltyAccount::query()->where('customer_id', $withoutBalance->id)->first(), 'Sin cuenta de fidelizacion');
    }

    public function test_listing_does_not_mix_balances_between_companies(): void
    {
        [$company, $branch, $user] = $this->context();
        [$otherCompany] = $this->context();

        $otherCustomer = $this->customer($otherCompany, ['name' => 'Cliente Otra Empresa', 'identification' => '302220001']);
        LoyaltyAccount::create([
            'company_id' => $otherCompany->id,
            'customer_id' => $otherCustomer->id,
            'balance' => '9999.0000',
            'total_earned' => '9999.0000',
            'total_redeemed' => '0.0000',
            'total_expired' => '0.0000',
            'is_active' => true,
        ]);

        $mine = $this->customer($company, ['name' => 'Cliente Propio Sin Cuenta', 'identification' => '302220002']);

        $html = $this->asContext($user, $company, $branch)
            ->get(route('clientes.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Cliente Propio Sin Cuenta', $html);
        $this->assertStringNotContainsString('9,999', $html, 'Nunca debe verse el saldo de otra empresa');
        $this->assertStringNotContainsString('Cliente Otra Empresa', $html, 'El listado aísla por empresa');
        $this->assertSame(0, LoyaltyAccount::query()->where('company_id', $company->id)->count(), 'Solo existe cuenta en la otra empresa');
        unset($mine);
    }

    public function test_show_and_edit_forms_use_loyalty_balance(): void
    {
        [$company, $branch, $user] = $this->context();

        $withBalance = $this->customer($company, ['name' => 'Cliente Con Saldo', 'identification' => '301110001', 'points' => 0]);
        LoyaltyAccount::create([
            'company_id' => $company->id,
            'customer_id' => $withBalance->id,
            'balance' => '1250.0000',
            'total_earned' => '1250.0000',
            'total_redeemed' => '0.0000',
            'total_expired' => '0.0000',
            'is_active' => true,
        ]);

        $withoutBalance = $this->customer($company, ['name' => 'Cliente Sin Saldo', 'identification' => '301110002', 'points' => 4321]);

        $showHtml = $this->asContext($user, $company, $branch)
            ->get(route('clientes.show', $withBalance))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('1,250', $showHtml, 'La ficha debe mostrar el saldo real');
        $this->assertStringNotContainsString('4,321', $showHtml, 'La ficha no debe usar customers.points');

        $editHtml = $this->asContext($user, $company, $branch)
            ->get(route('clientes.edit', $withBalance))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('1,250', $editHtml, 'El formulario debe mostrar el saldo real');
        $this->assertStringNotContainsString('4,321', $editHtml, 'El formulario no debe usar customers.points');

        $showNoAccount = $this->asContext($user, $company, $branch)
            ->get(route('clientes.show', $withoutBalance))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/Puntos<\/label>\s*<p>\s*0\s*<\/p>/', $showNoAccount, 'Sin cuenta debe mostrar 0');
    }

    private function context(): array
    {
        $company = Company::create(['trade_name' => 'Empresa '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.uniqid(), 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        $role->permissions()->attach(Permission::firstOrCreate(['name' => 'clientes.ver'], ['label' => 'clientes.ver', 'module' => 'General', 'is_active' => true]));
        $user->companies()->attach($company, ['role_id' => $role->id]);
        $user->branches()->attach($branch);

        return [$company, $branch, $user];
    }

    private function customer(Company $company, array $attributes = []): Customer
    {
        return Customer::create(array_merge([
            'company_id' => $company->id,
            'name' => 'Cliente '.uniqid(),
            'customer_type' => 'individual',
            'is_active' => true,
        ], $attributes));
    }

    private function asContext(User $user, Company $company, Branch $branch)
    {
        return $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
    }
}
