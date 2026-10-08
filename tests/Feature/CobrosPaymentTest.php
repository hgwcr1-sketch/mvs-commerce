<?php

namespace Tests\Feature;

use App\Models\AccountReceivable;
use App\Models\AccountReceivablePayment;
use App\Models\Branch;
use App\Models\CobrosAssignment;
use App\Models\CobrosReceipt;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\Cobros\PortfolioAssignmentService;
use App\Services\CompanyLicenseService;
use App\Services\PaymentMethodProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fase 3B — cobros reales (parcial/total) y recibos.
 *
 * Reglas certificadas aquí:
 * - reutiliza CxC, Gate 3A, transacción, decimales BCMath e idempotencia;
 * - mismo token + mismo payload => mismo pago; token con payload distinto => 409;
 * - sin duplicar saldos y sin ingresar efectivo a caja (custodia pendiente).
 */
class CobrosPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_payment_reduces_balance_and_creates_unique_receipt(): void
    {
        [$c, $b, $u] = $this->context();
        [$customer, $account] = $this->customerWithAccount($c, $b, $u, '25000.0000');
        $cash = $this->method($c, 'cash');

        $response = $this->actingAs($u)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.payments.store', $account),
            $this->confirm($this->payload('10000.00', $cash, 'Conf 1'))
        );

        $account->refresh();
        $this->assertSame('15000.0000', (string) $account->balance_due);
        $this->assertSame(AccountReceivable::STATUS_PARTIAL, $account->status);

        $payment = AccountReceivablePayment::sole();
        $this->assertSame('10000.0000', (string) $payment->amount);
        $this->assertNull($payment->cash_session_id);
        $this->assertFalse($payment->affects_cash_snapshot);
        $this->assertSame('0.0000', (string) $payment->cash_effect_amount);

        $receipt = CobrosReceipt::sole();
        $this->assertSame('RCB-00000001', $receipt->receipt_number);
        $this->assertSame($payment->id, $receipt->payment_id);
        $this->assertSame((int) $u->id, (int) $receipt->collector_user_id);
        $this->assertSame((int) $customer->id, (int) $receipt->customer_id);
        $this->assertSame('10000.0000', (string) $receipt->amount);
        $this->assertSame('Conf 1', $receipt->reference);

        // Custodia: ninguna caja toca este cobro.
        $this->assertDatabaseCount('cash_sessions', 0);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseCount('accounts_receivable_payments', 1);

        $response->assertRedirect(route('cobros.collector.payments.receipt', 'RCB-00000001'));
    }

    public function test_four_decimal_payment_keeps_the_exact_remaining_balance(): void
    {
        [$company, $branch, $collector] = $this->context();
        [, $account] = $this->customerWithAccount($company, $branch, $collector, '100.0002');

        $this->actingAs($collector)->withSession(['active_company_id' => $company->id])->post(
            route('cobros.collector.payments.store', $account),
            $this->confirm($this->payload('0.0001', $this->method($company, 'cash')))
        )->assertRedirect(route('cobros.collector.payments.receipt', 'RCB-00000001'));

        $this->assertSame('0.0001', (string) AccountReceivablePayment::sole()->amount);
        $this->assertSame('100.0001', (string) $account->fresh()->balance_due);
        $this->assertSame('0.0001', (string) CobrosReceipt::sole()->amount);
    }

    public function test_total_payment_marks_account_paid(): void
    {
        [$c, $b, $u] = $this->context();
        [, $account] = $this->customerWithAccount($c, $b, $u, '4000.5000');

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.payments.store', $account),
            $this->confirm($this->payload('4000.5', $this->method($c, 'sinpe'), 'SINPE-77'))
        )->assertRedirect(route('cobros.collector.payments.receipt', 'RCB-00000001'));

        $account->refresh();
        $this->assertSame('0.0000', (string) $account->balance_due);
        $this->assertSame(AccountReceivable::STATUS_PAID, $account->status);
        $this->assertSame('4000.5000', (string) AccountReceivablePayment::sole()->amount);
    }

    public function test_amount_above_balance_is_rejected_without_writes(): void
    {
        [$c, $b, $u] = $this->context();
        [, $account] = $this->customerWithAccount($c, $b, $u, '5000.0000');

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.payments.store', $account),
            $this->confirm($this->payload('5000.01', $this->method($c, 'cash')))
        )->assertSessionHasErrors('amount');

        $this->assertSame('5000.0000', (string) $account->fresh()->balance_due);
        $this->assertDatabaseCount('accounts_receivable_payments', 0);
        $this->assertDatabaseCount('cobros_receipts', 0);
    }

    public function test_same_token_and_payload_returns_the_same_payment(): void
    {
        [$c, $b, $u] = $this->context();
        [, $account] = $this->customerWithAccount($c, $b, $u, '3000.0000');
        $payload = $this->confirm($this->payload('3000.00', $this->method($c, 'cash'), null, (string) Str::uuid()));

        $first = $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.collector.payments.store', $account), $payload);
        $second = $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.collector.payments.store', $account), $payload);

        $first->assertRedirect(route('cobros.collector.payments.receipt', 'RCB-00000001'));
        $second->assertRedirect(route('cobros.collector.payments.receipt', 'RCB-00000001'));
        $this->assertDatabaseCount('accounts_receivable_payments', 1);
        $this->assertDatabaseCount('cobros_receipts', 1);
        $this->assertSame('0.0000', (string) $account->fresh()->balance_due);
        $this->assertSame('3000.0000', (string) AccountReceivablePayment::sole()->amount);
    }

    public function test_same_token_with_a_different_payload_is_rejected(): void
    {
        [$c, $b, $u] = $this->context();
        [, $account] = $this->customerWithAccount($c, $b, $u, '3000.0000');
        $token = (string) Str::uuid();

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.payments.store', $account),
            $this->confirm($this->payload('1000.00', $this->method($c, 'cash'), null, $token))
        )->assertRedirect();

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.payments.store', $account),
            $this->confirm($this->payload('2000.00', $this->method($c, 'cash'), null, $token))
        )->assertStatus(409);

        $this->assertDatabaseCount('accounts_receivable_payments', 1);
        $this->assertDatabaseCount('cobros_receipts', 1);
        $this->assertSame('2000.0000', (string) $account->fresh()->balance_due);
    }

    public function test_capture_without_confirmation_writes_nothing(): void
    {
        [$c, $b, $u] = $this->context();
        [, $account] = $this->customerWithAccount($c, $b, $u, '1000.0000');

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.payments.store', $account),
            $this->payload('1000.00', $this->method($c, 'cash'))
        )->assertOk()->assertSee('Confirme el cobro', false);

        $this->assertSame('1000.0000', (string) $account->fresh()->balance_due);
        $this->assertDatabaseCount('accounts_receivable_payments', 0);
        $this->assertDatabaseCount('cobros_receipts', 0);
        $this->assertDatabaseCount('cash_sessions', 0);
    }

    public function test_post_requires_the_collector_permission(): void
    {
        [$c, $b, $u] = $this->context();
        [, $account] = $this->customerWithAccount($c, $b, $u, '1000.0000');
        $u->companies()->updateExistingPivot($c->id, ['role_id' => $this->roleWithout($c, 'cobros.cobrar')->id]);

        $this->actingAs($u->fresh())->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.payments.store', $account),
            $this->confirm($this->payload('500.00', $this->method($c, 'cash')))
        )->assertForbidden();

        $this->assertDatabaseCount('accounts_receivable_payments', 0);
    }

    public function test_foreign_account_and_foreign_receipt_are_404(): void
    {
        [$c, $b, $u] = $this->context();
        [, $account] = $this->customerWithAccount($c, $b, $u, '1000.0000');
        [$fc, $fb, $fu] = $this->context();
        [, $foreignAccount] = $this->customerWithAccount($fc, $fb, $fu, '700.0000');

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.payments.store', $foreignAccount),
            $this->confirm($this->payload('100.00', $this->method($c, 'cash')))
        )->assertNotFound();

        // Recibo emitido por otra empresa: inexistente para esta sesión.
        $foreignReceipt = CobrosReceipt::create([
            'company_id' => $fc->id, 'branch_id' => $fb->id, 'customer_id' => $foreignAccount->customer_id,
            'account_receivable_id' => $foreignAccount->id, 'collector_user_id' => $fu->id,
            'payment_method_id' => $this->method($fc, 'cash')->id, 'receipt_number' => 'RCB-00000999',
            'amount' => '10.0000', 'currency_code' => 'CRC', 'client_token' => (string) Str::uuid(),
            'payload_hash' => str_repeat('a', 64), 'paid_at' => now(),
        ]);

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.payments.receipt', $foreignReceipt->receipt_number))->assertNotFound();

        $this->assertDatabaseCount('accounts_receivable_payments', 0);
        $this->assertSame('1000.0000', (string) $account->fresh()->balance_due);
    }

    public function test_invalid_or_foreign_payment_methods_and_missing_reference_are_rejected(): void
    {
        [$c, $b, $u] = $this->context();
        [, $account] = $this->customerWithAccount($c, $b, $u, '2000.0000');
        [$fc] = $this->context();
        $credit = $this->method($c, 'credit');
        $foreign = $this->method($fc, 'cash');
        $card = $this->method($c, 'card');
        $sessions = ['active_company_id' => $c->id];

        $this->actingAs($u)->withSession($sessions)->post(
            route('cobros.collector.payments.store', $account),
            $this->confirm($this->payload('100.00', $credit))
        )->assertSessionHasErrors('payment_method_id');

        $this->actingAs($u)->withSession($sessions)->post(
            route('cobros.collector.payments.store', $account),
            $this->confirm($this->payload('100.00', $foreign))
        )->assertSessionHasErrors('payment_method_id');

        // Tarjeta exige referencia: sin ella no hay cobro.
        $this->actingAs($u)->withSession($sessions)->post(
            route('cobros.collector.payments.store', $account),
            $this->confirm($this->payload('100.00', $card))
        )->assertSessionHasErrors('reference');

        $this->assertDatabaseCount('accounts_receivable_payments', 0);
        $this->assertDatabaseCount('cobros_receipts', 0);
        $this->assertSame('2000.0000', (string) $account->fresh()->balance_due);
    }

    public function test_receipt_numbers_are_unique_and_sequential_per_company(): void
    {
        [$c, $b, $u] = $this->context();
        [, $a1] = $this->customerWithAccount($c, $b, $u, '1000.0000');
        [, $a2] = $this->customerWithAccount($c, $b, $u, '800.0000');
        $sessions = ['active_company_id' => $c->id];

        $this->actingAs($u)->withSession($sessions)->post(
            route('cobros.collector.payments.store', $a1),
            $this->confirm($this->payload('500.00', $this->method($c, 'cash')))
        )->assertRedirect(route('cobros.collector.payments.receipt', 'RCB-00000001'));

        $this->actingAs($u)->withSession($sessions)->post(
            route('cobros.collector.payments.store', $a2),
            $this->confirm($this->payload('300.00', $this->method($c, 'cash')))
        )->assertRedirect(route('cobros.collector.payments.receipt', 'RCB-00000002'));

        $this->assertSame(
            ['RCB-00000001', 'RCB-00000002'],
            CobrosReceipt::query()->forCompany($c->id)->orderBy('id')->pluck('receipt_number')->all()
        );

        // Cada empresa empieza su propia secuencia.
        [$fc, $fb, $fu] = $this->context();
        [, $foreignAccount] = $this->customerWithAccount($fc, $fb, $fu, '500.0000');
        $this->actingAs($fu)->withSession(['active_company_id' => $fc->id])->post(
            route('cobros.collector.payments.store', $foreignAccount),
            $this->confirm($this->payload('100.00', $this->method($fc, 'cash')))
        )->assertRedirect(route('cobros.collector.payments.receipt', 'RCB-00000001'));
    }

    public function test_gate_is_revalidated_before_writing_money(): void
    {
        [$c, $b, $u] = $this->context();
        [$customer, $account] = $this->customerWithAccount($c, $b, $u, '1500.0000');
        app(PortfolioAssignmentService::class)->unassign($c, $customer, $u, 'Revocada');

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.payments.store', $account),
            $this->confirm($this->payload('100.00', $this->method($c, 'cash')))
        )->assertNotFound();

        $this->assertDatabaseCount('accounts_receivable_payments', 0);
        $this->assertDatabaseCount('cobros_receipts', 0);
    }

    /** payments/create con ubicación: 200 + enlace Maps real (reutiliza getGoogleMapsUrlAttribute). */
    public function test_create_screen_links_to_google_maps_when_customer_has_location(): void
    {
        [$c, $b, $u] = $this->context();
        [$customer, $account] = $this->customerWithAccount($c, $b, $u, '500.0000');

        // Sin GPS: la página abre y no ofrece enlace de navegación.
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.payments.create', $account))
            ->assertOk()
            ->assertDontSee('google.com/maps');

        $customer->update(['latitude' => '9.931', 'longitude' => '-84.082']);

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.payments.create', $account))
            ->assertOk()
            ->assertSee('https://www.google.com/maps/search/?api=1&query=9.93100000,-84.08200000');
    }

    public function test_receipt_history_and_detail_are_scoped_to_the_collector(): void
    {
        [$c, $b, $u] = $this->context();
        [, $account] = $this->customerWithAccount($c, $b, $u, '900.0000');
        [, , $other] = $this->extraCollector($c);

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.payments.store', $account),
            $this->confirm($this->payload('900.00', $this->method($c, 'cash')))
        )->assertRedirect();

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.payments.history'))
            ->assertOk()->assertSee('RCB-00000001')->assertSee('900.00');

        // Otro cobrador de la misma empresa: sin recibos ajenos ni detalle.
        $this->actingAs($other)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.payments.history'))
            ->assertOk()->assertDontSee('RCB-00000001');
        $this->actingAs($other)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.payments.receipt', 'RCB-00000001'))
            ->assertNotFound();

        // Un dueño de OTRA empresa tampoco ve el recibo (rutas exigen cobrar).
        [$oc, , $owner] = $this->context(['cobros.acceder', 'cobros.portal_dueno', 'cobros.cobrar']);
        $this->actingAs($owner)->withSession(['active_company_id' => $oc->id])
            ->get(route('cobros.collector.payments.receipt', 'RCB-00000001'))
            ->assertNotFound();
    }

    public function test_owner_with_portal_dueno_can_read_receipts_of_their_company(): void
    {
        [$c, $b, $u] = $this->context();
        [, $account] = $this->customerWithAccount($c, $b, $u, '600.0000');
        $owner = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $c->id, 'name' => 'Dueño '.uniqid(), 'is_active' => true]);
        // La ruta de recibo exige cobros.cobrar; el detalle además acepta portal_dueno.
        foreach (['cobros.acceder', 'cobros.portal_dueno', 'cobros.cobrar'] as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]));
        }
        $owner->companies()->attach($c->id, ['role_id' => $role->id]);

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.payments.store', $account),
            $this->confirm($this->payload('600.00', $this->method($c, 'cash')))
        )->assertRedirect();

        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.payments.receipt', 'RCB-00000001'))
            ->assertOk()->assertSee('RCB-00000001')->assertSee($account->customer->name);
    }

    public function test_receipt_screen_never_mutates_financial_state(): void
    {
        [$c, $b, $u] = $this->context();
        [, $account] = $this->customerWithAccount($c, $b, $u, '700.0000');

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.payments.store', $account),
            $this->confirm($this->payload('700.00', $this->method($c, 'cash')))
        )->assertRedirect();

        $before = $account->fresh()->getRawOriginal();
        $paymentBefore = AccountReceivablePayment::sole()->getRawOriginal();

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.payments.receipt', 'RCB-00000001'))->assertOk();
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.payments.history'))->assertOk();

        $this->assertSame($before, $account->fresh()->getRawOriginal());
        $this->assertSame($paymentBefore, AccountReceivablePayment::sole()->fresh()->getRawOriginal());
        $this->assertDatabaseCount('cobros_receipts', 1);
        $this->assertDatabaseCount('cash_sessions', 0);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('revocations')]
    public function test_revocation_between_preflight_and_transaction_aborts_without_financial_writes(string $change): void
    {
        [$company, $branch, $collector] = $this->context();
        [$customer, $account] = $this->customerWithAccount($company, $branch, $collector, '1500.0000');
        $payload = $this->payload('100.0001', $this->method($company, 'cash'));
        if ($change === 'individual_license') {
            $company->modules()->where('module_key', 'cobros')->update(['license_mode' => 'individual']);
            app(\App\Services\Cobros\CollectorLicenseService::class)->grantIfMissing($collector, $company);
        }
        $realGate = app(\App\Services\Cobros\CobrosPaymentGate::class);
        $depth = \Illuminate\Support\Facades\DB::transactionLevel();
        $calls = 0;
        $gate = \Mockery::mock(\App\Services\Cobros\CobrosPaymentGate::class);
        $gate->shouldReceive('assertCanStart')->twice()->andReturnUsing(
            function ($user, $tenant, $ar) use ($realGate, &$calls, $change, $company, $branch, $collector, $customer, $depth) {
                if (++$calls === 2) {
                    $this->assertGreaterThan($depth, \Illuminate\Support\Facades\DB::transactionLevel());
                }
                $decision = $realGate->assertCanStart($user, $tenant, $ar);
                // Intercalación determinista: la revocación gana antes del lock.
                if ($calls === 1) {
                    match ($change) {
                        'assignment' => CobrosAssignment::where('customer_id', $customer->id)->delete(),
                        'reassignment' => CobrosAssignment::where('customer_id', $customer->id)->update(['collector_user_id' => User::factory()->create()->id]),
                        'user' => $collector->update(['is_active' => false]),
                        'company' => $company->update(['is_active' => false]),
                        'membership' => $collector->companies()->detach($company->id),
                        'permission' => $collector->roleInCompany($company)->permissions()->detach(),
                        'module' => $company->modules()->where('module_key', 'cobros')->update(['is_enabled' => false]),
                        'license' => $company->license()->update(['expires_at' => now()->subDay(), 'grace_until' => null]),
                        'individual_license' => \App\Models\CobrosCollectorLicense::where('company_id', $company->id)
                            ->where('user_id', $collector->id)->update(['status' => 'suspended']),
                    };
                }

                return $decision;
            }
        );
        $this->app->instance(\App\Services\Cobros\CobrosPaymentGate::class, $gate);
        try {
            app(\App\Services\Cobros\SecurePaymentService::class)->register($collector, $company, $account, $payload);
            $this->fail('La revocación debe impedir el cobro.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertContains($exception->getStatusCode(), [403, 404]);
        }
        $this->assertSame(2, $calls);
        $this->assertSame('1500.0000', $account->fresh()->balance_due);
        $this->assertDatabaseCount('accounts_receivable_payments', 0);
        $this->assertDatabaseCount('cobros_receipts', 0);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public static function revocations(): array
    {
        // 'branch' no revoca autoridad: branch_id de CxC es trazabilidad, no frontera.
        return array_map(fn ($change) => [$change], ['assignment', 'reassignment', 'user', 'company', 'membership', 'permission', 'module', 'license', 'individual_license']);
    }

    public function test_failure_after_payment_rolls_back_payment_balance_and_receipt(): void
    {
        [$company, $branch, $collector] = $this->context();
        [, $account] = $this->customerWithAccount($company, $branch, $collector, '1500.0000');
        $audit = \Mockery::mock(\App\Services\RouteosAuditService::class);
        $audit->shouldReceive('log')->once()->andThrow(new \RuntimeException('forced rollback'));
        $this->app->instance(\App\Services\RouteosAuditService::class, $audit);
        try {
            app(\App\Services\Cobros\SecurePaymentService::class)->register(
                $collector, $company, $account, $this->payload('100.0001', $this->method($company, 'cash'))
            );
            $this->fail('Debe revertirse toda la operación.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('forced rollback', $exception->getMessage());
        }
        $this->assertSame('1500.0000', $account->fresh()->balance_due);
        $this->assertDatabaseCount('accounts_receivable_payments', 0);
        $this->assertDatabaseCount('cobros_receipts', 0);
    }

    public function test_balance_changed_after_preflight_is_checked_under_lock_and_receipt_is_rolled_back(): void
    {
        [$company, $branch, $collector] = $this->context();
        [, $account] = $this->customerWithAccount($company, $branch, $collector, '1500.0000');
        $realGate = app(\App\Services\Cobros\CobrosPaymentGate::class);
        $calls = 0;
        $gate = \Mockery::mock(\App\Services\Cobros\CobrosPaymentGate::class);
        $gate->shouldReceive('assertCanStart')->twice()->andReturnUsing(function ($user, $tenant, $ar) use ($realGate, &$calls, $account) {
            $decision = $realGate->assertCanStart($user, $tenant, $ar);
            if (++$calls === 1) {
                AccountReceivable::whereKey($account->id)->update(['balance_due' => '50.0000']);
            }

            return $decision;
        });
        $this->app->instance(\App\Services\Cobros\CobrosPaymentGate::class, $gate);
        try {
            app(\App\Services\Cobros\SecurePaymentService::class)->register(
                $collector, $company, $account, $this->payload('100.0001', $this->method($company, 'cash'))
            );
            $this->fail('Debe usar el saldo actual.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('amount', $exception->errors());
        }
        $this->assertSame('50.0000', $account->fresh()->balance_due);
        $this->assertDatabaseCount('accounts_receivable_payments', 0);
        $this->assertDatabaseCount('cobros_receipts', 0);
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

    /** El servidor solo escribe cuando el usuario confirma explícitamente. */
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

    private function roleWithout(Company $company, string $denied): Role
    {
        $role = Role::create(['company_id' => $company->id, 'name' => 'Sin cobrar '.uniqid(), 'is_active' => true]);
        foreach (['cobros.acceder', 'cobros.supervisar', 'cobros.portal_dueno', 'cobros.asignar'] as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]));
        }
        $role->permissions()->detach(Permission::query()->where('name', $denied)->value('id'));

        return $role;
    }

    private function extraCollector(Company $company): array
    {
        $other = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Otro cobrador '.uniqid(), 'is_active' => true]);
        foreach (['cobros.acceder', 'cobros.cobrar'] as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]));
        }
        $other->companies()->attach($company->id, ['role_id' => $role->id]);
        $other->branches()->attach($company->branches()->first()->id);

        return [$company, $company->branches()->first(), $other];
    }

    /** @return array{0: Customer, 1: AccountReceivable} */
    private function customerWithAccount(Company $c, Branch $b, User $u, string $balance, string $status = 'pending'): array
    {
        $customer = Customer::create([
            'company_id' => $c->id, 'customer_type' => 'individual', 'name' => 'Cliente '.uniqid(), 'is_active' => true,
        ]);
        CobrosAssignment::create([
            'company_id' => $c->id, 'customer_id' => $customer->id,
            'collector_user_id' => $u->id, 'assigned_by' => $u->id, 'assigned_at' => now(),
        ]);

        return [$customer, $this->account($c, $b, $u, $balance, $customer->id, $status)];
    }

    private function account(Company $c, Branch $b, User $u, string $balance, int $customerId, string $status = 'pending'): AccountReceivable
    {
        $sale = Sale::create(['company_id' => $c->id, 'branch_id' => $b->id, 'user_id' => $u->id, 'customer_id' => $customerId, 'sale_number' => 'CXC-'.strtoupper(substr(uniqid(), -8)), 'status' => 'completed', 'completed_at' => now()]);

        return AccountReceivable::create(['company_id' => $c->id, 'branch_id' => $b->id, 'customer_id' => $customerId, 'sale_id' => $sale->id, 'issued_at' => today()->subMonth(), 'due_date' => today($c->timezone)->addDays(7), 'original_amount' => $balance, 'balance_due' => $balance, 'status' => $status, 'currency_code' => 'CRC']);
    }
}
