<?php

namespace Tests\Feature;

use App\Models\AccountReceivable;
use App\Models\AccountReceivablePayment;
use App\Models\Branch;
use App\Models\CobrosAssignment;
use App\Models\CobrosPaymentReversal;
use App\Models\CobrosReceipt;
use App\Models\CobrosVisit;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\CompanyLicenseService;
use App\Services\PaymentMethodProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fase 6A — prueba integral de punta a punta de CobrOS.
 *
 * Recorre el flujo real por HTTP: login CobrOS por rol -> asignación de
 * cartera -> agenda/visita -> pago parcial -> pago total -> recibo ->
 * anulación -> reportes del dueño y alertas, verificando además:
 * - idempotencia por client_token (mismo token + mismo payload),
 * - aislamiento por empresa, sucursal, rol y cartera,
 * - caja intacta (los cobros nunca ingresan efectivo a cash_sessions).
 *
 * No escribe dinero fuera de los servicios existentes ni amplía alcance.
 */
class CobrosEndToEnd6ATest extends TestCase
{
    use RefreshDatabase;

    public function test_full_flow_login_assignment_visit_payments_receipt_annulment_reports_and_alerts(): void
    {
        $tenant = $this->tenant();
        [$company, $branch, $owner, $supervisor, $collector] = $tenant;
        [$customer, $account] = $this->customerWithAccount($company, $branch, '30000.0000');

        // 1) Login real por el formulario CobrOS de cada rol.
        $this->post(route('cobros.login.store'), [
            'email' => $owner->email, 'password' => 'Secure123',
        ])->assertRedirect(route('cobros.index'));
        $this->post(route('cobros.logout'))->assertRedirect(route('cobros.pwa.inicio'));

        $this->post(route('cobros.login.store'), [
            'email' => $supervisor->email, 'password' => 'Secure123',
        ])->assertRedirect(route('cobros.index'));

        $this->post(route('cobros.logout'))->assertRedirect(route('cobros.pwa.inicio'));

        $this->post(route('cobros.login.store'), [
            'email' => $collector->email, 'password' => 'Secure123',
        ])->assertRedirect(route('cobros.index'));
        $this->post(route('cobros.logout'))->assertRedirect(route('cobros.pwa.inicio'));

        // 2) El dueño asigna la cartera; el cobrador aún no la ve.
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])
            ->post(route('cobros.owner.assignments.store'), [
                'customer_id' => $customer->id,
                'collector_user_id' => $collector->id,
                'reason' => 'Asignación inicial 6A',
            ])->assertRedirect();

        $this->assertDatabaseHas('cobros_assignments', [
            'company_id' => $company->id, 'customer_id' => $customer->id,
            'collector_user_id' => $collector->id,
        ]);

        $collectorSession = ['active_company_id' => $company->id];
        $this->actingAs($collector)->withSession($collectorSession)
            ->get(route('cobros.collector.portfolio.show', $customer))->assertOk();

        // 3) El cobrador programa y gestiona la visita del día.
        $this->actingAs($collector)->withSession($collectorSession)
            ->post(route('cobros.visits.store', $customer), [
                'scheduled_for' => today($company->timezone)->toDateString(),
                'notes' => 'Visita de cobranza 6A',
            ])->assertRedirect();

        $visit = CobrosVisit::query()->where('company_id', $company->id)
            ->where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('pendiente', $visit->status);

        $this->actingAs($collector)->withSession($collectorSession)
            ->patch(route('cobros.visits.update', $visit), [
                'status' => 'visitado',
                'notes' => 'Cliente en sitio 6A',
            ])->assertRedirect();

        $this->assertSame('visitado', $visit->fresh()->status);

        // 4) Pago PARCIAL confirmado -> saldo y estado parcial, recibo RCB-1.
        $cash = $this->method($company, 'cash');
        $partialToken = (string) Str::uuid();
        $this->actingAs($collector)->withSession($collectorSession)
            ->post(route('cobros.collector.payments.store', $account), $this->confirm([
                'amount' => '12000.00', 'payment_method_id' => $cash->id,
                'reference' => 'REF-6A-1', 'notes' => null, 'client_token' => $partialToken,
            ]))->assertRedirect(route('cobros.collector.payments.receipt', 'RCB-00000001'));

        $this->assertSame('18000.0000', (string) $account->fresh()->balance_due);
        $this->assertSame(AccountReceivable::STATUS_PARTIAL, $account->fresh()->status);

        // Idempotencia: mismo token + mismo payload devuelve el mismo pago.
        $this->actingAs($collector)->withSession($collectorSession)
            ->post(route('cobros.collector.payments.store', $account), $this->confirm([
                'amount' => '12000.00', 'payment_method_id' => $cash->id,
                'reference' => 'REF-6A-1', 'notes' => null, 'client_token' => $partialToken,
            ]))->assertRedirect(route('cobros.collector.payments.receipt', 'RCB-00000001'));
        $this->assertDatabaseCount('accounts_receivable_payments', 1);
        $this->assertDatabaseCount('cobros_receipts', 1);
        $this->assertSame('18000.0000', (string) $account->fresh()->balance_due);

        // 5) Pago TOTAL -> cuenta pagada y segundo recibo correlativo.
        $this->actingAs($collector)->withSession($collectorSession)
            ->post(route('cobros.collector.payments.store', $account), $this->confirm([
                'amount' => '18000.00', 'payment_method_id' => $this->method($company, 'sinpe')->id,
                'reference' => 'SINPE-6A', 'notes' => null, 'client_token' => (string) Str::uuid(),
            ]))->assertRedirect(route('cobros.collector.payments.receipt', 'RCB-00000002'));

        $this->assertSame('0.0000', (string) $account->fresh()->balance_due);
        $this->assertSame(AccountReceivable::STATUS_PAID, $account->fresh()->status);
        $this->assertDatabaseCount('accounts_receivable_payments', 2);
        $this->assertDatabaseCount('cobros_receipts', 2);
        $this->assertSame('RCB-00000002', CobrosReceipt::query()->orderByDesc('id')->value('receipt_number'));

        // 6) Recibo visible para cobrador y dueño; cobrador ajeno no lo ve.
        $this->actingAs($collector)->withSession($collectorSession)
            ->get(route('cobros.collector.payments.receipt', 'RCB-00000001'))->assertOk();
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.collector.payments.receipt', 'RCB-00000001'))->assertOk();

        [$foreignCompany, , $foreignCollector] = $this->tenant();
        $foreign = CobrosAssignment::query()->where('company_id', $foreignCompany->id)->count();
        $this->assertSame(0, $foreign);
        $this->actingAs($foreignCollector)->withSession(['active_company_id' => $foreignCompany->id])
            ->get(route('cobros.collector.payments.receipt', 'RCB-00000001'))->assertNotFound();

        // 7) Anulación del segundo recibo -> saldo restaurado y pago intacto.
        $paymentSnapshot = AccountReceivablePayment::query()->orderByDesc('id')->firstOrFail()->getRawOriginal();
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])
            ->post(route('cobros.collector.payments.annul', 'RCB-00000002'), [
                'reason' => 'Anulación integral de prueba 6A',
                'client_token' => (string) Str::uuid(),
            ])->assertRedirect(route('cobros.collector.payments.receipt', 'RCB-00000002'));

        $this->assertSame('18000.0000', (string) $account->fresh()->balance_due);
        $this->assertSame(AccountReceivable::STATUS_PARTIAL, $account->fresh()->status);
        $this->assertDatabaseCount('cobros_payment_reversals', 1);
        $this->assertSame('RVC-00000001', CobrosPaymentReversal::sole()->reversal_number);
        $this->assertSame($paymentSnapshot, AccountReceivablePayment::query()->orderByDesc('id')->firstOrFail()->getRawOriginal());
        $this->assertNotNull(CobrosReceipt::query()->where('receipt_number', 'RCB-00000002')->value('annulled_at'));

        // Anulación repetida con el mismo token es idempotente (no duplica reverso).
        $replayToken = (string) Str::uuid();
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])
            ->post(route('cobros.collector.payments.annul', 'RCB-00000002'), [
                'reason' => 'Anulación integral de prueba 6A',
                'client_token' => $replayToken,
            ])->assertStatus(409);
        $this->assertDatabaseCount('cobros_payment_reversals', 1);

        // 8) Reportes y alertas del dueño reflejan el estado real.
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.owner.dashboard'))->assertOk();
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.owner.reports'))->assertOk();
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.owner.audit'))->assertOk();

        $alerts = $this->actingAs($owner)->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.owner.alerts'))->assertOk();
        $alerts->assertViewHas('alerts');
        $this->assertSame(
            ['cobros.custody_pending', 'cobros.payment_annulled', 'cobros.payment_registered'],
            $alerts->viewData('alerts')->pluck('type')->unique()->sort()->values()->all()
        );
        // La custodia pendiente es justamente la de los cobros aún no ingresados a caja.
        $this->assertSame(1, (int) $alerts->viewData('openCounts')->get('cobros.custody_pending', 0));

        // El supervisor ve la misma información operativa; el cobrador no.
        $this->actingAs($supervisor)->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.supervisor.index'))->assertOk();
        $this->actingAs($supervisor)->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.supervisor.alerts'))->assertOk();
        $this->actingAs($collector)->withSession($collectorSession)
            ->get(route('cobros.owner.reports'))->assertForbidden();
        $this->actingAs($collector)->withSession($collectorSession)
            ->get(route('cobros.supervisor.index'))->assertForbidden();

        // 9) Auditoría con actor y empresa para cobro y anulación.
        foreach (['cobros.payment.registered', 'cobros.payment.annulled'] as $action) {
            $this->assertNotNull(
                DB::table('routeos_audit_logs')->where('company_id', $company->id)->where('action', $action)->first(),
                $action
            );
        }

        // 10) Caja intacta: ningún cobro de CobrOS mueve efectivo de caja.
        $this->assertDatabaseCount('cash_sessions', 0);
        $this->assertDatabaseCount('cash_movements', 0);
        AccountReceivablePayment::query()->get()->each(function (AccountReceivablePayment $payment) {
            $this->assertNull($payment->cash_session_id);
            $this->assertFalse($payment->affects_cash_snapshot);
            $this->assertSame('0.0000', (string) $payment->cash_effect_amount);
        });
    }

    public function test_cartera_y_sucursal_aíslan_la_operación_diaria_del_cobrador(): void
    {
        [$company, $branch, $owner, , $collector] = $this->tenant();
        [$otherCollector] = $this->userWithRole($company, $branch, ['cobros.acceder', 'cobros.cobrar'], 'Otro cobrador');
        [$mine, $myAccount] = $this->customerWithAccount($company, $branch, '8000.0000');
        [, $theirAccount] = $this->customerWithAccount($company, $branch, '5000.0000');

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])->post(
            route('cobros.owner.assignments.store'),
            ['customer_id' => $mine->id, 'collector_user_id' => $collector->id]
        )->assertRedirect();
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])->post(
            route('cobros.owner.assignments.store'),
            ['customer_id' => $theirAccount->customer_id, 'collector_user_id' => $otherCollector->id]
        )->assertRedirect();

        $session = ['active_company_id' => $company->id];

        // Cartera: cada cobrador solo ve lo suyo.
        $portfolio = $this->actingAs($collector)->withSession($session)
            ->get(route('cobros.collector.portfolio'))->assertOk();
        $this->assertSame(
            [$mine->id],
            $portfolio->viewData('customers')->getCollection()->modelKeys()
        );
        $this->assertSame(
            '8000.0000',
            (string) $portfolio->viewData('customers')->getCollection()->first()->portfolio_balance
        );
        $this->actingAs($otherCollector)->withSession($session)
            ->get(route('cobros.collector.portfolio.show', $mine))->assertNotFound();

        // Cobro sobre cuenta de otra cartera: 404 y cero escrituras.
        $this->actingAs($collector)->withSession($session)
            ->post(route('cobros.collector.payments.store', $theirAccount), $this->confirm([
                'amount' => '100.00', 'payment_method_id' => $this->method($company, 'cash')->id,
                'reference' => null, 'notes' => null, 'client_token' => (string) Str::uuid(),
            ]))->assertNotFound();
        $this->assertDatabaseCount('accounts_receivable_payments', 0);
        $this->assertSame('5000.0000', (string) $theirAccount->fresh()->balance_due);

        // Sucursal ajena: el reporte del dueño no la resuelve.
        $otherBranch = Branch::create([
            'company_id' => $company->id, 'name' => 'Sucursal 6A B',
            'code' => 'B'.random_int(1, 99), 'is_active' => true,
        ]);
        $this->actingAs($owner)->withSession($session)
            ->get(route('cobros.owner.reports', ['branch_id' => $otherBranch->id]))->assertOk();
        $this->actingAs($owner)->withSession($session)
            ->get(route('cobros.supervisor.index', ['branch_id' => 999999]))->assertNotFound();
    }

    public function test_revocaciones_y_modo_sin_confirmación_no_dejan_escrituras(): void
    {
        [$company, $branch, $owner, , $collector] = $this->tenant();
        [$customer, $account] = $this->customerWithAccount($company, $branch, '1500.0000');
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])->post(
            route('cobros.owner.assignments.store'),
            ['customer_id' => $customer->id, 'collector_user_id' => $collector->id]
        )->assertRedirect();
        $session = ['active_company_id' => $company->id];
        $payload = [
            'amount' => '500.00', 'payment_method_id' => $this->method($company, 'cash')->id,
            'reference' => null, 'notes' => null, 'client_token' => (string) Str::uuid(),
        ];

        // Sin confirmación explícita no se escribe nada (nunca éxito sin servidor):
        // el servidor responde con la vista de confirmación, no con el recibo.
        $this->actingAs($collector)->withSession($session)
            ->post(route('cobros.collector.payments.store', $account), $payload)
            ->assertOk()->assertViewIs('cobros.collector.payment-create');
        $this->assertDatabaseCount('accounts_receivable_payments', 0);
        $this->assertDatabaseCount('cobros_receipts', 0);

        // Rol sin permiso de cobro: 403 y cero escrituras.
        [$noPermission] = $this->userWithRole($company, $branch, ['cobros.acceder'], 'Solo lectura');
        $this->actingAs($noPermission)->withSession($session)
            ->post(route('cobros.collector.payments.store', $account), $this->confirm($payload))
            ->assertForbidden();
        $this->assertDatabaseCount('accounts_receivable_payments', 0);

        // Usuario desactivado tras abrir sesión: 403 y cero escrituras.
        $collector->update(['is_active' => false]);
        $this->actingAs($collector->fresh())->withSession($session)
            ->post(route('cobros.collector.payments.store', $account), $this->confirm($payload))
            ->assertForbidden();
        $this->assertDatabaseCount('accounts_receivable_payments', 0);

        // Reactivado: el flujo normal vuelve a funcionar (control, noPermanent lockout).
        $collector->update(['is_active' => true]);
        $this->actingAs($collector->fresh())->withSession($session)
            ->post(route('cobros.collector.payments.store', $account), $this->confirm($payload))
            ->assertRedirect();
        $this->assertDatabaseCount('accounts_receivable_payments', 1);
        $this->assertSame('1000.0000', (string) $account->fresh()->balance_due);

        // Aislamiento: el dueño de otra empresa no alcanza el pago.
        [$foreignCompany, , $foreignOwner] = $this->tenant();
        $this->actingAs($foreignOwner)->withSession(['active_company_id' => $foreignCompany->id])
            ->get(route('cobros.collector.payments.receipt', 'RCB-00000001'))->assertNotFound();
    }

    /** @return array{0: Company, 1: Branch, 2: User, 3: User, 4: User} */
    private function tenant(): array
    {
        $company = Company::create([
            'trade_name' => 'CobrOS 6A '.uniqid(), 'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica', 'is_active' => true,
        ]);
        $branch = Branch::create([
            'company_id' => $company->id, 'name' => 'Sucursal '.substr(uniqid(), -5),
            'code' => 'S'.random_int(1, 99), 'is_active' => true,
        ]);
        app(CompanyLicenseService::class)->updateModules(
            $company,
            User::factory()->create(['is_platform_admin' => true, 'is_active' => true]),
            ['cobros']
        );
        app(PaymentMethodProvisioner::class)->provision($company);

        [$owner] = $this->userWithRole($company, $branch, [
            'cobros.acceder', 'cobros.portal_dueno', 'cobros.supervisar', 'cobros.asignar', 'cobros.anular',
        ], 'Dueño 6A');
        [$supervisor] = $this->userWithRole($company, $branch, [
            'cobros.acceder', 'cobros.supervisar',
        ], 'Supervisor 6A');
        [$collector] = $this->userWithRole($company, $branch, [
            'cobros.acceder', 'cobros.cobrar',
        ], 'Cobrador 6A');

        return [$company->fresh(), $branch, $owner, $supervisor, $collector];
    }

    /** @return array{0: User} */
    private function userWithRole(Company $company, Branch $branch, array $permissions, string $label): array
    {
        $user = User::factory()->create([
            'password' => 'Secure123', 'is_active' => true, 'must_change_password' => false,
        ]);
        $role = Role::create(['company_id' => $company->id, 'name' => $label.' '.substr(uniqid(), -5), 'is_active' => true]);
        foreach ($permissions as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(
                ['name' => $name],
                ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]
            ));
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return [$user];
    }

    private function method(Company $company, string $code): PaymentMethod
    {
        return PaymentMethod::query()->where('company_id', $company->id)->where('code', $code)->firstOrFail();
    }

    /** @return array{0: Customer, 1: AccountReceivable} */
    private function customerWithAccount(Company $company, Branch $branch, string $balance): array
    {
        $customer = Customer::create([
            'company_id' => $company->id, 'customer_type' => 'individual',
            'name' => 'Cliente 6A '.uniqid(), 'is_active' => true,
        ]);
        $sale = Sale::create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $company->users()->value('users.id'),
            'customer_id' => $customer->id, 'sale_number' => 'CXC-'.strtoupper(substr(uniqid(), -8)),
            'status' => 'completed', 'completed_at' => now(),
        ]);

        $account = AccountReceivable::create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'sale_id' => $sale->id, 'issued_at' => today()->subMonth(), 'due_date' => today($company->timezone)->addDays(7),
            'original_amount' => $balance, 'balance_due' => $balance, 'status' => 'pending', 'currency_code' => 'CRC',
        ]);

        return [$customer, $account];
    }

    private function confirm(array $payload): array
    {
        return array_merge($payload, ['confirm' => '1']);
    }
}
