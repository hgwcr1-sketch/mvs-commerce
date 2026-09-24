<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanySequence;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CompanySequenceTest extends TestCase
{
    use RefreshDatabase;

    private function companyWithDeps(string $suffix = ''): array
    {
        $company = Company::create([
            'trade_name' => 'Empresa '.$suffix.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);

        $branch = Branch::create([
            'company_id' => $company->id,
            'name' => 'Sucursal',
            'code' => 'SUC-'.$company->id.'-'.uniqid(),
            'is_active' => true,
        ]);

        $user = User::factory()->create();
        $company->users()->attach($user->id);

        $customer = Customer::create([
            'company_id' => $company->id,
            'name' => 'Cliente Test',
            'identification' => '12345'.uniqid(),
            'email' => 'test'.$company->id.'@mail.com',
        ]);

        return compact('company', 'branch', 'user', 'customer');
    }

    // =========================================================
    // A. SALE_RETURN DESINCRONIZADA
    // =========================================================

    public function test_next_sale_return_skips_existing_number(): void
    {
        ['company' => $company, 'branch' => $branch, 'user' => $user] = $this->companyWithDeps();
        $cid = $company->id;

        CompanySequence::create([
            'company_id' => $cid,
            'name' => 'sale_return',
            'current_value' => 1,
        ]);

        $saleId = DB::table('sales')->insertGetId([
            'company_id' => $cid, 'branch_id' => $branch->id, 'user_id' => $user->id,
            'sale_number' => 'POS-TEST', 'document_type' => 'electronic_ticket',
            'sale_condition' => 'cash', 'status' => 'completed', 'currency_code' => 'CRC',
            'exchange_rate' => 1, 'subtotal' => 10000, 'tax_total' => 1300,
            'discount_total' => 0, 'rounding_total' => 0, 'total' => 11300,
            'paid_total' => 11300, 'balance_due' => 0, 'completed_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sale_returns')->insert([
            'company_id' => $cid, 'branch_id' => $branch->id,
            'sale_id' => $saleId, 'user_id' => $user->id,
            'return_number' => 'DEV-00000002', 'reason' => 'Test',
            'status' => 'completed', 'returned_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = CompanySequence::nextSaleReturnNumber($cid);

        $this->assertSame('DEV-00000003', $result);

        $seq = CompanySequence::where('company_id', $cid)->where('name', 'sale_return')->first();
        $this->assertSame(3, $seq->current_value);
    }

    public function test_next_sale_return_skips_multiple_existing(): void
    {
        ['company' => $company, 'branch' => $branch, 'user' => $user] = $this->companyWithDeps();
        $cid = $company->id;

        CompanySequence::create([
            'company_id' => $cid,
            'name' => 'sale_return',
            'current_value' => 0,
        ]);

        $saleId = DB::table('sales')->insertGetId([
            'company_id' => $cid, 'branch_id' => $branch->id, 'user_id' => $user->id,
            'sale_number' => 'POS-TEST', 'document_type' => 'electronic_ticket',
            'sale_condition' => 'cash', 'status' => 'completed', 'currency_code' => 'CRC',
            'exchange_rate' => 1, 'subtotal' => 10000, 'tax_total' => 1300,
            'discount_total' => 0, 'rounding_total' => 0, 'total' => 11300,
            'paid_total' => 11300, 'balance_due' => 0, 'completed_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (['DEV-00000001', 'DEV-00000002', 'DEV-00000003'] as $num) {
            DB::table('sale_returns')->insert([
                'company_id' => $cid, 'branch_id' => $branch->id,
                'sale_id' => $saleId, 'user_id' => $user->id,
                'return_number' => $num, 'reason' => 'Test',
                'status' => 'completed', 'returned_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $result = CompanySequence::nextSaleReturnNumber($cid);

        $this->assertSame('DEV-00000004', $result);
    }

    // =========================================================
    // B. CREDIT_NOTE DESINCRONIZADA
    // =========================================================

    public function test_next_credit_note_skips_existing_number(): void
    {
        ['company' => $company, 'branch' => $branch, 'user' => $user, 'customer' => $customer] = $this->companyWithDeps();
        $cid = $company->id;

        CompanySequence::create([
            'company_id' => $cid,
            'name' => 'credit_note',
            'current_value' => 1,
        ]);

        $saleId = DB::table('sales')->insertGetId([
            'company_id' => $cid, 'branch_id' => $branch->id, 'user_id' => $user->id,
            'customer_id' => $customer->id,
            'sale_number' => 'POS-TEST', 'document_type' => 'electronic_ticket',
            'sale_condition' => 'cash', 'status' => 'completed', 'currency_code' => 'CRC',
            'exchange_rate' => 1, 'subtotal' => 10000, 'tax_total' => 1300,
            'discount_total' => 0, 'rounding_total' => 0, 'total' => 11300,
            'paid_total' => 11300, 'balance_due' => 0, 'completed_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $returnId = DB::table('sale_returns')->insertGetId([
            'company_id' => $cid, 'branch_id' => $branch->id,
            'sale_id' => $saleId, 'user_id' => $user->id,
            'return_number' => 'DEV-00000001', 'reason' => 'Test',
            'status' => 'completed', 'returned_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('credit_notes')->insert([
            'company_id' => $cid, 'branch_id' => $branch->id,
            'customer_id' => $customer->id, 'sale_id' => $saleId,
            'sale_return_id' => $returnId,
            'credit_note_number' => 'NC-00000002', 'currency_code' => 'CRC',
            'issued_amount' => 10000, 'offset_amount' => 0,
            'applied_amount' => 0, 'balance' => 10000,
            'status' => 'issued', 'reason' => 'Test',
            'issued_by' => $user->id, 'issued_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = CompanySequence::nextCreditNoteNumber($cid);

        $this->assertSame('NC-00000003', $result);
    }

    // =========================================================
    // C. MULTIEMPRESA
    // =========================================================

    public function test_same_consecutive_in_different_companies(): void
    {
        ['company' => $companyA, 'branch' => $branchA, 'user' => $userA] = $this->companyWithDeps('A');
        $companyB = Company::create([
            'trade_name' => 'EmpresaB'.uniqid(),
            'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true,
        ]);

        $saleId = DB::table('sales')->insertGetId([
            'company_id' => $companyA->id, 'branch_id' => $branchA->id, 'user_id' => $userA->id,
            'sale_number' => 'POS-TEST', 'document_type' => 'electronic_ticket',
            'sale_condition' => 'cash', 'status' => 'completed', 'currency_code' => 'CRC',
            'exchange_rate' => 1, 'subtotal' => 10000, 'tax_total' => 1300,
            'discount_total' => 0, 'rounding_total' => 0, 'total' => 11300,
            'paid_total' => 11300, 'balance_due' => 0, 'completed_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sale_returns')->insert([
            'company_id' => $companyA->id, 'branch_id' => $branchA->id,
            'sale_id' => $saleId, 'user_id' => $userA->id,
            'return_number' => 'DEV-00000001', 'reason' => 'Test',
            'status' => 'completed', 'returned_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $resultB = CompanySequence::nextSaleReturnNumber($companyB->id);

        $this->assertSame('DEV-00000001', $resultB);
    }

    public function test_company_a_sequence_independent_from_b(): void
    {
        ['company' => $companyA] = $this->companyWithDeps('A');
        ['company' => $companyB] = $this->companyWithDeps('B');

        $numA1 = CompanySequence::nextSaleReturnNumber($companyA->id);
        $numA2 = CompanySequence::nextSaleReturnNumber($companyA->id);
        $numB1 = CompanySequence::nextSaleReturnNumber($companyB->id);

        $this->assertSame('DEV-00000001', $numA1);
        $this->assertSame('DEV-00000002', $numA2);
        $this->assertSame('DEV-00000001', $numB1);
    }

    // =========================================================
    // D. OTROS GENERADORES NO ALTERADOS
    // =========================================================

    public function test_other_generators_unaffected(): void
    {
        ['company' => $company] = $this->companyWithDeps();
        $cid = $company->id;

        $pos = CompanySequence::nextPosNumber($cid);
        $cash = CompanySequence::nextCashSessionNumber($cid);
        $quote = CompanySequence::nextQuoteNumber($cid);
        $layaway = CompanySequence::nextLayawayNumber($cid);
        $order = CompanySequence::nextOrderNumber($cid);
        $po = CompanySequence::nextPurchaseOrderNumber($cid);
        $cust = CompanySequence::nextCustomerCode($cid);
        $susp = CompanySequence::nextSuspensionNumber($cid);

        $this->assertMatchesRegularExpression('/^POS-\d{8}$/', $pos);
        $this->assertMatchesRegularExpression('/^CAJA-\d{8}$/', $cash);
        $this->assertMatchesRegularExpression('/^COT-\d{8}$/', $quote);
        $this->assertMatchesRegularExpression('/^APT-\d{8}$/', $layaway);
        $this->assertMatchesRegularExpression('/^PED-\d{8}$/', $order);
        $this->assertMatchesRegularExpression('/^OC-\d{8}$/', $po);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $cust);
        $this->assertMatchesRegularExpression('/^SUSP-\d{6}$/', $susp);
    }
}
