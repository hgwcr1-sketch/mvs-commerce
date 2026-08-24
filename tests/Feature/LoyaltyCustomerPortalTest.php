<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltyMultiplier;
use App\Models\LoyaltyReward;
use App\Models\LoyaltySetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Loyalty\LoyaltyAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyCustomerPortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_shows_balance_monetary_value_and_only_own_movements(): void
    {
        [$company, $branch] = $this->companyContext('Portal A');
        $this->setting($company);
        $user = $this->user($company, $branch, true);
        $customer = $this->customer($company, 'CLIENTE-PORTAL');
        $otherCustomer = $this->customer($company, 'CLIENTE-OTRO');

        $service = app(LoyaltyAccountService::class);
        $account = $service->getOrCreateAccount($customer, $company);
        $service->addPoints($account, '500.0000', LoyaltyMovement::TYPE_PURCHASE, ['branch' => $branch, 'description' => 'Compra tienda web']);
        $service->subtractPoints($account, '100.0000', LoyaltyMovement::TYPE_REDEMPTION, ['branch' => $branch, 'description' => 'Canje en caja']);
        $otherAccount = $service->getOrCreateAccount($otherCustomer, $company);
        $service->addPoints($otherAccount, '999.0000', LoyaltyMovement::TYPE_PURCHASE, ['branch' => $branch, 'description' => 'Movimiento privado de otro cliente']);

        $this->getAs($user, $company, $branch, route('loyalty.portal.show', $customer))
            ->assertOk()
            ->assertSee($customer->name)
            ->assertSee('Saldo actual')
            ->assertSee('400 puntos')
            ->assertSee('Equivale a')
            ->assertSee('₡400,00')
            ->assertSee('Compra tienda web')
            ->assertSee('+500 puntos')
            ->assertSee('Canje en caja')
            ->assertSee('-100 puntos')
            ->assertSee(LoyaltyMovement::LABELS[LoyaltyMovement::TYPE_PURCHASE])
            ->assertDontSee('Movimiento privado de otro cliente');

        $this->assertSame('400.0000', $account->fresh()->balance);
    }

    public function test_rewards_and_promotions_are_active_and_from_own_company(): void
    {
        [$companyA, $branchA] = $this->companyContext('Premios A');
        [$companyB, $branchB] = $this->companyContext('Premios B');
        $this->setting($companyA);
        $this->setting($companyB);
        $user = $this->user($companyA, $branchA, true);
        $customer = $this->customer($companyA, 'CLIENTE-CATALOGO');

        LoyaltyReward::create(['company_id' => $companyA->id, 'name' => 'Café de regalo', 'type' => 'gift', 'availability_mode' => 'unlimited', 'points_cost' => '50.0000', 'is_active' => true]);
        LoyaltyReward::create(['company_id' => $companyA->id, 'name' => 'Premio desactivado', 'type' => 'discount', 'availability_mode' => 'unlimited', 'points_cost' => '10.0000', 'is_active' => false]);
        LoyaltyReward::create(['company_id' => $companyB->id, 'name' => 'Premio de otra empresa', 'type' => 'gift', 'availability_mode' => 'unlimited', 'points_cost' => '20.0000', 'is_active' => true]);

        LoyaltyMultiplier::create(['company_id' => $companyA->id, 'name' => 'Doble puntos fin de semana', 'multiplier' => '2.0000', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'is_active' => true]);
        LoyaltyMultiplier::create(['company_id' => $companyA->id, 'name' => 'Promoción vencida', 'multiplier' => '3.0000', 'starts_at' => now()->subDays(10), 'ends_at' => now()->subDays(5), 'is_active' => true]);
        LoyaltyMultiplier::create(['company_id' => $companyA->id, 'name' => 'Promoción futura', 'multiplier' => '3.0000', 'starts_at' => now()->addDays(5), 'ends_at' => now()->addDays(10), 'is_active' => true]);
        LoyaltyMultiplier::create(['company_id' => $companyA->id, 'name' => 'Promoción pausada', 'multiplier' => '4.0000', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'is_active' => false]);
        LoyaltyMultiplier::create(['company_id' => $companyB->id, 'name' => 'Promoción de otra empresa', 'multiplier' => '9.0000', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'is_active' => true]);

        $this->getAs($user, $companyA, $branchA, route('loyalty.portal.show', $customer))
            ->assertOk()
            ->assertSee('Café de regalo')
            ->assertSee('50 puntos')
            ->assertSee('Doble puntos fin de semana')
            ->assertDontSee('Premio desactivado')
            ->assertDontSee('Premio de otra empresa')
            ->assertDontSee('Promoción vencida')
            ->assertDontSee('Promoción futura')
            ->assertDontSee('Promoción pausada')
            ->assertDontSee('Promoción de otra empresa');
    }

    public function test_customer_of_another_company_is_not_accessible(): void
    {
        [$companyA, $branchA] = $this->companyContext('Acceso A');
        [$companyB, $branchB] = $this->companyContext('Acceso B');
        $this->setting($companyA);
        $this->setting($companyB);
        $user = $this->user($companyA, $branchA, true);
        $foreignCustomer = $this->customer($companyB, 'CLIENTE-FUERA');

        $service = app(LoyaltyAccountService::class);
        $foreignAccount = $service->getOrCreateAccount($foreignCustomer, $companyB);
        $service->addPoints($foreignAccount, '777.0000', LoyaltyMovement::TYPE_PURCHASE, ['branch' => $branchB, 'description' => 'Compra de empresa ajena']);

        $this->getAs($user, $companyA, $branchA, route('loyalty.portal.show', $foreignCustomer))
            ->assertNotFound();

        $ownCustomer = $this->customer($companyA, 'CLIENTE-PROPIO');
        $this->getAs($user, $companyA, $branchA, route('loyalty.portal.show', $ownCustomer))
            ->assertOk()
            ->assertDontSee('Compra de empresa ajena');
    }

    public function test_customer_without_account_or_movements_renders_zero_state(): void
    {
        [$company, $branch] = $this->companyContext('Sin datos');
        $this->setting($company);
        $user = $this->user($company, $branch, true);
        $customer = $this->customer($company, 'CLIENTE-NUEVO-SIN-MOVIMIENTOS');

        $this->getAs($user, $company, $branch, route('loyalty.portal.show', $customer))
            ->assertOk()
            ->assertSee('Saldo actual')
            ->assertSee('0 puntos')
            ->assertSee('Equivale a')
            ->assertSee('Aún no tienes movimientos.')
            ->assertSee('No hay promociones vigentes.')
            ->assertSee('No hay premios disponibles por el momento.');
    }

    public function test_inactive_module_hides_balance_catalog_but_keeps_history(): void
    {
        [$company, $branch] = $this->companyContext('Modulo inactivo');
        LoyaltySetting::create(['company_id' => $company->id, 'is_active' => false]);
        $user = $this->user($company, $branch, true);
        $customer = $this->customer($company, 'CLIENTE-HISTORIAL');
        $account = app(LoyaltyAccountService::class)->getOrCreateAccount($customer, $company);
        app(LoyaltyAccountService::class)->addPoints($account, '250.0000', LoyaltyMovement::TYPE_PURCHASE, ['branch' => $branch, 'description' => 'Compra histórica']);

        $response = $this->getAs($user, $company, $branch, route('loyalty.portal.show', $customer));

        $response->assertOk()
            ->assertSee('El programa de fidelización no está disponible por el momento.')
            ->assertSee('Compra histórica')
            ->assertDontSee('Equivale a')
            ->assertDontSee('Premios disponibles')
            ->assertDontSee('Promociones vigentes');

        $this->assertStringNotContainsString('Saldo actual', (string) $response->getContent());
    }

    public function test_movements_are_paginated_newest_first(): void
    {
        [$company, $branch] = $this->companyContext('Paginacion portal');
        $this->setting($company);
        $user = $this->user($company, $branch, true);
        $customer = $this->customer($company, 'CLIENTE-PAGINADO');
        $account = app(LoyaltyAccountService::class)->getOrCreateAccount($customer, $company);

        for ($index = 1; $index <= 16; $index++) {
            app(LoyaltyAccountService::class)->addPoints($account, '1.0000', LoyaltyMovement::TYPE_ADJUSTMENT, [
                'description' => 'Movimiento portal '.$index,
                'effective_at' => now()->addMinutes($index),
            ]);
        }

        $this->getAs($user, $company, $branch, route('loyalty.portal.show', $customer))
            ->assertOk()
            ->assertSee('Movimiento portal 16')
            ->assertViewHas('movements', fn ($movements) => $movements->count() === 15 && $movements->total() === 16 && $movements->first()->description === 'Movimiento portal 16');
    }

    public function test_requires_authentication_permission_and_responsive_render(): void
    {
        [$company, $branch] = $this->companyContext('Permisos portal');
        $this->setting($company);
        $authorized = $this->user($company, $branch, true);
        $unauthorized = $this->user($company, $branch, false);
        $customer = $this->customer($company, 'CLIENTE-PERMISOS');

        $this->get(route('loyalty.portal.show', $customer))->assertRedirect(route('login'));
        $this->getAs($unauthorized, $company, $branch, route('loyalty.portal.show', $customer))->assertForbidden();

        $this->getAs($authorized, $company, $branch, route('loyalty.portal.show', $customer))
            ->assertOk()
            ->assertSee('width=device-width, initial-scale=1.0', false)
            ->assertSee('Programa de fidelización')
            ->assertSee($company->trade_name)
            ->assertSee('Historial de movimientos');
    }

    private function customer(Company $company, string $name): Customer
    {
        return Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => $name, 'credit_limit' => 0, 'is_active' => true]);
    }

    private function setting(Company $company): LoyaltySetting
    {
        return LoyaltySetting::create(['company_id' => $company->id, 'is_active' => true, 'earning_percentage' => '5.0000', 'point_value' => '1.0000']);
    }

    private function getAs(User $user, Company $company, Branch $branch, string $url)
    {
        return $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])->get($url);
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

    private function user(Company $company, Branch $branch, bool $authorized): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        if ($authorized) {
            $permission = Permission::firstOrCreate(['name' => 'fidelidad.ver'], ['label' => 'Ver Kardex de Fidelidad', 'module' => 'Fidelidad', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return $user;
    }
}
