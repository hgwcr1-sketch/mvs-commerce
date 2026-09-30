<?php

namespace Tests\Feature;

use App\Models\AccountReceivable;
use App\Models\AccountReceivablePayment;
use App\Models\Branch;
use App\Models\CobrosAssignment;
use App\Models\CobrosPaymentReversal;
use App\Models\CobrosReceipt;
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
 * Fase 3C — auditoría de cobros, anulaciones seguras y trazabilidad.
 *
 * Reglas certificadas aquí:
 * - la anulación nunca borra ni modifica el pago original: crea reverso único;
 * - exige permiso dedicado cobros.anular (supervisor/dueño, nunca cobrador);
 * - saldo/custodia/recibo/idempotencia/empresa-sucursal/concurrencia protegidos;
 * - dobles anulaciones y tokens repetidos con payload distinto → 409;
 * - auditoría reutilizando RouteosAuditService con actor y empresa.
 */
class CobrosPaymentAnnulmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_annulment_restores_balance_and_keeps_original_payment_intact(): void
    {
        [$c, $b, $u] = $this->context();
        [, $account] = $this->customerWithAccount($c, $b, $u, '25000.0000');
        $this->pay($c, $account, $u, '10000.00');
        $payment = AccountReceivablePayment::sole();
        $paymentSnapshot = $payment->getRawOriginal();
        $annuller = $this->annuller($c);

        $this->actingAs($annuller)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.payments.annul', 'RCB-00000001'),
            ['reason' => 'Cobro capturado dos veces por error', 'client_token' => (string) Str::uuid()]
        )->assertRedirect(route('cobros.collector.payments.receipt', 'RCB-00000001'));

        // Saldo devuelto con BCMath y estado coherente.
        $account->refresh();
        $this->assertSame('25000.0000', (string) $account->balance_due);
        $this->assertSame(AccountReceivable::STATUS_PENDING, $account->status);

        // El pago original queda byte-idéntico: sin UPDATE ni DELETE.
        $this->assertSame($paymentSnapshot, $payment->fresh()->getRawOriginal());
        $this->assertDatabaseCount('accounts_receivable_payments', 1);

        // Reverso auditable único con actor, motivo y saldos.
        $reversal = CobrosPaymentReversal::sole();
        $this->assertSame('RVC-00000001', $reversal->reversal_number);
        $this->assertSame('10000.0000', (string) $reversal->amount);
        $this->assertSame('15000.0000', (string) $reversal->balance_before);
        $this->assertSame('25000.0000', (string) $reversal->balance_after);
        $this->assertSame((int) $annuller->id, (int) $reversal->annulled_by_user_id);
        $this->assertSame((int) $u->id, (int) $reversal->collector_user_id);
        $this->assertSame($payment->id, $reversal->payment_id);

        // El recibo refleja la anulación sin perder su contenido original.
        $receipt = CobrosReceipt::sole();
        $this->assertNotNull($receipt->annulled_at);
        $this->assertSame('Cobro capturado dos veces por error', $receipt->annul_reason);
        $this->assertSame((int) $annuller->id, (int) $receipt->annulled_by_user_id);
        $this->assertSame('RCB-00000001', $receipt->receipt_number);
        $this->assertSame('10000.0000', (string) $receipt->amount);
    }

    public function test_collector_without_annul_permission_is_forbidden(): void
    {
        [$c, $b, $u] = $this->context();
        [, $account] = $this->customerWithAccount($c, $b, $u, '1000.0000');
        $this->pay($c, $account, $u, '400.00');

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.payments.annul', 'RCB-00000001'),
            ['reason' => 'Intento del cobrador sin permiso', 'client_token' => (string) Str::uuid()]
        )->assertForbidden();

        $this->assertSame('600.0000', (string) $account->fresh()->balance_due);
        $this->assertDatabaseCount('cobros_payment_reversals', 0);
        $this->assertNull(CobrosReceipt::sole()->annulled_at);
    }

    public function test_duplicate_annulment_of_the_same_payment_is_blocked(): void
    {
        [$c, $b, $u] = $this->context();
        [, $account] = $this->customerWithAccount($c, $b, $u, '3000.0000');
        $this->pay($c, $account, $u, '1000.00');
        $annuller = $this->annuller($c);

        $this->actingAs($annuller)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.payments.annul', 'RCB-00000001'),
            ['reason' => 'Primera anulación válida del cobro', 'client_token' => (string) Str::uuid()]
        )->assertRedirect();

        // Token distinto, mismo pago: 409 y sin cambios adicionales.
        $this->actingAs($annuller)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.payments.annul', 'RCB-00000001'),
            ['reason' => 'Segundo intento sobre el mismo cobro', 'client_token' => (string) Str::uuid()]
        )->assertStatus(409);

        $this->assertSame('3000.0000', (string) $account->fresh()->balance_due);
        $this->assertDatabaseCount('cobros_payment_reversals', 1);
        $this->assertDatabaseCount('accounts_receivable_payments', 1);
    }

    public function test_same_token_and_reason_replays_the_same_reversal(): void
    {
        [$c, $b, $u] = $this->context();
        [, $account] = $this->customerWithAccount($c, $b, $u, '2000.0000');
        $this->pay($c, $account, $u, '500.00');
        $annuller = $this->annuller($c);
        $payload = ['reason' => 'Reintento de red del mismo cobro', 'client_token' => (string) Str::uuid()];

        $first = $this->actingAs($annuller)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.collector.payments.annul', 'RCB-00000001'), $payload);
        $second = $this->actingAs($annuller)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.collector.payments.annul', 'RCB-00000001'), $payload);

        $first->assertRedirect(route('cobros.collector.payments.receipt', 'RCB-00000001'));
        $second->assertSessionHas('cobros_reversal', 'RVC-00000001');

        $this->assertDatabaseCount('cobros_payment_reversals', 1);
        $this->assertSame('2000.0000', (string) $account->fresh()->balance_due);
    }

    public function test_same_token_with_a_different_reason_is_rejected(): void
    {
        [$c, $b, $u] = $this->context();
        [, $account] = $this->customerWithAccount($c, $b, $u, '2000.0000');
        $this->pay($c, $account, $u, '500.00');
        $annuller = $this->annuller($c);
        $token = (string) Str::uuid();

        $this->actingAs($annuller)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.payments.annul', 'RCB-00000001'),
            ['reason' => 'Motivo original de la anulación', 'client_token' => $token]
        )->assertRedirect();

        $this->actingAs($annuller)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.payments.annul', 'RCB-00000001'),
            ['reason' => 'Otro motivo para el mismo token', 'client_token' => $token]
        )->assertStatus(409);

        $this->assertDatabaseCount('cobros_payment_reversals', 1);
        $this->assertSame('2000.0000', (string) $account->fresh()->balance_due);
    }

    public function test_annulment_requires_a_meaningful_reason(): void
    {
        [$c, $b, $u] = $this->context();
        [, $account] = $this->customerWithAccount($c, $b, $u, '1200.0000');
        $this->pay($c, $account, $u, '200.00');
        $annuller = $this->annuller($c);

        $this->actingAs($annuller)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.payments.annul', 'RCB-00000001'),
            ['reason' => 'corto', 'client_token' => (string) Str::uuid()]
        )->assertSessionHasErrors('reason');

        $this->actingAs($annuller)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.payments.annul', 'RCB-00000001'),
            ['reason' => 'Motivo válido de anulación', 'client_token' => 'x']
        )->assertSessionHasErrors('client_token');

        $this->assertSame('1000.0000', (string) $account->fresh()->balance_due);
        $this->assertDatabaseCount('cobros_payment_reversals', 0);
    }

    public function test_foreign_receipt_cannot_be_annulled(): void
    {
        [$c, $b, $u] = $this->context();
        [, $account] = $this->customerWithAccount($c, $b, $u, '800.0000');
        $this->pay($c, $account, $u, '300.00');
        [$fc, $fb, $fu] = $this->context();
        $foreignAnnuller = $this->annuller($fc);

        $this->actingAs($foreignAnnuller)->withSession(['active_company_id' => $fc->id])->post(
            route('cobros.collector.payments.annul', 'RCB-00000001'),
            ['reason' => 'Intento cruzado sobre otra empresa', 'client_token' => (string) Str::uuid()]
        )->assertNotFound();

        $this->assertDatabaseCount('cobros_payment_reversals', 0);
        $this->assertSame('500.0000', (string) $account->fresh()->balance_due);
        $this->assertNull(CobrosReceipt::sole()->annulled_at);
        unset($b, $u, $fb, $fu);
    }

    public function test_annulled_total_payment_restores_pending_status(): void
    {
        [$c, $b, $u] = $this->context();
        [, $account] = $this->customerWithAccount($c, $b, $u, '4000.0000');
        $this->pay($c, $account, $u, '4000.00');
        $this->assertSame(AccountReceivable::STATUS_PAID, $account->fresh()->status);

        $annuller = $this->annuller($c);
        $this->actingAs($annuller)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.payments.annul', 'RCB-00000001'),
            ['reason' => 'Pago total anulado por el supervisor', 'client_token' => (string) Str::uuid()]
        )->assertRedirect();

        $account->refresh();
        $this->assertSame('4000.0000', (string) $account->balance_due);
        $this->assertSame(AccountReceivable::STATUS_PENDING, $account->status);
        $this->assertSame('4000.0000', (string) AccountReceivablePayment::sole()->amount);
    }

    public function test_payments_and_annulments_are_audited_with_actor_and_company(): void
    {
        [$c, $b, $u] = $this->context();
        [, $account] = $this->customerWithAccount($c, $b, $u, '1500.0000');
        $this->pay($c, $account, $u, '500.00');
        $annuller = $this->annuller($c);

        $this->actingAs($annuller)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.payments.annul', 'RCB-00000001'),
            ['reason' => 'Anulación auditada con actor y empresa', 'client_token' => (string) Str::uuid()]
        )->assertRedirect();

        $registered = DB::table('routeos_audit_logs')->where('company_id', $c->id)
            ->where('action', 'cobros.payment.registered')->first();
        $annulled = DB::table('routeos_audit_logs')->where('company_id', $c->id)
            ->where('action', 'cobros.payment.annulled')->first();

        $this->assertNotNull($registered);
        $this->assertSame((int) $u->id, (int) $registered->actor_id);
        $this->assertSame(AccountReceivablePayment::class, $registered->entity_type);
        $this->assertSame((int) $b->id, (int) $registered->branch_id);
        $this->assertStringContainsString('RCB-00000001', $registered->metadata);

        $this->assertNotNull($annulled);
        $this->assertSame((int) $annuller->id, (int) $annulled->actor_id);
        $this->assertSame((int) $c->id, (int) $annulled->company_id);
        $this->assertStringContainsString('RVC-00000001', $annulled->metadata);
        $this->assertSame(
            'Anulación auditada con actor y empresa',
            json_decode($annulled->metadata, true)['reason'] ?? null
        );
        // Nunca se registran tokens de idempotencia.
        $this->assertStringNotContainsString('client_token', $annulled->metadata);
    }

    public function test_annulment_never_touches_cash_custody_or_idempotency_state(): void
    {
        [$c, $b, $u] = $this->context();
        [, $account] = $this->customerWithAccount($c, $b, $u, '900.0000');
        $this->pay($c, $account, $u, '900.00');
        $payment = AccountReceivablePayment::sole();
        $receipt = CobrosReceipt::sole();

        $annuller = $this->annuller($c);
        $this->actingAs($annuller)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.payments.annul', 'RCB-00000001'),
            ['reason' => 'Reverso sin efecto en caja', 'client_token' => (string) Str::uuid()]
        )->assertRedirect();

        $payment->refresh();
        $this->assertNull($payment->cash_session_id);
        $this->assertFalse($payment->affects_cash_snapshot);
        $this->assertSame('0.0000', (string) $payment->cash_effect_amount);
        $this->assertDatabaseCount('cash_sessions', 0);
        $this->assertDatabaseCount('cash_movements', 0);

        // El token de idempotencia del cobro sigue siendo único y vigente.
        $this->assertNotNull($receipt->fresh()->client_token);
        $this->assertSame($receipt->payload_hash, CobrosReceipt::sole()->payload_hash);

        // Reintentar el cobro con el mismo token devuelve el mismo recibo, sin pagos nuevos.
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.payments.store', $account),
            $this->confirm($this->payload('900.00', $this->method($c, 'cash'), null, $receipt->client_token))
        )->assertRedirect(route('cobros.collector.payments.receipt', 'RCB-00000001'));

        $this->assertDatabaseCount('accounts_receivable_payments', 1);
        $this->assertDatabaseCount('cobros_receipts', 1);
        $this->assertDatabaseCount('cash_sessions', 0);
    }

    public function test_audit_screen_is_scoped_and_permissioned(): void
    {
        [$c, $b, $u] = $this->context();
        [, $account] = $this->customerWithAccount($c, $b, $u, '700.0000');
        $this->pay($c, $account, $u, '700.00');
        $annuller = $this->annuller($c);
        $this->actingAs($annuller)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.payments.annul', 'RCB-00000001'),
            ['reason' => 'Auditoría visible para el dueño', 'client_token' => (string) Str::uuid()]
        )->assertRedirect();

        // Dueño de la empresa: ve cobro y anulación.
        $this->actingAs($annuller)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.owner.audit'))
            ->assertOk()->assertSee('Cobro registrado')->assertSee('Cobro anulado')->assertSee('RCB-00000001');

        // El cobrador sin portal_dueno/supervisar no entra.
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.owner.audit'))->assertForbidden();
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.supervisor.audit'))->assertForbidden();

        // Otra empresa: pantalla propia, sin registros ajenos.
        [$fc, , $fu] = $this->context();
        $foreignOwner = $this->annuller($fc);
        $this->actingAs($foreignOwner)->withSession(['active_company_id' => $fc->id])
            ->get(route('cobros.owner.audit'))
            ->assertOk()->assertDontSee('RCB-00000001')->assertSee('Todavía no hay cobros');
        unset($fu);
    }

    /** Registra un cobro real por HTTP para tener recibo y pago. */
    private function pay(Company $c, AccountReceivable $account, User $collector, string $amount): void
    {
        $this->actingAs($collector)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.payments.store', $account),
            $this->confirm($this->payload($amount, $this->method($c, 'cash')))
        )->assertRedirect();
    }

    /** Usuario de la empresa con permiso dedicado cobros.anular (sin cobrar). */
    private function annuller(Company $company): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Anulador '.uniqid(), 'is_active' => true]);
        foreach (['cobros.acceder', 'cobros.portal_dueno', 'cobros.anular'] as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]));
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($company->branches()->first()->id);

        return $user;
    }

    /** @return array<string, mixed> */
    private function payload(string $amount, PaymentMethod $method, ?string $reference = null, ?string $token = null): array
    {
        return [
            'amount' => $amount,
            'payment_method_id' => $method->id,
            'reference' => $reference,
            'notes' => null,
            'client_token' => $token ?? (string) Str::uuid(),
        ];
    }

    private function confirm(array $payload): array
    {
        return array_merge($payload, ['confirm' => '1']);
    }

    private function context(array $permissions = ['cobros.acceder', 'cobros.cobrar']): array
    {
        $company = Company::create(['trade_name' => 'Cobros '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Sucursal '.substr(uniqid(), -5), 'code' => 'C'.random_int(1, 99), 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Cobrador '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]));
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        app(CompanyLicenseService::class)->updateModules($company, User::factory()->create(['is_platform_admin' => true, 'is_active' => true]), ['cobros']);
        app(PaymentMethodProvisioner::class)->provision($company);

        return [$company->fresh(), $branch, $user];
    }

    private function method(Company $company, string $code): PaymentMethod
    {
        return PaymentMethod::query()->where('company_id', $company->id)->where('code', $code)->firstOrFail();
    }

    /** @return array{0: Customer, 1: AccountReceivable} */
    private function customerWithAccount(Company $c, Branch $b, User $u, string $balance): array
    {
        $customer = Customer::create([
            'company_id' => $c->id, 'customer_type' => 'individual', 'name' => 'Cliente '.uniqid(), 'is_active' => true,
        ]);
        CobrosAssignment::create([
            'company_id' => $c->id, 'customer_id' => $customer->id,
            'collector_user_id' => $u->id, 'assigned_by' => $u->id, 'assigned_at' => now(),
        ]);

        $sale = Sale::create(['company_id' => $c->id, 'branch_id' => $b->id, 'user_id' => $u->id, 'customer_id' => $customer->id, 'sale_number' => 'CXC-'.strtoupper(substr(uniqid(), -8)), 'status' => 'completed', 'completed_at' => now()]);
        $account = AccountReceivable::create(['company_id' => $c->id, 'branch_id' => $b->id, 'customer_id' => $customer->id, 'sale_id' => $sale->id, 'issued_at' => today()->subMonth(), 'due_date' => today($c->timezone)->addDays(7), 'original_amount' => $balance, 'balance_due' => $balance, 'status' => 'pending', 'currency_code' => 'CRC']);

        return [$customer, $account];
    }
}
