<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CustomerTaxpayerActivity;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * B — Cliente rápido del POS con la misma consulta de Hacienda y las mismas
 * reglas de identificación que el módulo de Clientes.
 *
 * Misma ruta (/clientes/contribuyente vía MvsIdentification.consult), mismas
 * reglas, persistencia de actividades y ninguna llamada red en el guardado.
 */
class PosQuickCustomerHaciendaTest extends TestCase
{
    use RefreshDatabase;

    public function test_quick_customer_persists_applied_hacienda_activities(): void
    {
        Http::fake(fn () => throw new ConnectionException('Hacienda no responde'));

        [$company, $branch, $user] = $this->context();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->postJson(route('pos.customers.quick-store'), [
                'name' => 'Cliente Rápido SAC',
                'customer_type' => 'company',
                'identification_type' => '02',
                'identification' => '3101000000',
                'taxpayer_activities' => [
                    ['code' => '461010000', 'description' => 'Venta al por menor'],
                    ['code' => '469000000', 'description' => 'Comercio al por mayor'],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('success', true);

        $activities = CustomerTaxpayerActivity::query()
            ->where('company_id', $company->id)
            ->get();

        $this->assertCount(2, $activities);
        $this->assertSame(
            ['461010000', '469000000'],
            $activities->pluck('code')->sort()->values()->all()
        );
        $this->assertSame(0, CustomerTaxpayerActivity::query()->where('company_id', '!=', $company->id)->count());
    }

    public function test_quick_customer_without_applied_activities_persists_nothing(): void
    {
        [$company, $branch, $user] = $this->context();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->postJson(route('pos.customers.quick-store'), [
                'name' => 'Cliente Sin Actividades',
                'customer_type' => 'individual',
                'identification_type' => '01',
                'identification' => '100020002',
            ])
            ->assertCreated();

        $this->assertSame(0, CustomerTaxpayerActivity::query()->count());
    }

    public function test_quick_customer_rejects_activity_codes_that_are_not_official(): void
    {
        [$company, $branch, $user] = $this->context();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->postJson(route('pos.customers.quick-store'), [
                'name' => 'Cliente Con Código Libre',
                'customer_type' => 'individual',
                'identification_type' => '01',
                'identification' => '100030003',
                'taxpayer_activities' => [['code' => 'libre-1', 'description' => 'Código inventado']],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('taxpayer_activities.0.code');

        $this->assertSame(0, CustomerTaxpayerActivity::query()->count());
    }

    public function test_quick_customer_uses_the_same_identification_rules_as_the_customer_module(): void
    {
        [$company, $branch, $user] = $this->context();

        // Cédula física con 8 dígitos: inválida en Clientes, también aquí.
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->postJson(route('pos.customers.quick-store'), [
                'name' => 'Identificación Corta',
                'customer_type' => 'individual',
                'identification_type' => '01',
                'identification' => '12345678',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('identification');
    }

    public function test_quick_customer_saving_never_calls_hacienda(): void
    {
        Http::fake(fn () => throw new ConnectionException('No debería consultarse'));

        [$company, $branch, $user] = $this->context();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->postJson(route('pos.customers.quick-store'), [
                'name' => 'Cliente Sin Red',
                'customer_type' => 'individual',
                'identification_type' => '01',
                'identification' => '100040004',
                'taxpayer_activities' => [['code' => '461010000', 'description' => 'Venta al por menor']],
            ])
            ->assertCreated();

        Http::assertNothingSent();

        $this->assertSame(1, CustomerTaxpayerActivity::query()->where('company_id', $company->id)->count());
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
        $role = Role::create(['company_id' => $company->id, 'name' => 'POS '.uniqid(), 'is_active' => true]);

        foreach (['clientes.crear', 'clientes.ver', 'clientes.editar', 'pos.acceder'] as $name) {
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

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
