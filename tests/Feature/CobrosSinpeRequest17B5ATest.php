<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CobrosBillingContract;
use App\Models\CobrosPaymentConfirmation;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Cobros\CobrosBillingContractService;
use App\Services\CompanyCashSettingsProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BLOQUE 17B-5A — solicitud SINPE pendiente (cobros.payment.sinpe.report).
 *
 * Cubre: idempotencia por contrato/período con referencia única persistida,
 * aislamiento empresarial, rechazo a no pagadores y que el navegador nunca
 * define importe/moneda ni logra un estado confirmado ni activación de licencias.
 */
class CobrosSinpeRequest17B5ATest extends TestCase
{
    use RefreshDatabase;

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

    /** Payload hostil: el navegador intenta definir importe/moneda/estado. */
    private function hostilePayload(): array
    {
        return [
            'amount' => '1.00',
            'currency' => 'USD',
            'status' => 'confirmed',
            'confirmed_at' => now()->toIso8601String(),
            'confirmed_by_user_id' => 999,
            'transaction_id' => 'FAKE-TRANSACTION',
        ];
    }

    public function test_payer_registers_pending_sinpe_request_idempotently(): void
    {
        [$company, $payer] = $this->context(['cobros.acceder']);
        $contract = $this->contractFor($company, $payer);

        $this->actingAs($payer)
            ->withSession(['active_company_id' => $company->id])
            ->post(route('cobros.payment.sinpe.report', $contract), $this->hostilePayload())
            ->assertRedirect(route('cobros.payment.methods', $contract));

        $this->assertSame(1, CobrosPaymentConfirmation::query()->count());

        $confirmation = CobrosPaymentConfirmation::query()->sole();
        $this->assertSame('pending', $confirmation->status);
        $this->assertSame('sinpe', $confirmation->provider);
        $this->assertNull($confirmation->confirmed_at);
        $this->assertNull($confirmation->confirmed_by_user_id);
        $this->assertSame('CRC', $confirmation->currency);
        $this->assertSame(
            app(CobrosBillingContractService::class)->monthlyTotal($contract),
            $confirmation->amount
        );
        $this->assertMatchesRegularExpression('/^SINPE-\d+-\d{6}-[A-Z0-9]{8}$/', $confirmation->transaction_id);
        $this->assertStringStartsWith('SINPE-', $confirmation->transaction_id);
        $this->assertStringNotContainsString('FAKE-TRANSACTION', $confirmation->transaction_id);

        // Nunca activa licencias ni cambia el estado contractual.
        $this->assertSame('draft', $contract->fresh()->status);
        $this->assertSame(0, \DB::table('cobros_collector_licenses')->count());

        // Segunda llamada: idempotente (misma referencia, sin filas duplicadas).
        $this->actingAs($payer)
            ->withSession(['active_company_id' => $company->id])
            ->post(route('cobros.payment.sinpe.report', $contract), $this->hostilePayload())
            ->assertRedirect(route('cobros.payment.methods', $contract));

        $this->assertSame(1, CobrosPaymentConfirmation::query()->count());
        $this->assertSame($confirmation->transaction_id, CobrosPaymentConfirmation::query()->sole()->transaction_id);

        // Consulta preparada para Panel Maestro (sin pantallas nuevas).
        $this->assertSame(1, CobrosPaymentConfirmation::sinpePending($company->id)->count());
    }

    public function test_sinpe_request_is_isolated_from_other_company_contracts(): void
    {
        [$company, $user] = $this->context(['cobros.acceder']);
        [$otherCompany, $otherPayer] = $this->context(['cobros.acceder']);
        $foreignContract = $this->contractFor($otherCompany, $otherPayer);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id])
            ->post(route('cobros.payment.sinpe.report', $foreignContract), $this->hostilePayload())
            ->assertNotFound();

        $this->assertSame(0, CobrosPaymentConfirmation::query()->count());
    }

    public function test_sinpe_confirmation_by_non_payer_is_rejected(): void
    {
        [$company, $payer] = $this->context(['cobros.acceder']);
        $contract = $this->contractFor($company, $payer);
        $nonPayer = $this->member($company, ['cobros.acceder']);

        $this->actingAs($nonPayer)
            ->withSession(['active_company_id' => $company->id])
            ->post(route('cobros.payment.sinpe.report', $contract), $this->hostilePayload())
            ->assertForbidden();

        $this->assertSame(0, CobrosPaymentConfirmation::query()->count());
        $this->assertSame('draft', $contract->fresh()->status);
    }
}
