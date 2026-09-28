<?php

namespace Tests\Feature;

use App\Models\AccountPayable;
use App\Models\AccountPayablePayment;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AccountsPayableModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_tables_have_required_structure_and_decimal_models(): void
    {
        $this->assertTrue(Schema::hasColumns('accounts_payable', ['company_id','branch_id','supplier_id','purchase_id','original_amount','paid_amount','balance_due','issue_date','due_date','status','currency_code','notes','created_by','cancelled_by','cancelled_at','cancellation_reason','created_at','updated_at']));
        $this->assertTrue(Schema::hasColumns('accounts_payable_payments', ['account_payable_id','company_id','branch_id','supplier_id','user_id','payment_method_id','cash_session_id','amount','affects_cash_snapshot','cash_effect_amount','reference','notes','paid_at','created_at','updated_at']));

        [$company,$branch,$user,$supplier,$purchase] = $this->context('credit');
        $account = $this->account($company,$branch,$user,$supplier,$purchase,1000);
        $this->assertSame('1000.0000',$account->original_amount);
        $this->assertSame('0.0000',$account->paid_amount);
        $this->assertSame('1000.0000',$account->balance_due);
    }

    public function test_only_credit_purchase_can_have_one_payable_and_context_must_match(): void
    {
        [$company,$branch,$user,$supplier,$cashPurchase] = $this->context('cash');
        try {$this->account($company,$branch,$user,$supplier,$cashPurchase,1000); $this->fail('Una compra de contado no debe crear CxP.');} catch (ValidationException $e) {$this->assertArrayHasKey('purchase_id',$e->errors());}

        $cashPurchase->update(['payment_type'=>'credit','due_date'=>today()->addMonth()]);
        $account=$this->account($company,$branch,$user,$supplier,$cashPurchase,1000);
        $this->assertTrue($cashPurchase->fresh()->accountPayable->is($account));
        $this->assertTrue($supplier->accountsPayable->contains($account));

        $this->expectException(QueryException::class);
        $this->account($company,$branch,$user,$supplier,$cashPurchase,1000);
    }

    public function test_payment_relations_and_overpayment_guard(): void
    {
        [$company,$branch,$user,$supplier,$purchase] = $this->context('credit');
        $account=$this->account($company,$branch,$user,$supplier,$purchase,1000);
        $method=PaymentMethod::create(['company_id'=>$company->id,'code'=>'transfer','name'=>'Transferencia','type'=>PaymentMethod::TYPE_BANK_TRANSFER,'is_active'=>true,'affects_cash'=>false]);
        $register=CashRegister::create(['company_id'=>$company->id,'branch_id'=>$branch->id,'code'=>'CP1','name'=>'Caja','is_active'=>true]);
        $session=CashSession::create(['company_id'=>$company->id,'branch_id'=>$branch->id,'cash_register_id'=>$register->id,'session_number'=>'CP-1','opened_by'=>$user->id,'status'=>CashSession::STATUS_OPEN,'open_guard'=>CashSession::OPEN_GUARD,'opening_amount'=>0,'opened_at'=>now()]);
        $payment=AccountPayablePayment::create(['account_payable_id'=>$account->id,'company_id'=>$company->id,'branch_id'=>$branch->id,'supplier_id'=>$supplier->id,'user_id'=>$user->id,'payment_method_id'=>$method->id,'cash_session_id'=>$session->id,'amount'=>400,'reference'=>'TRX-1','paid_at'=>now()]);
        $this->assertTrue($payment->accountPayable->is($account)); $this->assertTrue($payment->paymentMethod->is($method)); $this->assertTrue($payment->cashSession->is($session));

        AccountPayablePayment::create(['account_payable_id'=>$account->id,'company_id'=>$company->id,'branch_id'=>$branch->id,'supplier_id'=>$supplier->id,'user_id'=>$user->id,'payment_method_id'=>$method->id,'amount'=>600,'paid_at'=>now()]);

        $this->expectException(ValidationException::class);
        AccountPayablePayment::create(['account_payable_id'=>$account->id,'company_id'=>$company->id,'branch_id'=>$branch->id,'supplier_id'=>$supplier->id,'user_id'=>$user->id,'payment_method_id'=>$method->id,'amount'=>1,'paid_at'=>now()]);
    }

    public function test_company_branch_scopes_and_effective_status_are_isolated(): void
    {
        [$company,$branch,$user,$supplier,$purchase]=$this->context('credit','Uno');
        $account=$this->account($company,$branch,$user,$supplier,$purchase,500); $account->update(['due_date'=>today()->subDay()]);
        [$other,$otherBranch,$otherUser,$otherSupplier,$otherPurchase]=$this->context('credit','Dos');
        $this->account($other,$otherBranch,$otherUser,$otherSupplier,$otherPurchase,700);

        $this->assertSame(AccountPayable::STATUS_OVERDUE,$account->fresh()->effective_status);
        $this->assertSame([$account->id],AccountPayable::forCompany($company->id)->forBranch($branch->id)->pluck('id')->all());
    }

    private function context(string $paymentType, string $name='Empresa'): array
    {
        $company=Company::create(['trade_name'=>$name.uniqid(),'currency'=>'CRC','timezone'=>'America/Costa_Rica','is_active'=>true]);
        $branch=Branch::create(['company_id'=>$company->id,'name'=>'Principal','code'=>'P'.uniqid(),'is_active'=>true]);
        $user=User::factory()->create();
        $supplier=Supplier::create(['company_id'=>$company->id,'supplier_type'=>'company','name'=>'Proveedor '.uniqid(),'credit_days'=>30,'is_active'=>true]);
        $purchase=Purchase::create(['company_id'=>$company->id,'branch_id'=>$branch->id,'supplier_id'=>$supplier->id,'user_id'=>$user->id,'number'=>'CP-'.uniqid(),'purchase_date'=>today(),'payment_type'=>$paymentType,'due_date'=>$paymentType==='credit'?today()->addMonth():null,'total'=>1000,'status'=>'posted']);
        return[$company,$branch,$user,$supplier,$purchase];
    }

    private function account(Company $company,Branch $branch,User $user,Supplier $supplier,Purchase $purchase,float $amount): AccountPayable
    {
        return AccountPayable::create(['company_id'=>$company->id,'branch_id'=>$branch->id,'supplier_id'=>$supplier->id,'purchase_id'=>$purchase->id,'original_amount'=>$amount,'paid_amount'=>0,'balance_due'=>$amount,'issue_date'=>$purchase->purchase_date,'due_date'=>$purchase->due_date,'status'=>AccountPayable::STATUS_PENDING,'currency_code'=>'CRC','created_by'=>$user->id]);
    }
}
