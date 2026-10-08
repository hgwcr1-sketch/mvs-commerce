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
use App\Services\CompanyLicenseService;
use App\Services\PaymentMethodProvisioner;
use App\Services\Sales\AccountsReceivableService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Fase 6B — límites de precisión monetaria (DECIMAL(19,4) / BCMath, sin float).
 *
 * Reglas certificadas aquí: cero, fracciones de colón y saldos de 4 decimales
 * en las dos capas, con la MISMA regla: montos con más de cuatro decimales se
 * RECHAZAN (no se redondean), la disponibilidad de crédito se acumula en
 * string/BCMath y el límite de crédito se compara en escala 4.
 */
class CobrosMoneyPrecision6BTest extends TestCase
{
    use RefreshDatabase;

    public function test_zero_amount_is_rejected_without_writes(): void
    {
        [$c, $b, $u] = $this->context();
        [, $account] = $this->customerWithAccount($c, $b, $u, '500.0000');

        foreach (['0', '0.0000'] as $amount) {
            $this->actingAs($u)->withSession(['active_company_id' => $c->id])->post(
                route('cobros.collector.payments.store', $account),
                $this->confirm($this->payload($amount, $this->method($c, 'cash')))
            )->assertSessionHasErrors('amount');
        }

        $this->assertSame('500.0000', (string) $account->fresh()->balance_due);
        $this->assertDatabaseCount('accounts_receivable_payments', 0);
        $this->assertDatabaseCount('cobros_receipts', 0);
    }

    public function test_fractional_colon_payment_keeps_scale_four_exactly(): void
    {
        [$c, $b, $u] = $this->context();
        [, $account] = $this->customerWithAccount($c, $b, $u, '10.0004');

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.payments.store', $account),
            $this->confirm($this->payload('0.0001', $this->method($c, 'cash')))
        )->assertRedirect(route('cobros.collector.payments.receipt', 'RCB-00000001'));

        $payment = AccountReceivablePayment::sole();
        $this->assertSame('0.0001', (string) $payment->amount);
        $this->assertMatchesRegularExpression('/^\d+\.\d{4}$/', (string) $payment->amount);
        $this->assertSame('10.0003', (string) $account->fresh()->balance_due);
        $this->assertSame('0.0001', (string) CobrosReceipt::sole()->amount);
        $this->assertMatchesRegularExpression('/^\d+\.\d{4}$/', (string) $account->fresh()->balance_due);
    }

    public function test_exact_total_at_scale_four_marks_account_paid_at_zero(): void
    {
        [$c, $b, $u] = $this->context();
        [, $account] = $this->customerWithAccount($c, $b, $u, '0.0001');

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.payments.store', $account),
            $this->confirm($this->payload('0.0001', $this->method($c, 'cash')))
        )->assertRedirect(route('cobros.collector.payments.receipt', 'RCB-00000001'));

        $account->refresh();
        $this->assertSame('0.0000', (string) $account->balance_due);
        $this->assertSame(AccountReceivable::STATUS_PAID, $account->status);
        $this->assertSame('0.0001', (string) AccountReceivablePayment::sole()->amount);
    }

    public function test_one_ten_thousandth_above_balance_is_rejected_without_writes(): void
    {
        [$c, $b, $u] = $this->context();
        [, $account] = $this->customerWithAccount($c, $b, $u, '0.0001');

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.payments.store', $account),
            $this->confirm($this->payload('0.0002', $this->method($c, 'cash')))
        )->assertSessionHasErrors('amount');

        $this->assertSame('0.0001', (string) $account->fresh()->balance_due);
        $this->assertDatabaseCount('accounts_receivable_payments', 0);
        $this->assertDatabaseCount('cobros_receipts', 0);
    }

    public function test_secure_layer_rejects_more_than_four_decimals_and_twelve_digits(): void
    {
        [$c, $b, $u] = $this->context();
        [, $account] = $this->customerWithAccount($c, $b, $u, '999.9999');

        foreach (['0.00005', '1.23456', '1234567890123.0000'] as $amount) {
            $this->actingAs($u)->withSession(['active_company_id' => $c->id])->post(
                route('cobros.collector.payments.store', $account),
                $this->confirm($this->payload($amount, $this->method($c, 'cash')))
            )->assertSessionHasErrors('amount');
        }

        $this->assertSame('999.9999', (string) $account->fresh()->balance_due);
        $this->assertDatabaseCount('accounts_receivable_payments', 0);
        $this->assertDatabaseCount('cobros_receipts', 0);
    }

    public function test_cxc_layer_rejects_more_than_four_decimals_like_the_secure_layer(): void
    {
        [$c, $b, $u] = $this->context();
        [, $account] = $this->customerWithAccount($c, $b, $u, '20.0001');
        $method = $this->method($c, 'cash');
        $service = app(AccountsReceivableService::class);

        foreach (['10.00005', '10.00004', '0.00005'] as $amount) {
            try {
                $service->payInCustody($account, [
                    'amount' => $amount, 'payment_method_id' => $method->id,
                ], $u, (int) $c->id, (int) $b->id);
                $this->fail("El monto {$amount} (más de cuatro decimales) debía ser rechazado.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('amount', $e->errors());
            }
        }

        $this->assertDatabaseCount('accounts_receivable_payments', 0);
        $this->assertSame('20.0001', (string) $account->fresh()->balance_due);
        $this->assertSame(AccountReceivable::STATUS_PENDING, $account->fresh()->status);

        // Misma regla, monto válido: la capa CxC sigue aceptando cuatro decimales.
        $payment = $service->payInCustody($account, [
            'amount' => '10.0001', 'payment_method_id' => $method->id,
        ], $u, (int) $c->id, (int) $b->id);

        $this->assertSame('10.0001', (string) $payment->amount);
        $this->assertSame('10.0000', (string) $account->fresh()->balance_due);
    }

    public function test_credit_availability_accumulates_fractional_balances_exactly(): void
    {
        [$c, $b, $u] = $this->context();
        $customer = $this->creditCustomer($c, '1.0000', 30);
        foreach (['0.1000', '0.2000', '0.3000'] as $balance) {
            $this->account($c, $b, $u, $balance, $customer->id);
        }

        // Disponibilidad exacta 1.0000 - 0.6000 = 0.4000 (nunca 0.3999 por float).
        $sale = $this->creditSale($c, $b, $u, $customer, '0.4000');
        $account = app(AccountsReceivableService::class)->createForSale($sale, $customer);

        $this->assertSame('0.4000', (string) $account->balance_due);
        $this->assertSame(AccountReceivable::STATUS_PENDING, $account->status);
    }

    public function test_credit_availability_stays_exact_at_large_magnitudes(): void
    {
        [$c, $b, $u] = $this->context();
        $customer = $this->creditCustomer($c, '10000000000.10', 30);
        foreach (['5000000000.0001', '5000000000.0001'] as $balance) {
            $this->account($c, $b, $u, $balance, $customer->id);
        }

        $service = app(AccountsReceivableService::class);

        // Exacto: 10000000000.0002 de usado => disponible 0.0998.
        try {
            $service->createForSale($this->creditSale($c, $b, $u, $customer, '0.0999'), $customer);
            $this->fail('La disponibilidad exacta es 0.0998: 0.0999 debe ser rechazado.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('credit', $e->errors());
        }

        $account = $service->createForSale($this->creditSale($c, $b, $u, $customer, '0.0998'), $customer);
        $this->assertSame('0.0998', (string) $account->balance_due);
    }

    public function test_credit_limit_is_the_exact_boundary_at_scale_four(): void
    {
        [$c, $b, $u] = $this->context();
        $customer = $this->creditCustomer($c, '0.6000', 30);
        $service = app(AccountsReceivableService::class);

        try {
            $service->createForSale($this->creditSale($c, $b, $u, $customer, '0.6001'), $customer);
            $this->fail('Un crédito de 0.6001 con límite 0.6000 debía ser rechazado.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('credit', $e->errors());
        }
        $this->assertDatabaseCount('accounts_receivable', 0);

        $account = $service->createForSale($this->creditSale($c, $b, $u, $customer, '0.6000'), $customer);
        $this->assertSame('0.6000', (string) $account->balance_due);
        $this->assertSame('0.6000', (string) $account->original_amount);
    }

    public function test_zero_credit_limit_is_rejected_at_scale_four(): void
    {
        [$c, $b, $u] = $this->context();
        $customer = $this->creditCustomer($c, '0.0000', 30);

        try {
            app(AccountsReceivableService::class)->createForSale($this->creditSale($c, $b, $u, $customer, '0.0100'), $customer);
            $this->fail('Un crédito de 0.0000 debía ser rechazado.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('credit', $e->errors());
        }
        $this->assertDatabaseCount('accounts_receivable', 0);
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | --------------------------------------------------------------------- */

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
        $company = Company::create(['trade_name' => 'Precisión '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Sucursal '.substr(uniqid(), -5), 'code' => 'P'.random_int(1, 99), 'is_active' => true]);
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

    private function creditCustomer(Company $c, string $limit, int $days): Customer
    {
        return Customer::create([
            'company_id' => $c->id, 'customer_type' => 'individual', 'name' => 'Crédito '.uniqid(),
            'credit_limit' => $limit, 'credit_days' => $days, 'is_active' => true,
        ]);
    }

    private function creditSale(Company $c, Branch $b, User $u, Customer $customer, string $total): Sale
    {
        return Sale::create([
            'company_id' => $c->id, 'branch_id' => $b->id, 'user_id' => $u->id, 'customer_id' => $customer->id,
            'sale_number' => 'CXC-'.strtoupper(substr(uniqid(), -8)), 'sale_condition' => Sale::CONDITION_CREDIT,
            'status' => 'completed', 'completed_at' => now(), 'currency_code' => 'CRC',
            'subtotal' => $total, 'total' => $total, 'paid_total' => '0.0000', 'balance_due' => $total,
        ]);
    }
}
