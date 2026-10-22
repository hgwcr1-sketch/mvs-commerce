<?php
namespace App\Services\Cobros;
use App\Models\CobrosReceipt;
use App\Models\CobrosRenewalLateFeeObligation;
use App\Models\CobrosRenewalLateFeePayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class RenewalLateFeeObligationService {
    public function freeze(int $companyId,int $creditId,int $renewalId,string $amount): CobrosRenewalLateFeeObligation { return CobrosRenewalLateFeeObligation::firstOrCreate(['source_credit_id'=>$creditId,'renewal_id'=>$renewalId],['company_id'=>$companyId,'original_amount'=>$amount,'applied_amount'=>'0','status'=>bccomp($amount,'0',4)===0?'paid':'pending','frozen_at'=>now()]); }
    public function balance(CobrosRenewalLateFeeObligation $obligation): string { return max('0.0000',bcsub((string)$obligation->original_amount,(string)$obligation->applied_amount,4)); }
    public function apply(CobrosRenewalLateFeeObligation $obligation,CobrosReceipt $receipt,string $amount): CobrosRenewalLateFeePayment { return DB::transaction(function()use($obligation,$receipt,$amount){$o=CobrosRenewalLateFeeObligation::lockForUpdate()->findOrFail($obligation->id);$existing=CobrosRenewalLateFeePayment::where('obligation_id',$o->id)->where('cobros_receipt_id',$receipt->id)->first();if($existing)return $existing;if((int)$receipt->company_id!==(int)$o->company_id)throw ValidationException::withMessages(['receipt'=>'Recibo fuera de la empresa.']);if(CobrosRenewalLateFeePayment::where('cobros_receipt_id',$receipt->id)->exists())throw ValidationException::withMessages(['receipt'=>'El recibo ya está aplicado.']);if(bccomp($amount,'0',4)<=0||bccomp($amount,$this->balance($o),4)>0||bccomp($amount,(string)$receipt->amount,4)>0)throw ValidationException::withMessages(['amount'=>'Importe de mora no autorizado.']);$p=CobrosRenewalLateFeePayment::create(['obligation_id'=>$o->id,'cobros_receipt_id'=>$receipt->id,'amount'=>$amount]);$new=bcadd((string)$o->applied_amount,$amount,4);$o->update(['applied_amount'=>$new,'status'=>bccomp($new,(string)$o->original_amount,4)>=0?'paid':'pending']);return $p;}); }
}
