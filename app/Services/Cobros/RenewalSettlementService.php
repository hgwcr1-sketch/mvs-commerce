<?php
namespace App\Services\Cobros;
use App\Models\CobrosCredit;
use App\Models\CobrosCreditRenewalRequest;
use App\Models\CobrosRenewalSettlement;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class RenewalSettlementService {
    public function __construct(private CreditInstallmentService $installments,private RenewalLateFeeObligationService $lateFees){}
    public function prepare(Company $company,CobrosCreditRenewalRequest $renewal): CobrosRenewalSettlement { return DB::transaction(function()use($company,$renewal){$existing=CobrosRenewalSettlement::where('company_id',$company->id)->where('renewal_id',$renewal->id)->first();if($existing)return $existing;$credit=CobrosCredit::where('company_id',$company->id)->whereKey($renewal->credit_id)->lockForUpdate()->first();if(!$credit)throw ValidationException::withMessages(['renewal'=>'Crédito no pertenece a la empresa.']);$total=$this->installments->totalPending($credit);$principal=(string)$credit->principal;$contractTotal=(string)$credit->total_due;$pendingPrincipal=bccomp($contractTotal,'0',4)>0?bcdiv(bcmul($total,$principal,10),$contractTotal,10):$total;$pendingPrincipal=bcadd($pendingPrincipal,'0',4);$interest=bcsub($total,$pendingPrincipal,4);$late=$this->installments->pendingLateFee($company,$credit->account()->firstOrFail());$row=CobrosRenewalSettlement::create(['company_id'=>$company->id,'source_credit_id'=>$credit->id,'renewal_id'=>$renewal->id,'pending_principal'=>$pendingPrincipal,'pending_interest'=>$interest,'pending_total'=>$total,'transferred_late_fee'=>$late,'settled_at'=>now()]);if(bccomp($late,'0',4)>0)$this->lateFees->freeze($company->id,$credit->id,$renewal->id,$late);return $row;}); }
}
