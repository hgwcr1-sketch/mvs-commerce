<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerSearchSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_suggestion_links_filter_the_listing_instead_of_opening_the_customer_file(): void
    {
        [$company, $branch, $user] = $this->context();

        $html = $this->asContext($user, $company, $branch)
            ->get(route('clientes.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString("params.set('search', customer.identification || customer.customer_code || customer.name)", $html, 'La selección debe filtrar el listado');
        $this->assertStringContainsString("link.href = '".route('clientes.index')."?' + params.toString()", $html);
        $this->assertStringNotContainsString("/clientes/' + customer.id", $html, 'La selección no debe abrir la ficha del cliente');
    }

    public function test_selecting_a_result_shows_a_filtered_listing_without_redirect_to_customer_file(): void
    {
        [$company, $branch, $user] = $this->context();
        $this->customer($company, ['name' => 'Cliente Seleccion Unico', 'identification' => '301112222']);
        $this->customer($company, ['name' => 'Cliente Distinto Otro', 'identification' => '309998888']);

        $response = $this->asContext($user, $company, $branch)
            ->get(route('clientes.index', ['search' => '301112222']));

        $response->assertOk()
            ->assertSee('Cliente Seleccion Unico')
            ->assertDontSee('Cliente Distinto Otro')
            ->assertSee('Mostrando clientes que coinciden con');

        $this->assertNull($response->headers->get('Location'), 'Seleccionar un resultado no debe redirigir a la ficha');
    }

    public function test_filters_search_pagination_and_clear_remain_available(): void
    {
        [$company, $branch, $user] = $this->context();
        $this->customer($company, ['name' => 'Cliente Paginado Uno', 'identification' => '301234567']);

        $html = $this->asContext($user, $company, $branch)
            ->get(route('clientes.index', ['search' => '301234567', 'status' => '1']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="status"', $html, 'Se conservan los filtros');
        $this->assertStringContainsString('name="type"', $html, 'Se conservan los filtros');
        $this->assertStringContainsString('Limpiar filtros', $html, 'Se conserva la opción Limpiar');
        $this->assertStringContainsString('id="customer-search"', $html, 'Se conserva el buscador');
        $this->assertStringContainsString('Cliente Paginado Uno', $html);
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
