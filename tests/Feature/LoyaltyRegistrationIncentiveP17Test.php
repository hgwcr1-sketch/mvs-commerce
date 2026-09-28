<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Services\Loyalty\LoyaltyRegistrationIncentiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LoyaltyRegistrationIncentiveP17Test extends TestCase
{
    use RefreshDatabase;

    public function test_selected_branch_is_allowed_and_another_branch_is_blocked(): void
    {
        [$company, $allowed, $customer] = $this->context();
        $blocked = Branch::create(['company_id' => $company->id, 'name' => 'Secundaria', 'code' => 'SEC'.uniqid(), 'is_active' => true]);
        $service = app(LoyaltyRegistrationIncentiveService::class);
        $service->configure($company, true, 'fixed', '10', ['participating_branch_ids' => [$allowed->id]]);
        $service->tryAwardForRegistration($customer, $company);

        $this->assertTrue($service->evaluateForPurchase($customer, $company, '100', context: ['branch_id' => $allowed->id])['eligible']);
        $result = $service->evaluateForPurchase($customer, $company, '100', context: ['branch_id' => $blocked->id]);
        $this->assertFalse($result['eligible']);
        $this->assertSame('branch_not_participating', $result['reason']);
    }

    public function test_null_branch_scope_allows_every_company_branch(): void
    {
        [$company, $first, $customer] = $this->context();
        $second = Branch::create(['company_id' => $company->id, 'name' => 'Segunda', 'code' => 'ALL'.uniqid(), 'is_active' => true]);
        $service = app(LoyaltyRegistrationIncentiveService::class);
        $service->configure($company, true, 'fixed', '10', ['participating_branch_ids' => null]);
        $claim = $service->tryAwardForRegistration($customer, $company);

        $this->assertNull($claim->participating_branch_ids);
        $this->assertTrue($service->evaluateForPurchase($customer, $company, '100', context: ['branch_id' => $first->id])['eligible']);
        $this->assertTrue($service->evaluateForPurchase($customer, $company, '100', context: ['branch_id' => $second->id])['eligible']);
    }

    public function test_offer_rule_allows_normal_sale_and_blocks_offer_without_consuming_claim(): void
    {
        [$company, $branch, $customer] = $this->context();
        $service = app(LoyaltyRegistrationIncentiveService::class);
        $service->configure($company, true, 'fixed', '10', ['allow_offer_products' => false]);
        $claim = $service->tryAwardForRegistration($customer, $company);

        $this->assertTrue($service->evaluateForPurchase($customer, $company, '100', context: ['branch_id' => $branch->id, 'has_offers' => false])['eligible']);
        $blocked = $service->evaluateForPurchase($customer, $company, '100', context: ['branch_id' => $branch->id, 'has_offers' => true]);
        $this->assertSame('offer_products_not_allowed', $blocked['reason']);

        try {
            $service->consume($claim->id, $customer, $company, context: ['purchase_amount' => '100', 'branch_id' => $branch->id, 'has_offers' => true]);
            $this->fail('El consumo debió rechazarse.');
        } catch (ValidationException) {
            $this->assertNull($claim->fresh()->used_at);
        }
    }

    public function test_maximum_discount_caps_percentage_and_is_preserved_in_claim_snapshot(): void
    {
        [$company, $branch, $customer] = $this->context();
        $service = app(LoyaltyRegistrationIncentiveService::class);
        $service->configure($company, true, 'percentage', '50', ['maximum_discount_enabled' => true, 'maximum_discount_amount' => '25.1234']);
        $claim = $service->tryAwardForRegistration($customer, $company);
        $service->configure($company, true, 'percentage', '50', ['maximum_discount_enabled' => true, 'maximum_discount_amount' => '5']);

        $result = $service->evaluateForPurchase($customer, $company, '100', context: ['branch_id' => $branch->id]);
        $this->assertSame('25.1234', $result['discount_amount']);
        $this->assertSame('25.1234', $claim->fresh()->maximum_discount_amount);
    }

    public function test_stacking_can_be_allowed_or_blocked_and_block_does_not_consume(): void
    {
        [$company, $branch, $customer] = $this->context();
        $service = app(LoyaltyRegistrationIncentiveService::class);
        $service->configure($company, true, 'fixed', '10', ['stacking_allowed' => false]);
        $claim = $service->tryAwardForRegistration($customer, $company);
        $blocked = $service->evaluateForPurchase($customer, $company, '100', context: ['branch_id' => $branch->id, 'existing_discount_amount' => '0.0001']);

        $this->assertSame('stacking_not_allowed', $blocked['reason']);
        $this->assertNull($claim->fresh()->used_at);

        [$otherCompany, $otherBranch, $otherCustomer] = $this->context();
        $service->configure($otherCompany, true, 'fixed', '10', ['stacking_allowed' => true]);
        $service->tryAwardForRegistration($otherCustomer, $otherCompany);
        $this->assertTrue($service->evaluateForPurchase($otherCustomer, $otherCompany, '100', context: ['branch_id' => $otherBranch->id, 'existing_discount_amount' => '1'])['eligible']);
    }

    public function test_foreign_branch_is_rejected_in_configuration_and_evaluation(): void
    {
        [$companyA, $branchA, $customerA] = $this->context();
        [$companyB, $branchB] = $this->context();
        $service = app(LoyaltyRegistrationIncentiveService::class);

        try {
            $service->configure($companyA, true, 'fixed', '10', ['participating_branch_ids' => [$branchB->id]]);
            $this->fail('La configuración multiempresa debió rechazarse.');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('loyalty_registration_incentives', ['company_id' => $companyA->id, 'is_enabled' => true]);
        }

        $service->configure($companyA, true, 'fixed', '10', ['participating_branch_ids' => [$branchA->id]]);
        $service->tryAwardForRegistration($customerA, $companyA);
        $this->expectException(ValidationException::class);
        $service->evaluateForPurchase($customerA, $companyA, '100', context: ['branch_id' => $branchB->id]);
    }

    private function context(): array
    {
        $company = Company::create(['trade_name' => 'Empresa '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.uniqid(), 'is_active' => true]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Cliente '.uniqid(), 'customer_type' => 'individual', 'is_active' => true]);

        return [$company, $branch, $customer];
    }
}
