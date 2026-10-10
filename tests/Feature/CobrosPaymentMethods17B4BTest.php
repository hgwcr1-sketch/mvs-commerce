<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CobrosBillingContract;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyCashSettingsProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BLOQUE 17B-4B — ruta real de selección de métodos de pago (cobros.payment.methods).
 *
 * Cubre: 200 para usuario autorizado, 404 para contrato de otra empresa
 * (aislamiento empresarial) y 403 para usuario sin permiso cobros.acceder.
 */
class CobrosPaymentMethods17B4BTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Company, 1: User} Empresa + usuario con permiso cobros.acceder. */
    private function contextWithAccess(): array
    {
        return $this->context(['cobros.acceder']);
    }

    /**
     * @param  list<string>  $permissions
     * @return array{0: Company, 1: User}
     */
    private function context(array $permissions): array
    {
        $company = Company::create([
            'trade_name' => 'Prestamista '.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.uniqid(), 'is_active' => true]);

        $user = $this->member($company, $permissions);
        $user->branches()->attach($branch->id);

        app(CompanyCashSettingsProvisioner::class)->provision($company);

        return [$company, $user];
    }

    /** Usuario nuevo en la empresa con los permisos indicados. */
    private function member(Company $company, array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'R'.uniqid(), 'is_active' => true]);

        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Test', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }

        $user->companies()->attach($company->id, ['role_id' => $role->id]);

        return $user;
    }

    private function contractFor(Company $company, User $payer): CobrosBillingContract
    {
        return CobrosBillingContract::create([
            'company_id' => $company->id,
            'payer_user_id' => $payer->id,
            'billing_mode' => CobrosBillingContract::MODE_INDIVIDUAL,
            'modality' => 'prestamista',
            'country_iso' => 'CR',
            'currency' => 'CRC',
            'status' => 'draft',
            'seats_total' => 1,
            'monthly_amount' => '10000.0000',
            'admin_included' => false,
            'valid_from' => now()->startOfMonth()->toDateString(),
            'valid_until' => now()->addYear()->toDateString(),
        ]);
    }

    public function test_authorized_user_sees_payment_methods_screen(): void
    {
        [$company, $user] = $this->contextWithAccess();
        $contract = $this->contractFor($company, $user);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.payment.methods', $contract))
            ->assertOk()
            ->assertSee('Métodos de pago')
            ->assertSee('MARIO VEGA SAENZ');
    }

    public function test_contract_from_another_company_is_not_found(): void
    {
        [$company, $user] = $this->contextWithAccess();
        [$otherCompany, $otherUser] = $this->contextWithAccess();
        $foreignContract = $this->contractFor($otherCompany, $otherUser);
        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.payment.methods', $foreignContract))
            ->assertNotFound();
    }

    public function test_user_without_permission_is_denied(): void
    {
        [$company, $user] = $this->context([]);
        $contract = $this->contractFor($company, $user);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.payment.methods', $contract))
            ->assertForbidden();
    }

    /* ------------------------------------------------------------------
     | 17B-4B — «Mi membresía»: visibilidad por PAGADOR y acceso directo
     | ------------------------------------------------------------------ */

    public function test_membership_link_is_visible_to_the_payer_with_real_contract_url(): void
    {
        [$company, $payer] = $this->contextWithAccess();
        $contract = $this->contractFor($company, $payer);

        $this->actingAs($payer)
            ->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.payment.methods', $contract))
            ->assertOk()
            ->assertSee('Mi membresía')
            ->assertSee('contratos/'.$contract->id.'/metodos-pago', false);
    }

    public function test_membership_link_is_hidden_from_the_collector_whose_license_is_paid(): void
    {
        [$company, $payer] = $this->contextWithAccess();
        $this->contractFor($company, $payer);
        $collector = $this->member($company, ['cobros.acceder', 'cobros.cobrar']);

        $this->actingAs($collector);
        $menu = view('cobros.components.navigation', ['panel' => true, 'company' => $company])->render();

        $this->assertStringNotContainsString('Mi membresía', $menu);
    }

    public function test_non_payer_direct_access_to_payment_screen_is_forbidden(): void
    {
        [$company, $payer] = $this->contextWithAccess();
        $contract = $this->contractFor($company, $payer);
        $nonPayer = $this->member($company, ['cobros.acceder']);

        $this->actingAs($nonPayer)
            ->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.payment.methods', $contract))
            ->assertForbidden();
    }
}
