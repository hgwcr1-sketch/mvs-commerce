<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySequence;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackfillCustomerCodesTest extends TestCase
{
    use RefreshDatabase;

    public function test_numeric_maximum_ignores_manual_codes_and_crosses_six_digits_in_chunks(): void
    {
        $company = $this->company();
        $existing = [];
        foreach (['000009', '999999', '1000000', '99999999-ABC', 'MANUAL'] as $code) {
            $existing[] = $this->customer($company, $code);
        }
        $first = $this->customer($company);
        $second = $this->customer($company);
        DB::enableQueryLog();
        $this->artisan('customers:backfill-codes', ['--chunk' => 1])->assertSuccessful();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame('1000001', $first->fresh()->customer_code);
        $this->assertSame('1000002', $second->fresh()->customer_code);
        foreach ($existing as $customer) {
            $this->assertSame($customer->customer_code, $customer->fresh()->customer_code);
        }
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/\b(?:CAST|UNSIGNED)\b/i', $query['query']);
        }
        $before = DB::table('customers')->orderBy('id')->get()->toJson();
        $this->artisan('customers:backfill-codes')->assertSuccessful();
        $this->assertSame($before, DB::table('customers')->orderBy('id')->get()->toJson());
        $this->assertSame('1000003', CompanySequence::nextCustomerCode($company->id));
    }

    public function test_company_filter_and_existing_sequence_are_respected(): void
    {
        $a = $this->company();
        $b = $this->company();
        CompanySequence::create(['company_id' => $a->id, 'name' => CompanySequence::CUSTOMER_CODE, 'current_value' => 125]);
        $this->customer($a, '000002');
        $first = $this->customer($a);
        $other = $this->customer($b);
        $this->artisan('customers:backfill-codes', ['--company' => $a->id])->assertSuccessful();
        $this->assertSame('000126', $first->fresh()->customer_code);
        $this->assertNull($other->fresh()->customer_code);
        $this->artisan('customers:backfill-codes')->assertSuccessful();
        $this->assertSame('000001', $other->fresh()->customer_code);
        $this->assertSame('000127', CompanySequence::nextCustomerCode($a->id));
    }

    public function test_dry_run_has_zero_writes_and_predicts_numeric_codes(): void
    {
        $company = $this->company();
        $this->customer($company, '999999');
        $this->customer($company, '999999999X');
        $customer = $this->customer($company);
        $before = DB::table('customers')->orderBy('id')->get()->toJson();
        DB::enableQueryLog();
        $this->artisan('customers:backfill-codes', ['--dry-run' => true, '--company' => $company->id, '--chunk' => 1])
            ->expectsOutput("  ID {$customer->id} → 1000000 ({$customer->name})")
            ->assertSuccessful();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/^select\b/i', $query['query']);
        }
        $this->assertSame($before, DB::table('customers')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('company_sequences', 0);
    }

    public function test_deleted_customer_codes_remain_reserved(): void
    {
        $company = $this->company();
        $this->customer($company, '000010')->delete();
        $customer = $this->customer($company);
        $this->artisan('customers:backfill-codes')->assertSuccessful();
        $this->assertSame('000011', $customer->fresh()->customer_code);
    }

    public function test_numeric_overflow_fails_without_assigning_codes(): void
    {
        $company = $this->company();
        $this->customer($company, '99999999999999999999');
        $customer = $this->customer($company);
        $this->artisan('customers:backfill-codes')->assertFailed();
        $this->assertNull($customer->fresh()->customer_code);
        $this->assertDatabaseCount('company_sequences', 0);
    }

    private function company(): Company
    {
        return Company::create(['trade_name' => uniqid('Empresa '), 'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica', 'is_active' => true]);
    }

    private function customer(Company $company, ?string $code = null): Customer
    {
        return Customer::withoutEvents(fn () => Customer::create([
            'company_id' => $company->id, 'name' => uniqid('Cliente '),
            'customer_type' => 'individual', 'customer_code' => $code, 'is_active' => true,
        ]));
    }
}
