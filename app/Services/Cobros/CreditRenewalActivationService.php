<?php
declare(strict_types=1);
namespace App\Services\Cobros;
use App\Models\{AccountReceivable,CobrosCredit,CobrosCreditRenewalRequest,CobrosCreditRequest,CobrosCreditRequestLog,Company,User};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Activación única: settlement, builder, conciliación y cierre anterior. */
class CreditRenewalActivationService
{
    public function __construct(private InstallmentScheduleService $schedule, private CreditInstallmentService $installments, private PortfolioAssignmentService $assignments, private RenewalSettlementService $settlements, private RenewedCreditBuilder $builder) {}

    public function activate(CobrosCreditRenewalRequest $renewal, User $actor): CobrosCredit
    {
        $company=Company::findOrFail((int)$renewal->company_id);
        return DB::transaction(function()use($renewal,$actor,$company): CobrosCredit {
            $locked=CobrosCreditRenewalRequest::where('company_id',$company->id)->whereKey($renewal->id)->lockForUpdate()->firstOrFail();
            if($locked->status===CobrosCreditRenewalRequest::STATUS_ACTIVATED)return CobrosCredit::forCompany($company->id)->whereKey($locked->activated_credit_id)->firstOrFail();
            if($locked->status!==CobrosCreditRenewalRequest::STATUS_CONSENTED)throw ValidationException::withMessages(['renewal'=>'La renovación debe estar consentida.']);
            $consent=app(CreditRenewalConsentService::class);$hash=$consent->hashSnapshot($consent->consentSnapshot($locked));
            if(!$locked->terms_hash||!hash_equals((string)$locked->terms_hash,$hash))throw ValidationException::withMessages(['terms_hash'=>'Los términos firmados no coinciden.']);
            $previous=CobrosCredit::forCompany($company->id)->whereKey($locked->credit_id)->lockForUpdate()->first();
            if(!$previous||$previous->status!==CobrosCredit::STATUS_DISBURSED)throw ValidationException::withMessages(['renewal'=>'El crédito anterior no está disponible.']);
            if ((string) $locked->modality === 'partial_payment') {
                $receipt = \App\Models\CobrosReceipt::query()->forCompany((int) $company->id)
                    ->whereKey($locked->partial_payment_receipt_id)->whereNull('annulled_at')
                    ->where('account_receivable_id', $previous->account_receivable_id)
                    ->where('customer_id', $previous->customer_id)->first();
                if (! $receipt || ! $receipt->payment_id || ! \App\Models\CobrosCreditInstallmentApplication::query()
                    ->where('company_id', $company->id)->where('credit_id', $previous->id)
                    ->where('cobros_receipt_id', $receipt->id)->exists()) {
                    throw ValidationException::withMessages(['renewal' => 'El abono parcial fue anulado o no está confirmado.']);
                }
            }
            $pending=$this->installments->totalPending($previous);
            if(bccomp($pending,(string)$locked->previous_pending,4)!==0)throw ValidationException::withMessages(['pending'=>'El saldo pendiente cambió desde la firma.']);
            $settlement=$this->settlements->prepare($company,$locked);
            $rows=$locked->schedule_snapshot?:$this->schedule->build((string)$locked->consolidated_total,(int)$locked->installment_count,(string)$locked->frequency,(string)$locked->first_due_date->toDateString());
            $quote=['previous_pending_principal'=>(string)$settlement->pending_principal,'previous_pending_interest'=>(string)$settlement->pending_interest,'previous'=>['principal'=>(string)$settlement->pending_principal,'interest'=>(string)$settlement->pending_interest],'new_money'=>['principal'=>(string)$locked->disbursement,'interest'=>(string)$locked->new_interest],'consolidated_total'=>(string)$locked->consolidated_total,'schedule'=>['installment_count'=>(int)$locked->installment_count,'frequency'=>(string)$locked->frequency,'first_due_date'=>(string)$locked->first_due_date->toDateString(),'rows'=>$rows]];
            $credit=$this->builder->build($company,$locked,$quote,$previous);
            if(bccomp((string)$credit->installmentComponents()->sum('original_amount'),(string)$credit->total_due,4)!==0)throw ValidationException::withMessages(['renewal'=>'Los componentes no concilian con el total renovado.']);
            $account=$previous->account()->lockForUpdate()->first();if($account)$account->update(['status'=>AccountReceivable::STATUS_CANCELLED]);$previous->update(['status'=>CobrosCredit::STATUS_CLOSED_RENEWAL]);
            $locked->update(['status'=>CobrosCreditRenewalRequest::STATUS_ACTIVATED,'activated_credit_id'=>$credit->id]);
            CobrosCreditRequestLog::create(['company_id'=>$renewal->company_id,'credit_request_id'=>$credit->credit_request_id,'actor_user_id'=>$actor->id,'from_status'=>CobrosCreditRequest::STATUS_APPROVED,'to_status'=>CobrosCreditRequest::STATUS_APPROVED,'notes'=>'Renovación #'.$renewal->id.' activada; crédito aprobado pendiente de desembolso #'.$credit->id]);
            return $credit->fresh();
        });
    }
}
