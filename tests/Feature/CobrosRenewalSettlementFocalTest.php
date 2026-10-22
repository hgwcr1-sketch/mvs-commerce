<?php
namespace Tests\Feature;
use App\Models\CobrosCredit;
use App\Models\CobrosCreditInstallment;
use App\Models\CobrosCreditRenewalRequest;
use App\Models\CobrosCreditRequest;
use App\Models\CobrosRoute;
use App\Models\Company;
use App\Models\Customer;
use App\Models\AccountReceivable;
use App\Models\User;
use App\Models\Branch;
use App\Models\CobrosRenewalLateFeeObligation;
use App\Models\CobrosRenewalSettlement;
use App\Services\Cobros\RenewalSettlementService;
use App\Services\Cobros\RenewedCreditBuilder;
use App\Models\CobrosCreditInstallmentComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CobrosRenewalSettlementFocalTest extends TestCase {
    use RefreshDatabase;
    private function context(): array {
        $company=Company::create(['trade_name'=>'Settlement '.uniqid(),'currency'=>'CRC','timezone'=>'America/Costa_Rica','is_active'=>true]); session(['active_company_id'=>$company->id]);
        $collector=User::factory()->create(['is_active'=>true]); $branch=Branch::create(['company_id'=>$company->id,'name'=>'Principal','code'=>'ST'.uniqid()]); $route=CobrosRoute::create(['company_id'=>$company->id,'name'=>'Ruta Settlement','active'=>true]);
        $customer=Customer::create(['company_id'=>$company->id,'identification_type'=>'cedula','identification'=>uniqid(),'name'=>'Cliente Settlement']);
        $request=CobrosCreditRequest::create(['company_id'=>$company->id,'cobros_route_id'=>$route->id,'collector_user_id'=>$collector->id,'applicant_type'=>'nuevo','status'=>CobrosCreditRequest::STATUS_APPROVED,'identification_type'=>'cedula','identification'=>uniqid(),'name'=>'Cliente Settlement','phone'=>'88888888','address'=>'Prueba','amount_requested'=>'60000']);
        $account=AccountReceivable::create(['company_id'=>$company->id,'cobros_route_id'=>$route->id,'branch_id'=>$branch->id,'customer_id'=>$customer->id,'issued_at'=>today(),'due_date'=>today()->subDay(),'original_amount'=>'80000','balance_due'=>'80000','status'=>AccountReceivable::STATUS_PENDING,'currency_code'=>'CRC']);
        $credit=CobrosCredit::create(['company_id'=>$company->id,'credit_request_id'=>$request->id,'customer_id'=>$customer->id,'account_receivable_id'=>$account->id,'cobros_route_id'=>$route->id,'collector_user_id'=>$collector->id,'principal'=>'60000','interest_type'=>'fixed','interest_value'=>'20000','total_due'=>'80000','installment_count'=>1,'frequency'=>CobrosCredit::FREQUENCY_MONTHLY,'first_due_date'=>today()->subDays(10),'late_fee_type'=>CobrosCredit::LATE_FEE_FIXED,'late_fee_value'=>'10000','late_fee_grace_days'=>0,'status'=>CobrosCredit::STATUS_DISBURSED,'terms_snapshot'=>['principal'=>'60000']]);
        CobrosCreditInstallment::create(['credit_id'=>$credit->id,'number'=>1,'due_date'=>today()->subDays(10),'contractual_amount'=>'80000','applied_amount'=>'0','status'=>CobrosCreditInstallment::STATUS_PENDING]);
        $credit->update(['late_fee_type'=>CobrosCredit::LATE_FEE_FIXED,'late_fee_value'=>'10000','late_fee_grace_days'=>0]);
        $renewal=CobrosCreditRenewalRequest::create(['company_id'=>$company->id,'credit_id'=>$credit->id,'account_receivable_id'=>$account->id,'collector_user_id'=>$credit->collector_user_id,'new_capital'=>'70000','previous_pending'=>'80000','previous_principal'=>'60000','previous_interest'=>'20000','new_interest'=>'0','consolidated_total'=>'150000','disbursement'=>'70000','installment_count'=>4,'frequency'=>$credit->frequency,'first_due_date'=>today()->addWeek(),'schedule_snapshot'=>[],'status'=>CobrosCreditRenewalRequest::STATUS_CONSENTED,'requested_by'=>$collector->id]);
        return [$company,$renewal,$credit,$account];
    }
    public function test_settlement_is_split_idempotent_and_rollback_safe(): void {
        [$company,$renewal,$credit,$account]=$this->context(); $service=app(RenewalSettlementService::class);
        $row=$service->prepare($company,$renewal); $again=$service->prepare($company,$renewal);
        $this->assertSame($row->id,$again->id); $this->assertSame(1,CobrosRenewalSettlement::count()); $this->assertSame('60000.0000',(string)$row->pending_principal); $this->assertSame('20000.0000',(string)$row->pending_interest); $this->assertSame('10000.0000',(string)$row->transferred_late_fee); $this->assertSame(1,CobrosRenewalLateFeeObligation::count());
        try { DB::transaction(function()use($service,$company,$renewal){$service->prepare($company,$renewal);throw new \RuntimeException('rollback');}); } catch(\RuntimeException $e) {$this->assertSame('rollback',$e->getMessage());}
        $this->assertSame(CobrosCredit::STATUS_DISBURSED,$credit->fresh()->status); $this->assertSame('80000.0000',(string)$account->fresh()->balance_due);
    }

    public function test_renewed_credit_builder_creates_components_and_is_idempotent(): void
    {
        [$company,$renewal,$credit,$account]=$this->context();
        $quote=['consolidated_total'=>'180000.0000','previous_pending_principal'=>'60000.0000','previous_pending_interest'=>'20000.0000','new_money'=>['principal'=>'70000.0000','interest'=>'30000.0000'],'previous'=>['principal'=>'60000.0000','interest'=>'20000.0000'],'schedule'=>['installment_count'=>2,'frequency'=>'monthly','first_due_date'=>today()->addWeek()->toDateString(),'rows'=>[['number'=>1,'due_date'=>today()->addWeek()->toDateString(),'contractual_amount'=>'90000.0000'],['number'=>2,'due_date'=>today()->addMonth()->toDateString(),'contractual_amount'=>'90000.0000']]]];
        $builder=app(RenewedCreditBuilder::class); $new=$builder->build($company,$renewal,$quote,$credit); $again=$builder->build($company,$renewal,$quote,$credit);
        $this->assertSame($new->id,$again->id); $this->assertSame(2,$new->installments()->count()); $this->assertSame(8,CobrosCreditInstallmentComponent::where('credit_id',$new->id)->count());
        $this->assertSame(0,bccomp((string)CobrosCreditInstallmentComponent::where('credit_id',$new->id)->sum('original_amount'),'180000',4)); $this->assertSame('80000.0000',(string)$account->fresh()->balance_due); $this->assertSame(CobrosCredit::STATUS_DISBURSED,$credit->fresh()->status);
        try { DB::transaction(function()use($builder,$company,$renewal,$quote,$credit){$builder->build($company,$renewal,$quote,$credit);throw new \RuntimeException('rollback-builder');}); } catch(\RuntimeException $e) {$this->assertSame('rollback-builder',$e->getMessage());}
        $this->assertSame(1,CobrosCredit::where('company_id',$company->id)->whereJsonContains('terms_snapshot->renewal_request_id',$renewal->id)->count());
    }
}
