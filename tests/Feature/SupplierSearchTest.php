<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_victoria_finds_victoria_limon_case_insensitive(): void
    {
        [$company, $branch, $user] = $this->context();
        $victoria = $this->supplier($company, ['name' => 'Victoria Limon', 'identification' => '1-2345-6789']);

        foreach (['victoria', 'VICTORIA', 'Victoria', 'LiMoN'] as $term) {
            $response = $this->actingAs($user)
                ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
                ->getJson(route('proveedores.search', ['search' => $term]));

            $response->assertOk();
            $ids = collect($response->json())->pluck('id');
            $this->assertContains($victoria->id, $ids, "El término {$term} no encontró a Victoria Limon.");
        }
    }

    public function test_search_by_identification_and_commercial_name(): void
    {
        [$company, $branch, $user] = $this->context();
        $byId = $this->supplier($company, ['name' => 'Distribuidora CR', 'identification' => '3101-555-888']);
        $byCommercial = $this->supplier($company, ['name' => 'Grupo Andino SRL', 'commercial_name' => 'Ferretería El Tornillo']);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->getJson(route('proveedores.search', ['search' => '3101-555']))
            ->assertOk()
            ->assertJsonFragment(['id' => $byId->id]);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->getJson(route('proveedores.search', ['search' => 'tornillo']))
            ->assertOk()
            ->assertJsonFragment(['id' => $byCommercial->id]);
    }

    public function test_search_is_isolated_per_company(): void
    {
        [$company, $branch, $user] = $this->context();
        $this->supplier($company, ['name' => 'Victoria Limon']);

        [$otherCompany, $otherBranch, $otherUser] = $this->context('Otra');
        $this->supplier($otherCompany, ['name' => 'Victoria Sur']);

        $response = $this->actingAs($otherUser)
            ->withSession(['active_company_id' => $otherCompany->id, 'active_branch_id' => $otherBranch->id])
            ->getJson(route('proveedores.search', ['search' => 'victoria']));

        $response->assertOk();
        $ids = collect($response->json())->pluck('id')->all();
        $this->assertContains(Supplier::where('name', 'Victoria Sur')->first()->id, $ids);
        $this->assertNotContains(Supplier::where('name', 'Victoria Limon')->first()->id, $ids);
    }

    public function test_search_returns_no_duplicates_and_matches_list_behavior(): void
    {
        [$company, $branch, $user] = $this->context();
        $this->supplier($company, ['name' => 'Victoria Limon', 'commercial_name' => 'Victoria', 'identification' => '1']);

        // "victoria" coincide en name y commercial_name: debe aparecer una sola vez.
        $response = $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->getJson(route('proveedores.search', ['search' => 'victoria']));

        $response->assertOk();
        $this->assertCount(1, $response->json());
    }

    public function test_search_excludes_inactive_suppliers(): void
    {
        [$company, $branch, $user] = $this->context();
        $this->supplier($company, ['name' => 'Victoria Limon', 'is_active' => false]);
        $active = $this->supplier($company, ['name' => 'Victoria Norte', 'is_active' => true]);

        $response = $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->getJson(route('proveedores.search', ['search' => 'victoria']));

        $response->assertOk();
        $ids = collect($response->json())->pluck('id')->all();
        $this->assertContains($active->id, $ids);
        $this->assertNotContains(Supplier::where('name', 'Victoria Limon')->where('is_active', false)->first()->id, $ids);
    }

    private function context(string $name = 'Empresa'): array
    {
        $company = Company::create(['trade_name' => $name.' '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.uniqid(), 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        $role->permissions()->attach(Permission::firstOrCreate(['name' => 'proveedores.ver'], ['label' => 'proveedores.ver', 'module' => 'Proveedores', 'is_active' => true]));
        $user->companies()->attach($company, ['role_id' => $role->id]);
        $user->branches()->attach($branch);

        return [$company, $branch, $user];
    }

    private function supplier(Company $company, array $attributes = []): Supplier
    {
        return Supplier::create(array_merge([
            'company_id' => $company->id,
            'supplier_type' => 'company',
            'name' => 'Proveedor '.uniqid(),
            'is_active' => true,
        ], $attributes));
    }
}
