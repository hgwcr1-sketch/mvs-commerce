<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CobrosBillingContract;
use App\Models\CobrosCollectorLicense;
use App\Models\CobrosPaymentConfirmation;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Cobros\CollectorLicenseService;
use App\Services\Cobros\CollectorManagementService;
use App\Services\Cobros\CobrosBillingContractService;
use App\Services\Cobros\CobrosPaymentConfirmationService;
use App\Services\Cobros\CobrosPricingService;
use App\Services\CompanyCashSettingsProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BLOQUE 19A-1 — certificación E2E local del recorrido registro → pago →
 * activación con proveedores simulados (SINPE manual; sin llamadas externas).
 *
 * Reglas verificadas: el dueño gratuito conserva su acceso administrativo
 * (portal del dueño) en todo momento; el bloqueo previo a la activación se
 * demuestra con un cobrador que realmente requiere licencia individual; el
 * contrato pendiente se aprueba por Maestro (SINPE), activa licencia de pago
 * y da paso al acceso operativo y a la doble aprobación idempotente.
 */
class CobrosE2ERegistrationActivation19A1Test extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Company, 1: User, 2: User} empresa, dueño/pagador gratuito, admin Maestro */
    private function context(): array
    {
        $company = Company::create([
            'trade_name' => 'E2E '.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
        Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'E'.random_int(10, 99), 'is_active' => true]);
        app(CompanyCashSettingsProvisioner::class)->provision($company);

        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);

        $owner = User::factory()->create(['is_active' => true, 'email' => 'dueno.'.uniqid().'@e2e.test']);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Dueno '.uniqid(), 'is_active' => true]);
        foreach (['cobros.portal_dueno', 'cobros.acceder'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]);
            $role->permissions()->attach($permission->id);
        }
        $owner->companies()->attach($company->id, ['role_id' => $role->id]);

        return [$company, $owner, $admin];
    }

    private function registerContract(Company $company, User $payer, User $admin): CobrosBillingContract
    {
        return app(CobrosBillingContractService::class)->createIndividual(
            $company,
            'prestamista',
            'CR',
            $payer,
            now()->startOfMonth(),
            now()->addYear()->startOfMonth(),
            $admin,
        );
    }

    private function applyIndividualMode(Company $company, User $admin): void
    {
        $this->actingAs($admin)
            ->patch(route('platform.cobros-contract.update', $company), [
                'license_mode' => 'individual',
                'admin_license_charged' => false,
            ])
            ->assertSessionHasNoErrors();

        $company->refresh();
    }

    public function test_full_journey_registration_sinpe_payment_activation_and_operational_access(): void
    {
        [$company, $owner, $admin] = $this->context();

        // 1) Registro del prestamista: contrato individual pendiente (draft).
        $contract = $this->registerContract($company, $owner, $admin);
        $this->assertSame('draft', $contract->status);

        // 2) Cotización desde precios internos (fuente de verdad de importe/moneda).
        $quote = app(CobrosPricingService::class)->quoteForContract($contract);
        $this->assertSame('CRC', $quote->currency);
        $this->assertSame(0, bccomp($quote->monthlyTotal, (string) $contract->monthly_amount, 4));

        // 3) Dueño gratuito ANTES de la activación: acceso administrativo
        //    conservado (portal del dueño), conforme a las reglas existentes.
        $this->actingAs($owner)
            ->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.index'))
            ->assertRedirect(route('cobros.owner.dashboard'));

        // 4) Bloqueo previo a la activación con un cobrador que REALMENTE
        //    requiere licencia: alta sin modalidad individual (sin licencia)
        //    y luego el contrato individual exige la licencia propia.
        $collector = app(CollectorManagementService::class)->createCollector(
            $company,
            ['email' => 'cobrador.'.uniqid().'@e2e.test', 'name' => 'Cobrador E2E'],
            $admin,
            true,
        )['user'];
        $this->assertSame(0, CobrosCollectorLicense::query()->where('user_id', $collector->id)->count());

        $this->applyIndividualMode($company, $admin);

        $this->assertSame(0, CobrosCollectorLicense::query()->where('company_id', $company->id)->count());
        $this->actingAs($collector)
            ->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.index'))
            ->assertForbidden();

        // 5) Solicitud de pago SINPE (importes/moneda/estado los define el servicio).
        $confirmation = app(CobrosPaymentConfirmationService::class)->requestSinpe($contract, $owner);
        $this->assertSame(CobrosPaymentConfirmation::STATUS_PENDING, $confirmation->status);
        $this->assertSame('sinpe', $confirmation->provider);
        $this->assertSame('CRC', $confirmation->currency);
        $this->assertSame(0, bccomp((string) $confirmation->amount, $quote->monthlyTotal, 4));

        // 6) Aprobación por administrador Maestro autorizado (sin llamadas externas).
        $approved = app(CobrosPaymentConfirmationService::class)->approveSinpe($confirmation, $admin);
        $this->assertSame(CobrosPaymentConfirmation::STATUS_CONFIRMED, $approved->status);
        $this->assertSame($admin->id, $approved->confirmed_by_user_id);
        $this->assertNotNull($approved->confirmed_at);

        // 7) Activación de contrato y licencia individual del pagador.
        $this->assertSame('active', $contract->fresh()->status);
        $license = CobrosCollectorLicense::query()
            ->where('company_id', $company->id)->where('user_id', $owner->id)->sole();
        $this->assertSame('active', $license->status);
        $periodEnd = now()->startOfMonth()->endOfMonth();
        $this->assertSame($periodEnd->toDateTimeString(), $license->expires_at?->toDateTimeString());
        $this->assertSame('active', app(CollectorLicenseService::class)->stateOf($license, $company));

        // 8) Acceso operativo DESPUÉS de la activación (portal del dueño).
        $this->actingAs($owner)
            ->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.index'))
            ->assertRedirect(route('cobros.owner.dashboard'));

        // 9) Segunda aprobación: idempotente, sin duplicar licencia ni
        //    confirmaciones y sin extender nuevamente la vigencia.
        $second = app(CobrosPaymentConfirmationService::class)->approveSinpe($approved->fresh(), $admin);
        $this->assertSame($approved->confirmed_at?->getTimestamp(), $second->confirmed_at?->getTimestamp());
        $this->assertSame(1, CobrosPaymentConfirmation::query()
            ->where('cobros_billing_contract_id', $contract->id)->count());
        $this->assertSame(1, CobrosCollectorLicense::query()->where('company_id', $company->id)->count());
        $this->assertSame(
            $periodEnd->toDateTimeString(),
            CobrosCollectorLicense::query()->where('user_id', $owner->id)->sole()->expires_at?->toDateTimeString()
        );
    }

    public function test_isolation_between_companies_and_rejection_of_unauthorized_users(): void
    {
        [$companyA, $payerA, $admin] = $this->context();
        $contractA = $this->registerContract($companyA, $payerA, $admin);

        // Empresa B sin contrato: no ve la membresía ni el pagador de la empresa A.
        $payerB = User::factory()->create(['is_active' => true, 'email' => 'otro.'.uniqid().'@e2e.test']);
        $companyB = Company::create([
            'trade_name' => 'E2E-B '.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
            'owner_user_id' => $payerB->id,
        ]);
        $this->actingAs($admin)
            ->get(route('platform.companies.show', $companyB))
            ->assertOk()
            ->assertSee('Esta empresa no tiene un contrato de membresía CobrOS.')
            ->assertDontSee($payerA->email);

        // Usuario NO pagador de la misma empresa: 403 en la puerta de pago.
        $intruder = User::factory()->create(['is_active' => true, 'email' => 'intruso.'.uniqid().'@e2e.test']);
        $intruderRole = Role::create(['company_id' => $companyA->id, 'name' => 'Intruso '.uniqid(), 'is_active' => true]);
        $intruderRole->permissions()->attach(Permission::firstOrCreate(
            ['name' => 'cobros.acceder'], ['label' => 'cobros.acceder', 'module' => 'CobrOS', 'is_active' => true]
        )->id);
        $intruder->companies()->attach($companyA->id, ['role_id' => $intruderRole->id]);

        $this->actingAs($intruder)
            ->withSession(['active_company_id' => $companyA->id])
            ->get(route('cobros.payment.methods', $contractA))
            ->assertForbidden();

        // Pagador de OTRA empresa activa: 404 (contrato ajena a la empresa en sesión).
        $payerBRole = Role::create(['company_id' => $companyB->id, 'name' => 'Prestamista B '.uniqid(), 'is_active' => true]);
        $payerBRole->permissions()->attach(Permission::firstOrCreate(
            ['name' => 'cobros.acceder'], ['label' => 'cobros.acceder', 'module' => 'CobrOS', 'is_active' => true]
        )->id);
        $payerB->companies()->attach($companyB->id, ['role_id' => $payerBRole->id]);

        $this->actingAs($payerB)
            ->withSession(['active_company_id' => $companyB->id])
            ->get(route('cobros.payment.methods', $contractA))
            ->assertNotFound();
    }
}
