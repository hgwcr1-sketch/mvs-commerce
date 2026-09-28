<?php

namespace App\Services\Purchases;

use App\Models\AccountPayable;
use App\Models\AccountPayableAlert;
use App\Models\Company;
use App\Notifications\AccountPayableDueNotification;

class AccountPayableAlertService
{
    public function process(): int
    {
        $created=0;
        Company::query()->where('is_active',true)->with('users')->chunkById(100,function($companies)use(&$created){
            foreach($companies as $company){
                $days=(int)($company->payable_alert_days??5);
                AccountPayable::query()->forCompany($company->id)->whereNotIn('status',[AccountPayable::STATUS_PAID,AccountPayable::STATUS_CANCELLED])->where('balance_due','>',0)->with(['supplier:id,name','purchase:id,number'])->chunkById(100,function($accounts)use($company,$days,&$created){
                    foreach($accounts as $account){
                        $type=$account->due_date->isBefore(today())?AccountPayableAlert::TYPE_OVERDUE:($account->due_date->between(today(),today()->addDays($days))?AccountPayableAlert::TYPE_UPCOMING:null);
                        if(!$type)continue;
                        $alert=AccountPayableAlert::firstOrCreate(['account_payable_id'=>$account->id,'type'=>$type],['company_id'=>$company->id,'notified_at'=>now()]);
                        if(!$alert->wasRecentlyCreated)continue;
                        foreach($company->users as $user){
                            if($user->hasPermission('cuentas_pagar.ver',$company)&&$user->branches()->whereKey($account->branch_id)->exists())$user->notify(new AccountPayableDueNotification($account,$type));
                        }
                        $created++;
                    }
                });
            }
        });
        return $created;
    }
}
