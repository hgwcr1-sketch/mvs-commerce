<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyMovement;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Loyalty\LoyaltyAccountService;
use App\Services\Loyalty\LoyaltyMovementQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LoyaltyKardexTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_can_view_kardex_and_unauthorized_user_receives_403(): void
    {
        [$company, $branch] = $this->companyContext('Principal');
        $authorized = $this->user($company, $branch, true);
        $unauthorized = $this->user($company, $branch, false);
        $movement = $this->movement($company, $branch, 'Cliente autorizado', '500.0000', LoyaltyMovement::TYPE_PURCHASE);
        $redemption = app(LoyaltyAccountService::class)->subtractPoints(
            $movement->loyaltyAccount,
            '100.0000',
            LoyaltyMovement::TYPE_REDEMPTION,
            ['branch' => $branch, 'description' => 'Canje autorizado'],
        );

        $this->getAs($authorized, $company, $branch, route('loyalty.kardex.index'))
            ->assertOk()
            ->assertSee('Kardex de Fidelidad')
            ->assertSee($movement->customer->name)
            ->assertSee('+500 puntos')
            ->assertSee('-100 puntos')
            ->assertSee('0 puntos')
            ->assertSee('500 puntos')
            ->assertSee('400 puntos');

        $this->assertSame('500.0000', $redemption->balance_before);
        $this->assertSame('400.0000', $redemption->balance_after);

        $this->getAs($unauthorized, $company, $branch, route('loyalty.kardex.index'))->assertForbidden();
    }

    public function test_company_isolation_applies_to_list_options_and_detail(): void
    {
        [$companyA, $branchA] = $this->companyContext('Empresa A');
        [$companyB, $branchB] = $this->companyContext('Empresa B');
        $user = $this->user($companyA, $branchA, true);
        $visible = $this->movement($companyA, $branchA, 'CLIENTE-VISIBLE-A', 10, LoyaltyMovement::TYPE_PROMOTION);
        $foreign = $this->movement($companyB, $branchB, 'CLIENTE-SECRETO-B', 20, LoyaltyMovement::TYPE_PROMOTION);

        $this->getAs($user, $companyA, $branchA, route('loyalty.kardex.index'))
            ->assertOk()
            ->assertSee($visible->customer->name)
            ->assertDontSee($foreign->customer->name);

        $this->getAs($user, $companyA, $branchA, route('loyalty.kardex.show', $foreign))->assertNotFound();
        $this->getAs($user, $companyA, $branchA, route('loyalty.kardex.show', $visible))->assertOk();
    }

    public function test_customer_and_branch_filters_work_without_changing_global_balance(): void
    {
        [$company, $branchA] = $this->companyContext('Filtros');
        $branchB = $this->branch($company, 'Secundaria');
        $user = $this->user($company, $branchA, true);
        $first = $this->movement($company, $branchA, 'CLIENTE-UNO', 100, LoyaltyMovement::TYPE_PURCHASE);
        $second = $this->movement($company, $branchB, 'CLIENTE-DOS', 200, LoyaltyMovement::TYPE_PURCHASE);

        $this->getAs($user, $company, $branchA, route('loyalty.kardex.index', ['customer_id' => $first->customer_id]))
            ->assertViewHas('movements', fn ($movements) => $movements->count() === 1 && $movements->first()->is($first));
        $this->getAs($user, $company, $branchA, route('loyalty.kardex.index', ['branch_id' => $branchB->id]))
            ->assertViewHas('movements', fn ($movements) => $movements->count() === 1 && $movements->first()->is($second))
            ->assertSee('Saldo: 200 puntos');
        $this->assertSame('200.0000', $second->loyaltyAccount->fresh()->balance);
    }

    public function test_date_type_and_search_filters_work(): void
    {
        [$company, $branch] = $this->companyContext('Fechas');
        $user = $this->user($company, $branch, true);
        $old = $this->movement($company, $branch, 'CLIENTE-ANTIGUO', 30, LoyaltyMovement::TYPE_BIRTHDAY, ['effective_at' => '2026-01-10 10:00:00', 'description' => 'Bono antiguo']);
        $current = $this->movement($company, $branch, 'CLIENTE-ACTUAL', 40, LoyaltyMovement::TYPE_PROMOTION, ['effective_at' => '2026-02-15 10:00:00', 'description' => 'Campaña especial', 'event_key' => 'campaign:unique-reference']);
        $late = $this->movement($company, $branch, 'CLIENTE-TARDIO', 50, LoyaltyMovement::TYPE_PURCHASE, ['effective_at' => '2026-03-20 10:00:00']);

        $this->getAs($user, $company, $branch, route('loyalty.kardex.index', ['date_from' => '2026-02-01']))
            ->assertViewHas('movements', fn ($items) => $items->pluck('id')->all() === [$late->id, $current->id]);
        $this->getAs($user, $company, $branch, route('loyalty.kardex.index', ['date_to' => '2026-02-28']))
            ->assertViewHas('movements', fn ($items) => $items->pluck('id')->all() === [$current->id, $old->id]);
        $this->getAs($user, $company, $branch, route('loyalty.kardex.index', ['type' => LoyaltyMovement::TYPE_PROMOTION]))
            ->assertViewHas('movements', fn ($items) => $items->pluck('id')->all() === [$current->id]);
        $this->getAs($user, $company, $branch, route('loyalty.kardex.index', ['search' => 'unique-reference']))
            ->assertViewHas('movements', fn ($items) => $items->pluck('id')->all() === [$current->id]);
    }

    public function test_kardex_is_paginated_and_orders_newest_first(): void
    {
        [$company, $branch] = $this->companyContext('Paginación');
        $user = $this->user($company, $branch, true);
        for ($index = 1; $index <= 21; $index++) {
            $this->movement($company, $branch, 'CLIENTE-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT), 1, LoyaltyMovement::TYPE_ADJUSTMENT, ['description' => 'Movimiento '.$index, 'effective_at' => now()->addMinutes($index)]);
        }

        $this->getAs($user, $company, $branch, route('loyalty.kardex.index'))
            ->assertOk()->assertSee('Movimiento 21')
            ->assertViewHas('movements', fn ($movements) => $movements->count() === 20 && $movements->total() === 21)
            ->assertSee('page=2', false);
    }

    public function test_detail_shows_auditable_fields_and_read_only_routes_reject_mutations(): void
    {
        [$company, $branch] = $this->companyContext('Detalle');
        $user = $this->user($company, $branch, true);
        $movement = $this->movement($company, $branch, 'CLIENTE-DETALLE', 75, LoyaltyMovement::TYPE_PURCHASE, [
            'description' => 'Concepto auditable', 'source_type' => 'App\\Models\\Sale', 'source_id' => 99,
            'base_amount' => '1500.0000', 'metadata' => ['channel' => 'administrative-test'],
        ]);

        $this->getAs($user, $company, $branch, route('loyalty.kardex.show', $movement))
            ->assertOk()->assertSee('CLIENTE-DETALLE')->assertSee('Concepto auditable')
            ->assertSee('+75 puntos')->assertSee('₡1.500,00')->assertSee('administrative-test');

        $session = ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
        $this->actingAs($user)->withSession($session)->post('/fidelidad/kardex')->assertStatus(405);
        $this->actingAs($user)->withSession($session)->put(route('loyalty.kardex.show', $movement), ['points' => 999])->assertStatus(405);
        $this->actingAs($user)->withSession($session)->delete(route('loyalty.kardex.show', $movement))->assertStatus(405);
        $this->assertSame('75.0000', $movement->fresh()->points);
    }

    public function test_query_service_eager_loads_every_relation_used_by_the_list(): void
    {
        [$company, $branch] = $this->companyContext('Consultas');
        for ($index = 0; $index < 5; $index++) {
            $this->movement($company, $branch, 'CLIENTE-NQ-'.$index, 1, LoyaltyMovement::TYPE_PROMOTION);
        }

        DB::enableQueryLog();
        $movements = app(LoyaltyMovementQueryService::class)->paginate($company->id, []);
        $queriesBeforeRelations = count(DB::getQueryLog());
        foreach ($movements as $movement) {
            $movement->customer?->name;
            $movement->branch?->name;
            $movement->user?->name;
            $movement->loyaltyAccount?->balance;
            $movement->relatedMovement?->id;
        }

        $this->assertSame($queriesBeforeRelations, count(DB::getQueryLog()));
        $this->assertTrue($movements->first()->relationLoaded('customer'));
        $this->assertTrue($movements->first()->relationLoaded('loyaltyAccount'));
    }

    private function movement(Company $company, Branch $branch, string $customerName, string|int $points, string $type, array $context = []): LoyaltyMovement
    {
        $customer = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => $customerName, 'credit_limit' => 0, 'is_active' => true]);
        $service = app(LoyaltyAccountService::class);
        $account = $service->getOrCreateAccount($customer, $company);
        $context += ['branch' => $branch, 'description' => 'Movimiento '.$customerName];

        return str_starts_with((string) $points, '-')
            ? $service->subtractPoints($account, ltrim((string) $points, '-'), $type, $context)
            : $service->addPoints($account, $points, $type, $context);
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
