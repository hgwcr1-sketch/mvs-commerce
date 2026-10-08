<?php

namespace Tests\Feature;

use App\Models\AccountReceivable;
use App\Models\CobrosCredit;
use App\Models\CobrosCreditInstallment;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Cobros\CreditLateFeeService;
use App\Services\Cobros\LateFeeSplitService;
use App\Services\CompanyLicenseService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 9B.3 — reparto INTERNO de la mora entre prestamista y cobrador.
 * Participación pura: no altera deuda, mora del cliente, CxC ni cuotas.
 */
class CobrosLateFeeSplitTest extends TestCase
{
    use RefreshDatabase;

    private LateFeeSplitService $split;
    private CreditLateFeeService $lateFee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->split = $this->app->make(LateFeeSplitService::class);
        $this->lateFee = $this->app->make(CreditLateFeeService::class);
    }

    private function member(Company $company, array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]));
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);

        return $user;
    }

    /** @return array{0: Company, 1: User, 2: User} */
    private function tenant(): array
    {
        $company = Company::create(['trade_name' => 'Mora '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyLicenseService::class)->updateModules($company, $admin, ['cobros', 'notifications']);
        $owner = $this->member($company, ['cobros.acceder', 'cobros.portal_dueno']);
        $collector = $this->member($company, ['cobros.acceder', 'cobros.cobrar']);

        return [$company, $owner, $collector];
    }

    private function saveGeneral(User $owner, Company $company, string $pct)
    {
        return $this->actingAs($owner)->withSession(['active_company_id' => $company->id])
            ->put(route('cobros.owner.latefee.update'), ['collector_late_fee_pct' => $pct]);
    }

    private function saveOverride(User $owner, Company $company, User $collector, ?string $pct)
    {
        return $this->actingAs($owner)->withSession(['active_company_id' => $company->id])
            ->patch(route('cobros.owner.collectors.latefee', $collector), ['collector_late_fee_pct' => $pct]);
    }

    public function test_default_collector_share_is_fifty_percent(): void
    {
        [$company, , $collector] = $this->tenant();

        $this->assertSame('50.00', $this->split->effectivePercentage($company, $collector), 'default 50%');
    }

    public function test_general_forty_percent_is_effective_for_collector_without_override(): void
    {
        [$company, $owner, $collector] = $this->tenant();

        $this->saveGeneral($owner, $company, '40')->assertRedirect(route('cobros.owner.latefee'));

        $this->assertSame('40.00', $this->split->effectivePercentage($company->fresh(), $collector->fresh()));
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.owner.latefee'))
            ->assertOk()
            ->assertSee('¿Qué porcentaje de la mora gana normalmente el cobrador?');
    }

    public function test_collector_override_sixty_percent_beats_general(): void
    {
        [$company, $owner, $collector] = $this->tenant();
        $this->saveGeneral($owner, $company, '40');

        $this->saveOverride($owner, $company, $collector, '60')->assertRedirect();

        $this->assertSame('60.00', $this->split->effectivePercentage($company->fresh(), $collector->fresh()));
    }

    public function test_removing_override_returns_to_general(): void
    {
        [$company, $owner, $collector] = $this->tenant();
        $this->saveGeneral($owner, $company, '40');
        $this->saveOverride($owner, $company, $collector, '60');

        $this->saveOverride($owner, $company, $collector, '')->assertRedirect();

        $this->assertNull($collector->fresh()->collector_late_fee_pct, 'override vacío = heredar general');
        $this->assertSame('40.00', $this->split->effectivePercentage($company->fresh(), $collector->fresh()));
    }

    public function test_zero_percent_keeps_all_late_fee_for_lender(): void
    {
        [$company, $owner, $collector] = $this->tenant();

        $this->saveGeneral($owner, $company, '0')->assertRedirect();

        $this->assertSame('0.00', $this->split->effectivePercentage($company->fresh(), $collector->fresh()));
        $split = $this->split->split('5000.0000', '0.00');
        $this->assertSame('0.0000', $split['collector']);
        $this->assertSame('5000.0000', $split['lender'], '0% = toda la mora al prestamista');
    }

    public function test_hundred_percent_keeps_all_late_fee_for_collector(): void
    {
        [$company, $owner, $collector] = $this->tenant();

        $this->saveGeneral($owner, $company, '100')->assertRedirect();

        $this->assertSame('100.00', $this->split->effectivePercentage($company->fresh(), $collector->fresh()));
        $split = $this->split->split('5000.0000', '100.00');
        $this->assertSame('5000.0000', $split['collector'], '100% = toda la mora al cobrador');
        $this->assertSame('0.0000', $split['lender']);
    }

    public function test_percentages_below_zero_and_above_hundred_are_rejected(): void
    {
        [$company, $owner, $collector] = $this->tenant();

        $this->saveGeneral($owner, $company, '-5')->assertSessionHasErrors('collector_late_fee_pct');
        $this->saveGeneral($owner, $company, '100.01')->assertSessionHasErrors('collector_late_fee_pct');
        $this->saveGeneral($owner, $company, '150')->assertSessionHasErrors('collector_late_fee_pct');

        $this->assertSame('50.00', (string) $company->fresh()->collector_late_fee_pct, 'rechazo sin persistir');

        $this->saveOverride($owner, $company, $collector, '-1')->assertSessionHasErrors('collector_late_fee_pct');
        $this->saveOverride($owner, $company, $collector, '101')->assertSessionHasErrors('collector_late_fee_pct');
        $this->assertNull($collector->fresh()->collector_late_fee_pct);
    }

    public function test_collector_cannot_change_his_own_percentage(): void
    {
        [$company, , $collector] = $this->tenant();

        $this->actingAs($collector)->withSession(['active_company_id' => $company->id])
            ->patch(route('cobros.owner.collectors.latefee', $collector), ['collector_late_fee_pct' => '90'])
            ->assertForbidden();

        $this->assertNull($collector->fresh()->collector_late_fee_pct, 'el cobrador jamás se modifica su porcentaje');
    }

    public function test_other_tenant_cannot_change_collector_or_general(): void
    {
        [$company1, $owner1, $collector1] = $this->tenant();
        $this->saveGeneral($owner1, $company1, '40');

        $company2 = Company::create(['trade_name' => 'Otra '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $admin2 = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyLicenseService::class)->updateModules($company2, $admin2, ['cobros', 'notifications']);
        $owner2 = $this->member($company2, ['cobros.acceder', 'cobros.portal_dueno']);

        $this->saveOverride($owner2, $company2, $collector1, '99')->assertNotFound();
        $this->assertNull($collector1->fresh()->collector_late_fee_pct, 'otro tenant no toca el override');

        $this->saveGeneral($owner2, $company2, '10');
        $this->assertSame('40.00', (string) $company1->fresh()->collector_late_fee_pct, 'el general del tenant 1 queda intacto');
        $this->assertSame('10.00', (string) $company2->fresh()->collector_late_fee_pct);
    }

    public function test_configuration_does_not_alter_late_fee_calculation_or_models(): void
    {
        [$company, $owner, $collector] = $this->tenant();

        $credit = new CobrosCredit([
            'late_fee_type' => 'fixed',
            'late_fee_value' => '5000',
            'late_fee_grace_days' => '3',
            'terms_snapshot' => ['late_fee_type' => 'fixed', 'late_fee_value' => '5000', 'late_fee_grace_days' => 3],
        ]);
        $installment = new CobrosCreditInstallment([
            'number' => 1,
            'due_date' => '2026-10-20',
            'contractual_amount' => '281.2500',
            'applied_amount' => '0.0000',
            'status' => CobrosCreditInstallment::STATUS_PENDING,
        ]);
        $receivable = new AccountReceivable();
        $receivable->forceFill(['balance_due' => '1125.0000']);

        $before = $this->lateFee->evaluate($credit, $installment, CarbonImmutable::parse('2026-10-24'));
        $creditAttrs = $credit->getAttributes();
        $installmentAttrs = $installment->getAttributes();
        $receivableAttrs = $receivable->getAttributes();

        $this->saveGeneral($owner, $company, '40');
        $this->saveOverride($owner, $company, $collector, '60');

        $after = $this->lateFee->evaluate($credit, $installment, CarbonImmutable::parse('2026-10-24'));

        $this->assertSame($before, $after, 'el reparto no altera la mora que ve/Debe el cliente');
        $this->assertSame($creditAttrs, $credit->getAttributes(), 'crédito intacto');
        $this->assertSame($installmentAttrs, $installment->getAttributes(), 'cuotas intactas');
        $this->assertSame($receivableAttrs, $receivable->getAttributes(), 'CxC intacta');
    }

    public function test_split_example_five_thousand_at_fifty_percent_is_exact(): void
    {
        $snapshot = $this->split->snapshotFor('5000.0000', '50.00');

        $this->assertSame('5000.0000', $snapshot['late_fee_charged']);
        $this->assertSame('50.00', $snapshot['effective_pct']);
        $this->assertSame('2500.0000', $snapshot['collector_amount'], 'cobrador ₡2,500.00 exacto');
        $this->assertSame('2500.0000', $snapshot['lender_amount'], 'prestamista ₡2,500.00 exacto');
        $this->assertSame('5000.0000', bcadd($snapshot['collector_amount'], $snapshot['lender_amount'], 4), 'la suma reparte la mora completa');
    }
}
