<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyPromotion;
use App\Models\LoyaltySetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyPromotionTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_staff_creates_updates_and_toggles_promotions_with_timezone_precision(): void
    {
        [$company, $branch] = $this->companyContext('Promo CRUD');
        $staff = $this->user($company, $branch, ['fidelidad.promociones']);

        // La zona horaria de la empresa (Costa Rica, UTC-6) se convierte a UTC al guardar.
        $this->actingAs($staff)->withSession($this->ctx($company, $branch))
            ->post(route('loyalty.promotions.store'), $this->payload([
                'title' => 'Días de doble descuento',
                'description' => 'Todos los viernes de enero.',
                'starts_at' => '2030-01-04T00:00',
                'ends_at' => '2030-01-31T23:59',
                'sort_order' => '2',
            ]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $promotion = LoyaltyPromotion::query()->where('company_id', $company->id)->sole();
        $this->assertSame('Días de doble descuento', $promotion->title);
        $this->assertSame('Todos los viernes de enero.', $promotion->description);
        $this->assertSame('2030-01-04 06:00:00', $promotion->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2030-02-01 05:59:00', $promotion->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertTrue($promotion->is_active);
        $this->assertSame(2, $promotion->sort_order);

        $this->actingAs($staff)->withSession($this->ctx($company, $branch))
            ->put(route('loyalty.promotions.update', $promotion), $this->payload([
                'title' => 'Título editado',
                'starts_at' => '2030-01-04T00:00',
                'ends_at' => '2030-02-15T23:59',
                'is_active' => '1',
            ]))
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertSame('Título editado', $promotion->fresh()->title);
        $this->assertSame(0, $promotion->fresh()->sort_order);

        $this->actingAs($staff)->withSession($this->ctx($company, $branch))
            ->patch(route('loyalty.promotions.toggle', $promotion))
            ->assertRedirect();
        $this->assertFalse($promotion->fresh()->is_active);

        $this->actingAs($staff)->withSession($this->ctx($company, $branch))
            ->patch(route('loyalty.promotions.toggle', $promotion))
            ->assertRedirect();
        $this->assertTrue($promotion->fresh()->is_active);
    }

    public function test_validation_rejects_inverted_dates_and_invalid_data(): void
    {
        [$company, $branch] = $this->companyContext('Promo validacion');
        $staff = $this->user($company, $branch, ['fidelidad.promociones']);
        $acts = fn () => $this->actingAs($staff)->withSession($this->ctx($company, $branch));

        $acts()->post(route('loyalty.promotions.store'), $this->payload([
            'title' => 'Fin antes de inicio',
            'starts_at' => '2030-05-10T10:00',
            'ends_at' => '2030-05-01T10:00',
        ]))->assertRedirect()->assertSessionHasErrors('ends_at');
        $this->assertSame(0, LoyaltyPromotion::count());

        // Sin título: se envía la carga sin la clave title (no basta con sobrescribirla).
        $acts()->post(route('loyalty.promotions.store'), [
            'description' => null,
            'starts_at' => '2030-05-01T10:00',
            'ends_at' => '2030-05-10T10:00',
            'is_active' => '1',
        ])->assertSessionHasErrors('title');

        $acts()->post(route('loyalty.promotions.store'), $this->payload([
            'title' => 'Titulo',
            'description' => str_repeat('x', 501),
            'starts_at' => '2030-05-01T10:00',
            'ends_at' => '2030-05-10T10:00',
        ]))->assertRedirect()->assertSessionHasErrors('description');

        $acts()->post(route('loyalty.promotions.store'), $this->payload([
            'title' => 'Orden invalido',
            'sort_order' => '-3',
            'starts_at' => '2030-05-01T10:00',
            'ends_at' => '2030-05-10T10:00',
        ]))->assertRedirect()->assertSessionHasErrors('sort_order');

        $this->assertSame(0, LoyaltyPromotion::count());
    }

    public function test_administration_is_strictly_company_scoped(): void
    {
        [$companyA, $branchA] = $this->companyContext('Promo A');
        [$companyB, $branchB] = $this->companyContext('Promo B');
        $staffA = $this->user($companyA, $branchA, ['fidelidad.promociones']);
        $staffB = $this->user($companyB, $branchB, ['fidelidad.promociones']);

        $own = LoyaltyPromotion::create($this->row($companyA, ['title' => 'Promocion propia']));
        $foreign = LoyaltyPromotion::create($this->row($companyB, ['title' => 'Promocion ajena']));

        // El listado solo muestra promociones de la empresa activa.
        $this->actingAs($staffA)->withSession($this->ctx($companyA, $branchA))
            ->get(route('loyalty.promotions.index'))
            ->assertOk()
            ->assertSee('Promocion propia')
            ->assertDontSee('Promocion ajena');

        // Otra empresa no puede editar ni cambiar el estado de la promoción ajena.
        $this->actingAs($staffA)->withSession($this->ctx($companyA, $branchA))
            ->put(route('loyalty.promotions.update', $foreign), $this->payload())
            ->assertNotFound();
        $this->actingAs($staffB)->withSession($this->ctx($companyB, $branchB))
            ->put(route('loyalty.promotions.update', $own), $this->payload())
            ->assertNotFound();

        $this->actingAs($staffA)->withSession($this->ctx($companyA, $branchA))
            ->patch(route('loyalty.promotions.toggle', $foreign))
            ->assertNotFound();

        $this->assertTrue($foreign->fresh()->is_active);
        $this->assertSame('Promocion ajena', $foreign->fresh()->title);
        $this->assertTrue($own->fresh()->is_active);
    }

    public function test_management_requires_authentication_and_specific_permission(): void
    {
        [$company, $branch] = $this->companyContext('Promo permisos');
        $authorized = $this->user($company, $branch, ['fidelidad.promociones']);
        $unauthorized = $this->user($company, $branch, []);
        $promotion = LoyaltyPromotion::create($this->row($company));

        $this->get(route('loyalty.promotions.index'))->assertRedirect(route('login'));

        foreach ([
            ['GET', route('loyalty.promotions.index'), []],
            ['POST', route('loyalty.promotions.store'), $this->payload()],
            ['PUT', route('loyalty.promotions.update', $promotion), $this->payload()],
            ['PATCH', route('loyalty.promotions.toggle', $promotion), []],
        ] as [$method, $url, $data]) {
            $this->actingAs($unauthorized)->withSession($this->ctx($company, $branch))
                ->call($method, $url, $data)
                ->assertForbidden();
        }

        $this->actingAs($authorized)->withSession($this->ctx($company, $branch))
            ->get(route('loyalty.promotions.index'))
            ->assertOk();
    }

    public function test_portal_shows_only_current_promotions_of_the_own_company(): void
    {
        [$companyA, $branchA] = $this->companyContext('Portal promo A');
        [$companyB, $branchB] = $this->companyContext('Portal promo B');
        $this->setting($companyA);
        $this->setting($companyB);
        $staff = $this->user($companyA, $branchA, ['fidelidad.ver']);
        $customer = $this->customer($companyA, 'CLIENTE-PROMOS');

        LoyaltyPromotion::create($this->row($companyA, ['title' => 'Zapatos 2x1', 'description' => 'Solo esta semana.', 'sort_order' => 5]));
        LoyaltyPromotion::create($this->row($companyA, ['title' => 'Café gratis', 'description' => 'Por la compra de un postre.', 'sort_order' => 1]));
        LoyaltyPromotion::create($this->row($companyA, ['title' => 'Promo futura', 'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(10)]));
        LoyaltyPromotion::create($this->row($companyA, ['title' => 'Promo vencida', 'starts_at' => now()->subDays(10), 'ends_at' => now()->subDays(2)]));
        LoyaltyPromotion::create($this->row($companyA, ['title' => 'Promo pausada', 'is_active' => false]));
        LoyaltyPromotion::create($this->row($companyB, ['title' => 'Promo de otra empresa']));

        $this->actingAs($staff)->withSession($this->ctx($companyA, $branchA))
            ->get(route('loyalty.portal.show', $customer))
            ->assertOk()
            ->assertSeeInOrder(['Café gratis', 'Zapatos 2x1'])
            ->assertSee('Solo esta semana.')
            ->assertSee('Por la compra de un postre.')
            ->assertDontSee('Promo futura')
            ->assertDontSee('Promo vencida')
            ->assertDontSee('Promo pausada')
            ->assertDontSee('Promo de otra empresa');
    }

    public function test_portal_without_promotions_keeps_elegant_empty_state_and_public_access_routes_work(): void
    {
        [$company, $branch] = $this->companyContext('Portal vacio');
        $this->setting($company);
        $staff = $this->user($company, $branch, ['fidelidad.ver']);
        $customer = $this->customer($company, 'CLIENTE-SIN-PROMOS');

        $this->actingAs($staff)->withSession($this->ctx($company, $branch))
            ->get(route('loyalty.portal.show', $customer))
            ->assertOk()
            ->assertSee('No hay promociones vigentes.');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Promocion base',
            'description' => null,
            'starts_at' => '2030-06-01T00:00',
            'ends_at' => '2030-06-30T23:59',
            'is_active' => '1',
        ], $overrides);
    }

    private function row(Company $company, array $overrides = []): array
    {
        return array_merge([
            'company_id' => $company->id,
            'title' => 'Promocion',
            'description' => null,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
            'sort_order' => 0,
        ], $overrides);
    }

    private function ctx(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }

    private function customer(Company $company, string $name): Customer
    {
        return Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => $name, 'credit_limit' => 0, 'is_active' => true]);
    }

    private function setting(Company $company): LoyaltySetting
    {
        return LoyaltySetting::create(['company_id' => $company->id, 'is_active' => true, 'earning_percentage' => '5.0000', 'point_value' => '1.0000']);
    }

    private function companyContext(string $name): array
    {
        $company = Company::create(['trade_name' => $name.' '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);

        return [$company, $this->branch($company, 'Principal')];
    }

    private function branch(Company $company, string $name): Branch
    {
        return Branch::create(['company_id' => $company->id, 'name' => $name, 'code' => strtoupper(substr(uniqid(), -6)), 'is_active' => true]);
    }

    private function user(Company $company, Branch $branch, array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::firstOrCreate(['name' => $permissionName], ['label' => $permissionName, 'module' => 'Fidelidad', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return $user;
    }
}
