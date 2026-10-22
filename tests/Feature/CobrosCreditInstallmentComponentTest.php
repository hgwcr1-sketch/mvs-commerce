<?php

namespace Tests\Feature;

use App\Models\CobrosCredit;
use App\Models\CobrosCreditInstallment;
use App\Models\CobrosCreditInstallmentComponent;
use App\Models\CobrosCreditInstallmentApplication;
use App\Models\CobrosReceipt;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\AccountReceivable;
use App\Models\PaymentMethod;
use App\Models\CobrosAssignment;
use App\Models\Role;
use App\Models\Permission;
use App\Models\Sale;
use App\Models\AccountReceivablePayment;
use App\Services\CompanyLicenseService;
use App\Services\PaymentMethodProvisioner;
use Illuminate\Database\QueryException;
use App\Models\CobrosCreditRequest;
use App\Models\CobrosRoute;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Services\Cobros\CreditInstallmentComponentAllocator;
use App\Models\CobrosRenewalLateFeeObligation;
use App\Models\CobrosRenewalLateFeePayment;
use App\Services\Cobros\RenewalLateFeeObligationService;
use Tests\TestCase;

class CobrosCreditInstallmentComponentTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['trade_name' => 'Componentes '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        session(['active_company_id' => $company->id]);
        $collector = User::factory()->create(['is_active' => true]);
        $route = CobrosRoute::create(['company_id' => $company->id, 'name' => 'Ruta Componentes', 'active' => true]);
        $request = CobrosCreditRequest::create(['company_id' => $company->id, 'cobros_route_id' => $route->id, 'collector_user_id' => $collector->id, 'applicant_type' => 'nuevo', 'status' => CobrosCreditRequest::STATUS_APPROVED, 'identification_type' => 'cedula', 'identification' => uniqid(), 'name' => 'Componente', 'phone' => '88888888', 'address' => 'Prueba', 'amount_requested' => '10000']);
        $credit = CobrosCredit::create(['company_id' => $company->id, 'credit_request_id' => $request->id, 'cobros_route_id' => $route->id, 'collector_user_id' => $collector->id, 'principal' => '10000', 'interest_type' => CobrosCredit::INTEREST_NONE, 'interest_value' => '0', 'total_due' => '10000', 'installment_count' => 1, 'frequency' => CobrosCredit::FREQUENCY_MONTHLY, 'first_due_date' => now()->addWeek(), 'late_fee_type' => CobrosCredit::LATE_FEE_NONE, 'late_fee_value' => '0', 'late_fee_grace_days' => 0, 'status' => CobrosCredit::STATUS_DISBURSED, 'terms_snapshot' => ['principal' => '10000']]);
        $installment = CobrosCreditInstallment::create(['credit_id' => $credit->id, 'number' => 1, 'due_date' => now()->addWeek(), 'contractual_amount' => '10000', 'applied_amount' => '0', 'status' => CobrosCreditInstallment::STATUS_PENDING]);
        return [$company, $credit, $installment];
    }

    public function test_all_allowed_components_are_precise_and_related(): void
    {
        [, $credit, $installment] = $this->fixture();
        foreach (CobrosCreditInstallmentComponent::KINDS as $kind) {
            $component = CobrosCreditInstallmentComponent::create(['credit_id' => $credit->id, 'installment_id' => $installment->id, 'kind' => $kind, 'original_amount' => '2500.1250', 'applied_amount' => '0.1250']);
            $this->assertSame($installment->id, $component->installment->id);
            $this->assertSame('2500.1250', (string) $component->fresh()->original_amount);
        }
        $this->assertCount(4, $installment->fresh()->components);
        $this->assertCount(4, $credit->fresh()->installmentComponents);
    }

    public function test_invalid_amounts_and_foreign_installment_are_rejected(): void
    {
        [, $credit, $installment] = $this->fixture();
        foreach ([['original_amount' => '-1', 'applied_amount' => '0'], ['original_amount' => '1', 'applied_amount' => '2']] as $amounts) {
            $this->expectException(ValidationException::class);
            try { CobrosCreditInstallmentComponent::create($amounts + ['credit_id' => $credit->id, 'installment_id' => $installment->id, 'kind' => 'principal_new']); } finally { $this->expectException(ValidationException::class); }
        }
    }

    public function test_component_rejects_installment_from_another_credit(): void
    {
        [, $credit, $installment] = $this->fixture();
        $other = $this->fixture();
        $this->expectException(ValidationException::class);
        CobrosCreditInstallmentComponent::create(['credit_id' => $credit->id, 'installment_id' => $other[2]->id, 'kind' => 'principal_new', 'original_amount' => '1', 'applied_amount' => '0']);
    }

    public function test_component_rejects_missing_foreign_keys(): void
    {
        $this->expectException(ValidationException::class);
        CobrosCreditInstallmentComponent::create(['credit_id' => 999999, 'installment_id' => 999999, 'kind' => 'principal_new', 'original_amount' => '1', 'applied_amount' => '0']);
    }

    public function test_component_cannot_cross_tenant_context(): void
    {
        [, $credit, $installment] = $this->fixture();
        $other = $this->fixture();
        session(['active_company_id' => $other[0]->id]);
        $this->expectException(ValidationException::class);
        CobrosCreditInstallmentComponent::create(['credit_id' => $credit->id, 'installment_id' => $installment->id, 'kind' => 'principal_new', 'original_amount' => '1', 'applied_amount' => '0']);
    }

    public function test_component_application_partial_unique_indexes_exist_without_enabling_writes(): void
    {
        $indexes = collect(DB::select("PRAGMA index_list('cobros_credit_installment_applications')"))->pluck('name')->all();
        $this->assertContains('cobros_credit_installment_applications_receipt_installment_legacy_unique', $indexes);
        $this->assertContains('cobros_credit_installment_applications_receipt_installment_component_unique', $indexes);
    }

    public function test_real_application_uniqueness_for_legacy_and_components(): void
    {
        [$company, $credit, $installment] = $this->fixture();
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'CP'.uniqid()]);
        $customer = Customer::create(['company_id' => $company->id, 'identification_type' => 'cedula', 'identification' => uniqid(), 'name' => 'Cliente Componentes']);
        $account = AccountReceivable::create(['company_id' => $company->id, 'cobros_route_id' => $credit->cobros_route_id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'issued_at' => today(), 'due_date' => today(), 'original_amount' => '10000', 'balance_due' => '10000', 'status' => AccountReceivable::STATUS_PENDING, 'currency_code' => 'CRC']);
        $credit->forceFill(['account_receivable_id' => $account->id])->save();
        $method = PaymentMethod::create(['company_id' => $company->id, 'code' => 'cash-'.uniqid(), 'name' => 'Efectivo', 'type' => PaymentMethod::TYPE_CASH, 'is_system' => false, 'is_active' => true, 'affects_cash' => true, 'requires_reference' => false, 'allows_change' => true, 'sort_order' => 1]);
        $receipt = CobrosReceipt::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'account_receivable_id' => $account->id, 'collector_user_id' => $credit->collector_user_id, 'payment_method_id' => $method->id, 'amount' => '5000', 'currency_code' => 'CRC', 'client_token' => uniqid(), 'payload_hash' => hash('sha256', uniqid()), 'paid_at' => now()]);
        $app = ['company_id' => $company->id, 'credit_id' => $credit->id, 'installment_id' => $installment->id, 'account_receivable_id' => $account->id, 'cobros_receipt_id' => $receipt->id, 'amount' => '1'];
        CobrosCreditInstallmentApplication::create($app);
        $this->expectException(QueryException::class);
        CobrosCreditInstallmentApplication::create($app);
    }

    public function test_component_applications_are_unique_and_bound_to_their_credit(): void
    {
        [$company, $credit, $installment] = $this->fixture();
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'CP'.uniqid()]);
        $customer = Customer::create(['company_id' => $company->id, 'identification_type' => 'cedula', 'identification' => uniqid(), 'name' => 'Cliente Componentes']);
        $account = AccountReceivable::create(['company_id' => $company->id, 'cobros_route_id' => $credit->cobros_route_id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'issued_at' => today(), 'due_date' => today(), 'original_amount' => '10000', 'balance_due' => '10000', 'status' => AccountReceivable::STATUS_PENDING, 'currency_code' => 'CRC']);
        $method = PaymentMethod::create(['company_id' => $company->id, 'code' => 'cash-'.uniqid(), 'name' => 'Efectivo', 'type' => PaymentMethod::TYPE_CASH, 'is_system' => false, 'is_active' => true, 'affects_cash' => true, 'requires_reference' => false, 'allows_change' => true, 'sort_order' => 1]);
        $receipt = CobrosReceipt::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'account_receivable_id' => $account->id, 'collector_user_id' => $credit->collector_user_id, 'payment_method_id' => $method->id, 'amount' => '5000', 'currency_code' => 'CRC', 'client_token' => uniqid(), 'payload_hash' => hash('sha256', uniqid()), 'paid_at' => now()]);
        $componentA = CobrosCreditInstallmentComponent::create(['credit_id' => $credit->id, 'installment_id' => $installment->id, 'kind' => 'principal_new', 'original_amount' => '6000', 'applied_amount' => '0']);
        $componentB = CobrosCreditInstallmentComponent::create(['credit_id' => $credit->id, 'installment_id' => $installment->id, 'kind' => 'interest_current', 'original_amount' => '4000', 'applied_amount' => '0']);
        $app = ['company_id' => $company->id, 'credit_id' => $credit->id, 'installment_id' => $installment->id, 'account_receivable_id' => $account->id, 'cobros_receipt_id' => $receipt->id, 'amount' => '1'];
        CobrosCreditInstallmentApplication::create($app + ['component_id' => $componentA->id]);
        CobrosCreditInstallmentApplication::create($app + ['component_id' => $componentB->id]);
        $this->assertSame(2, CobrosCreditInstallmentApplication::where('cobros_receipt_id', $receipt->id)->count());
        [$otherCompany, $otherCredit, $otherInstallment] = $this->fixture();
        $foreignComponent = CobrosCreditInstallmentComponent::create(['credit_id' => $otherCredit->id, 'installment_id' => $otherInstallment->id, 'kind' => 'principal_new', 'original_amount' => '1', 'applied_amount' => '0']);
        session(['active_company_id' => $company->id]);
        try {
            CobrosCreditInstallmentApplication::create($app + ['component_id' => $foreignComponent->id]);
            $this->fail('Se aceptó un componente de otro crédito/empresa.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        $this->expectException(QueryException::class);
        CobrosCreditInstallmentApplication::create($app + ['component_id' => $componentA->id]);
    }

    public function test_allocator_distributes_partial_payment_and_replays_idempotently(): void
    {
        [$company, $credit, $installment] = $this->fixture();
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'CP'.uniqid()]);
        $customer = Customer::create(['company_id' => $company->id, 'identification_type' => 'cedula', 'identification' => uniqid(), 'name' => 'Cliente Componentes']);
        $account = AccountReceivable::create(['company_id' => $company->id, 'cobros_route_id' => $credit->cobros_route_id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'issued_at' => today(), 'due_date' => today(), 'original_amount' => '10000', 'balance_due' => '10000', 'status' => AccountReceivable::STATUS_PENDING, 'currency_code' => 'CRC']);
        $method = PaymentMethod::create(['company_id' => $company->id, 'code' => 'cash-'.uniqid(), 'name' => 'Efectivo', 'type' => PaymentMethod::TYPE_CASH, 'is_system' => false, 'is_active' => true, 'affects_cash' => true, 'requires_reference' => false, 'allows_change' => true, 'sort_order' => 1]);
        $credit->forceFill(['account_receivable_id' => $account->id])->save();
        $receipt = CobrosReceipt::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'account_receivable_id' => $account->id, 'collector_user_id' => $credit->collector_user_id, 'payment_method_id' => $method->id, 'amount' => '5000', 'currency_code' => 'CRC', 'client_token' => uniqid(), 'payload_hash' => hash('sha256', uniqid()), 'paid_at' => now()]);
        CobrosCreditInstallmentComponent::create(['credit_id' => $credit->id, 'installment_id' => $installment->id, 'kind' => 'principal_new', 'original_amount' => '6000', 'applied_amount' => '0']);
        CobrosCreditInstallmentComponent::create(['credit_id' => $credit->id, 'installment_id' => $installment->id, 'kind' => 'interest_current', 'original_amount' => '4000', 'applied_amount' => '0']);
        $service = app(\App\Services\Cobros\CreditInstallmentService::class);
        $first = $service->apply($company, $account, '5000', $receipt);
        $appliedBeforeReplay = (string) $installment->fresh()->applied_amount;
        $componentsBeforeReplay = (string) CobrosCreditInstallmentComponent::where('installment_id', $installment->id)->sum('applied_amount');
        $second = $service->apply($company, $account, '5000', $receipt);
        $this->assertCount(2, $first);
        $this->assertCount(2, $second);
        $this->assertSame(0, bccomp((string) $installment->fresh()->applied_amount, '5000', 4));
        $this->assertSame(0, bccomp((string) CobrosCreditInstallmentComponent::where('installment_id', $installment->id)->sum('applied_amount'), '5000', 4));
        $this->assertSame(0, bccomp($appliedBeforeReplay, (string) $installment->fresh()->applied_amount, 4));
        $this->assertSame(0, bccomp($componentsBeforeReplay, (string) CobrosCreditInstallmentComponent::where('installment_id', $installment->id)->sum('applied_amount'), 4));
        $this->assertSame(0, bccomp((string) $installment->fresh()->contractual_amount, '10000', 4));
        $this->assertSame(0, bccomp((string) bcsub((string) $installment->fresh()->contractual_amount, (string) $installment->fresh()->applied_amount, 4), '5000', 4));
        $summary = $service->applicationForReceipt($company, $receipt);
        $this->assertSame(0, bccomp((string) $summary['amount'], '5000', 4));
        $this->assertCount(2, $summary['lines'][0]['components']);
    }

    public function test_secure_payment_e2e_applies_components_and_replays_once(): void
    {
        [$company, $credit, $installment] = $this->fixture();
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Sucursal E2E', 'code' => 'E2E'.uniqid()]);
        $collector = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Cobrador E2E', 'is_active' => true]);
        $permission = Permission::firstOrCreate(['name' => 'cobros.cobrar'], ['label' => 'Cobrar', 'module' => 'CobrOS', 'is_active' => true]);
        $role->permissions()->attach($permission->id);
        $collector->companies()->attach($company->id, ['role_id' => $role->id]);
        $collector->branches()->attach($branch->id);
        app(CompanyLicenseService::class)->updateModules($company, User::factory()->create(['is_platform_admin' => true, 'is_active' => true]), ['cobros']);
        app(PaymentMethodProvisioner::class)->provision($company);
        $customer = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente E2E', 'is_active' => true]);
        CobrosAssignment::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'collector_user_id' => $collector->id, 'assigned_by' => $collector->id, 'assigned_at' => now()]);
        $account = AccountReceivable::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'issued_at' => today(), 'due_date' => today()->addDay(), 'original_amount' => '10000', 'balance_due' => '10000', 'status' => AccountReceivable::STATUS_PENDING, 'currency_code' => 'CRC']);
        $credit->forceFill(['account_receivable_id' => $account->id, 'collector_user_id' => $collector->id])->save();
        CobrosCreditInstallmentComponent::create(['credit_id' => $credit->id, 'installment_id' => $installment->id, 'kind' => 'principal_new', 'original_amount' => '6000', 'applied_amount' => '0']);
        CobrosCreditInstallmentComponent::create(['credit_id' => $credit->id, 'installment_id' => $installment->id, 'kind' => 'interest_current', 'original_amount' => '4000', 'applied_amount' => '0']);
        $payload = ['amount' => '5000', 'payment_method_id' => PaymentMethod::where('company_id', $company->id)->where('code', 'cash')->firstOrFail()->id, 'reference' => 'E2E-CASH', 'client_token' => 'e2e-'.uniqid()];
        $service = app(\App\Services\Cobros\SecurePaymentService::class);
        $first = $service->register($collector, $company, $account, $payload);
        $second = $service->register($collector, $company, $account, $payload);
        $this->assertFalse($first['replayed']);
        $this->assertTrue($second['replayed']);
        $this->assertSame(1, CobrosReceipt::where('company_id', $company->id)->count());
        $this->assertSame(1, AccountReceivablePayment::where('company_id', $company->id)->count());
        $this->assertSame(0, bccomp((string) $installment->fresh()->applied_amount, '5000', 4));
        $this->assertSame(0, bccomp((string) CobrosCreditInstallmentComponent::where('installment_id', $installment->id)->sum('applied_amount'), '5000', 4));
    }

    public function test_transferred_late_fee_partial_payment_is_idempotent(): void
    {
        [$company, $credit, $installment] = $this->fixture();
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Sucursal Mora', 'code' => 'M'.uniqid()]);
        $customer = Customer::create(['company_id' => $company->id, 'identification_type' => 'cedula', 'identification' => uniqid(), 'name' => 'Cliente Mora']);
        $account = AccountReceivable::create(['company_id' => $company->id, 'cobros_route_id' => $credit->cobros_route_id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'issued_at' => today(), 'due_date' => today(), 'original_amount' => '10000', 'balance_due' => '10000', 'status' => AccountReceivable::STATUS_PENDING, 'currency_code' => 'CRC']);
        $method = PaymentMethod::create(['company_id' => $company->id, 'code' => 'cash-'.uniqid(), 'name' => 'Efectivo', 'type' => PaymentMethod::TYPE_CASH, 'is_system' => false, 'is_active' => true, 'affects_cash' => true, 'requires_reference' => false, 'allows_change' => true, 'sort_order' => 1]);
        $receipt = CobrosReceipt::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'account_receivable_id' => $account->id, 'collector_user_id' => $credit->collector_user_id, 'payment_method_id' => $method->id, 'amount' => '4000', 'currency_code' => 'CRC', 'client_token' => uniqid(), 'payload_hash' => hash('sha256', uniqid()), 'paid_at' => now()]);
        $service = app(RenewalLateFeeObligationService::class);
        $obligation = $service->freeze($company->id, $credit->id, 77, '10000');
        $first = $service->apply($obligation, $receipt, '4000');
        $second = $service->apply($obligation, $receipt, '4000');
        $this->assertSame(1, CobrosRenewalLateFeeObligation::whereKey($obligation->id)->first()->payments()->count());
        $this->assertSame(0, bccomp((string) $obligation->fresh()->applied_amount, '4000', 4));
        $this->assertSame(0, bccomp($service->balance($obligation->fresh()), '6000', 4));
        $this->assertSame($first->id, $second->id);
    }

    public function test_transferred_late_fee_total_cross_company_and_amount_guards(): void
    {
        [$company, $credit] = $this->fixture();
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Sucursal Mora 2', 'code' => 'M2'.uniqid()]);
        $customer = Customer::create(['company_id' => $company->id, 'identification_type' => 'cedula', 'identification' => uniqid(), 'name' => 'Cliente Mora 2']);
        $account = AccountReceivable::create(['company_id' => $company->id, 'cobros_route_id' => $credit->cobros_route_id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'issued_at' => today(), 'due_date' => today(), 'original_amount' => '10000', 'balance_due' => '10000', 'status' => AccountReceivable::STATUS_PENDING, 'currency_code' => 'CRC']);
        $method = PaymentMethod::create(['company_id' => $company->id, 'code' => 'cash-'.uniqid(), 'name' => 'Efectivo', 'type' => PaymentMethod::TYPE_CASH, 'is_system' => false, 'is_active' => true, 'affects_cash' => true, 'requires_reference' => false, 'allows_change' => true, 'sort_order' => 1]);
        $makeReceipt = fn (Company $c, Branch $b, Customer $u, string $amount) => CobrosReceipt::create(['company_id' => $c->id, 'branch_id' => $b->id, 'customer_id' => $u->id, 'account_receivable_id' => $account->id, 'collector_user_id' => $credit->collector_user_id, 'payment_method_id' => $method->id, 'amount' => $amount, 'currency_code' => 'CRC', 'client_token' => uniqid(), 'payload_hash' => hash('sha256', uniqid()), 'paid_at' => now()]);
        $one = $makeReceipt($company, $branch, $customer, '4000');
        $two = $makeReceipt($company, $branch, $customer, '6000');
        $service = app(RenewalLateFeeObligationService::class);
        $obligation = $service->freeze($company->id, $credit->id, 78, '10000');
        $service->apply($obligation, $one, '4000');
        $service->apply($obligation, $two, '6000');
        $this->assertSame(0, bccomp($service->balance($obligation->fresh()), '0', 4));
        $this->assertSame(CobrosRenewalLateFeeObligation::STATUS_PAID, $obligation->fresh()->status);
        $this->assertSame($two->id, $service->apply($obligation, $two, '6000')->id);
        $third = $makeReceipt($company, $branch, $customer, '10000');
        try { $service->apply($obligation, $third, '1'); $this->fail('Debe rechazar mora ya saldada.'); } catch (ValidationException) { $this->assertTrue(true); }
        try { $service->apply($obligation, $third, '7000'); $this->fail('Debe rechazar importe sobre saldo/recibo.'); } catch (ValidationException) { $this->assertTrue(true); }
        $other = Company::create(['trade_name' => 'Otra Mora', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $foreign = CobrosReceipt::create(['company_id' => $other->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'account_receivable_id' => $account->id, 'collector_user_id' => $credit->collector_user_id, 'payment_method_id' => $method->id, 'amount' => '1', 'currency_code' => 'CRC', 'client_token' => uniqid(), 'payload_hash' => hash('sha256', uniqid()), 'paid_at' => now()]);
        try { $service->apply($obligation, $foreign, '1'); $this->fail('Debe rechazar recibo de otra empresa.'); } catch (ValidationException) { $this->assertTrue(true); }
        $this->assertSame(2, CobrosRenewalLateFeePayment::where('obligation_id', $obligation->id)->count());
    }
}
