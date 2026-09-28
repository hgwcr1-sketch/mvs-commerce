<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\Loyalty\LoyaltyRegistrationIncentiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LoyaltyRegistrationIncentiveP19Test extends TestCase
{
    use RefreshDatabase;

    public function test_claim_audits_customer_rule_benefit_configurator_and_award_date(): void
    {
        [$company, $branch, $customer, $configurator] = $this->context();
        $service = app(LoyaltyRegistrationIncentiveService::class);
        $setting = $service->configure($company, true, 'percentage', '12.5000', ['configured_by' => $configurator->id]);
        $claim = $service->tryAwardForRegistration($customer, $company, $branch->id);

        $this->assertSame($company->id, $claim->company_id);
        $this->assertSame($customer->id, $claim->customer_id);
        $this->assertSame($setting->id, $claim->incentive_rule_id);
        $this->assertSame('percentage', $claim->benefit_type);
        $this->assertSame('12.5000', $claim->benefit_value);
        $this->assertSame($configurator->id, $claim->configured_by);
        $this->assertSame($branch->id, $claim->branch_id);
        $this->assertNotNull($claim->awarded_at);
    }

    public function test_consumption_audits_purchase_and_branch_without_changing_award_date(): void
    {
        [$company, $branch, $customer, $configurator] = $this->context();
        $service = app(LoyaltyRegistrationIncentiveService::class);
        $service->configure($company, true, 'fixed', '10', ['configured_by' => $configurator->id]);
        $claim = $service->tryAwardForRegistration($customer, $company);
        $awardedAt = $claim->awarded_at;
        $sale = $this->sale($company, $branch, $customer, $configurator);

        $consumed = $service->consume($claim->id, $customer, $company, $sale->id, discountAmount: '10', context: ['purchase_amount' => '100', 'branch_id' => $branch->id]);

        $this->assertSame($sale->id, $consumed->sale_id);
        $this->assertSame($branch->id, $consumed->branch_id);
        $this->assertTrue($awardedAt->equalTo($consumed->awarded_at));
        $this->assertNotNull($consumed->used_at);
    }

    public function test_foreign_configurator_is_rejected_and_audit_remains_company_isolated(): void
    {
        [$companyA, , $customerA] = $this->context();
        [$companyB, , , $userB] = $this->context();
        $service = app(LoyaltyRegistrationIncentiveService::class);

        $this->expectException(ValidationException::class);
        try {
            $service->configure($companyA, true, 'fixed', '10', ['configured_by' => $userB->id]);
        } finally {
            $this->assertDatabaseMissing('loyalty_registration_incentive_claims', ['company_id' => $companyA->id, 'customer_id' => $customerA->id]);
            $this->assertDatabaseMissing('loyalty_registration_incentive_claims', ['company_id' => $companyB->id, 'customer_id' => $customerA->id]);
        }
    }

    private function context(): array
    {
        $company = Company::create(['trade_name' => 'Empresa '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'AU'.uniqid(), 'is_active' => true]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Cliente '.uniqid(), 'customer_type' => 'individual', 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Auditor '.uniqid(), 'is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);

        return [$company, $branch, $customer, $user];
    }

    private function sale(Company $company, Branch $branch, Customer $customer, User $user): Sale
    {
        return Sale::create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $user->id, 'customer_id' => $customer->id,
            'checkout_token' => (string) Str::uuid(), 'request_fingerprint' => hash('sha256', Str::random()), 'sale_number' => 'P19-'.Str::random(8),
            'document_type' => Sale::DOCUMENT_ELECTRONIC_TICKET, 'sale_condition' => Sale::CONDITION_CASH, 'status' => Sale::STATUS_COMPLETED,
            'currency_code' => 'CRC', 'exchange_rate' => '1.0000', 'subtotal' => '100.0000', 'discount_total' => '0.0000',
            'tax_total' => '0.0000', 'rounding_total' => '0.0000', 'total' => '100.0000', 'paid_total' => '100.0000', 'balance_due' => '0.0000', 'completed_at' => now(),
        ]);
    }
}
