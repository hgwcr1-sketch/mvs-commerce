<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\FiscalProfile;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductFiscalProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_with_explicit_profile_syncs_legacy_tax_rate(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.crear']);
        $exempt = $this->profile('01', '10');

        $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('productos.store'), $this->payload($company, [
                'fiscal_profile_id' => $exempt->id,
                'tax_rate' => '13',
            ]));

        $response->assertRedirect(route('productos.index'));

        $product = Product::query()->where('company_id', $company->id)->firstOrFail();

        $this->assertSame($exempt->id, (int) $product->fiscal_profile_id);
        $this->assertSame(0.0, (float) $product->tax_rate);
        $this->assertSame('exempt', $product->fiscalProfile->treatment);
    }

    public function test_zero_rate_exempt_and_not_subject_stay_distinct(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.crear']);

        $cases = [
            ['01', '01', 'zero_rate'],
            ['01', '10', 'exempt'],
            ['01', '11', 'not_subject'],
        ];

        foreach ($cases as $index => [$taxCode, $rateCode, $treatment]) {
            $profile = $this->profile($taxCode, $rateCode);

            $this->actingAs($user)->withSession($this->activeSession($company, $branch))
                ->post(route('productos.store'), $this->payload($company, [
                    'fiscal_profile_id' => $profile->id,
                    'internal_code' => 'P-' . $index . '-' . uniqid(),
                ]))->assertRedirect(route('productos.index'));

            $product = Product::query()
                ->where('company_id', $company->id)
                ->latest('id')
                ->firstOrFail();

            $this->assertSame($treatment, $product->fiscalProfile->treatment);
            $this->assertSame($rateCode, $product->fiscalProfile->tax_rate_code);
            $this->assertSame(0.0, (float) $product->tax_rate);
        }

        $profiles = Product::query()->where('company_id', $company->id)->pluck('fiscal_profile_id');

        $this->assertCount(3, $profiles->unique());
    }

    public function test_legacy_json_bridge_resolves_only_unequivocal_rates(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.crear']);

        $cases = [1 => '02', 2 => '03', 4 => '04', 13 => '08'];

        foreach ($cases as $rate => $rateCode) {
            $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
                ->postJson(route('productos.store'), $this->payload($company, [
                    'tax_rate' => (string) $rate,
                    'internal_code' => 'P-' . $rate . '-' . uniqid(),
                ]));

            $response->assertCreated();

            $product = Product::query()->where('company_id', $company->id)->latest('id')->firstOrFail();

            $this->assertSame($rateCode, $product->fiscalProfile->tax_rate_code);
            $this->assertSame((float) $rate, (float) $product->tax_rate);
            $this->assertSame('01', $product->fiscalProfile->tax_code);
        }
    }

    public function test_legacy_zero_and_eight_fail_explicitly_without_inventing_treatment(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.crear']);

        foreach (['0', '8'] as $rate) {
            $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
                ->postJson(route('productos.store'), $this->payload($company, [
                    'tax_rate' => $rate,
                    'internal_code' => 'P-' . $rate . '-' . uniqid(),
                ]));

            $response->assertSessionHasErrors(['fiscal_profile_id']);
        }

        $this->assertDatabaseCount('products', 0);
    }

    public function test_store_without_profile_or_rate_is_rejected(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.crear']);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('productos.store'), $this->payload($company, []))
            ->assertSessionHasErrors(['fiscal_profile_id']);
    }

    public function test_update_requires_explicit_treatment_and_does_not_backfill_history(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.editar']);
        $legacy = $this->product($company, ['tax_rate' => 13]);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->put(route('productos.update', $legacy), $this->payload($company, []))
            ->assertSessionHasErrors(['fiscal_profile_id']);

        $legacy->refresh();
        $this->assertNull($legacy->fiscal_profile_id);

        $notSubject = $this->profile('01', '11');

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->put(route('productos.update', $legacy), $this->payload($company, [
                'fiscal_profile_id' => $notSubject->id,
            ]))
            ->assertRedirect(route('productos.index'));

        $legacy->refresh();
        $this->assertSame($notSubject->id, (int) $legacy->fiscal_profile_id);
        $this->assertSame(0.0, (float) $legacy->tax_rate);
        $this->assertSame('not_subject', $legacy->fiscalProfile->treatment);
    }

    public function test_forms_render_human_treatments_and_cabys(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.crear', 'productos.editar']);
        $product = $this->product($company, ['cabys_code' => '5060101000000']);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('productos.create'))
            ->assertOk()
            ->assertSee('Exento (sin IVA)')
            ->assertSee('No sujeto (fuera de IVA)')
            ->assertSee('IVA 0% gravado');

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('productos.edit', $product))
            ->assertOk()
            ->assertSee('5060101000000')
            ->assertSee('Tratamiento fiscal');
    }

    private function context(string $name = 'Empresa'): array
    {
        $company = Company::create(['trade_name' => $name . ' ' . uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P' . uniqid(), 'is_active' => true]);

        return [$company, $branch];
    }

    private function user(Company $company, Branch $branch, array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol ' . uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Productos', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return $user;
    }

    private function profile(string $taxCode, string $rateCode): FiscalProfile
    {
        return FiscalProfile::query()
            ->where('tax_code', $taxCode)
            ->where('tax_rate_code', $rateCode)
            ->where('is_active', true)
            ->firstOrFail();
    }

    private function product(Company $company, array $attributes = []): Product
    {
        $id = uniqid();
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'CategorÃƒÂ­a ' . $id, 'slug' => 'cat-' . $id, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad ' . $id, 'abbreviation' => 'U', 'slug' => 'u-' . $id, 'is_active' => true]);

        return Product::create(array_merge(['company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id, 'name' => 'Producto ' . $id, 'internal_code' => 'P-' . $id, 'cost' => 100, 'sale_price' => 200, 'tax_rate' => 13, 'is_active' => true], $attributes));
    }

    private function payload(Company $company, array $overrides): array
    {
        $id = uniqid();
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Cat payload ' . $id, 'slug' => 'catp-' . $id, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad payload ' . $id, 'abbreviation' => 'UP', 'slug' => 'up-' . $id, 'is_active' => true]);

        return array_merge([
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'name' => 'Producto fiscal ' . $id,
            'internal_code' => 'PF-' . $id,
            'product_type' => 'product',
            'cost' => 100,
            'sale_price' => 200,
        ], $overrides);
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
