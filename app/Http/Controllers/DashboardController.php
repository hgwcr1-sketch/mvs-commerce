<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\AccountReceivable;
use App\Models\Layaway;
use App\Models\AccountPayable;

class DashboardController extends Controller
{
    public function index()
    {
        $company = Company::find(session('active_company_id'));
        $branchId = session('active_branch_id');

        $alertDays = (int) ($company->credit_alert_days ?? 5);

        // --- Modo CONSOLIDADO: sin branch seleccionada ---
        if (!$branchId) {
            $base = AccountReceivable::query()->forCompany($company->id)
                ->whereNotIn('status',[AccountReceivable::STATUS_PAID,AccountReceivable::STATUS_CANCELLED])
                ->where('balance_due','>',0);
            $creditSummary = [
                'overdue_count' => (clone $base)->whereDate('due_date','<',today())->count(),
                'overdue_amount' => (float) (clone $base)->whereDate('due_date','<',today())->sum('balance_due'),
                'upcoming_count' => (clone $base)->whereBetween('due_date',[today(),today()->addDays($alertDays)])->count(),
                'upcoming_amount' => (float) (clone $base)->whereBetween('due_date',[today(),today()->addDays($alertDays)])->sum('balance_due'),
                'alert_days' => $alertDays,
            ];

            $layawayBase = Layaway::query()->forCompany($company->id);
            $layawaySummary = [
                'active_count' => (clone $layawayBase)->whereIn('status',[Layaway::STATUS_ACTIVE,Layaway::STATUS_PAID])->count(),
                'pending_amount' => (float)(clone $layawayBase)->where('status',Layaway::STATUS_ACTIVE)->sum('balance_due'),
                'upcoming_count' => (clone $layawayBase)->where('status',Layaway::STATUS_ACTIVE)->whereBetween('expires_at',[today(),today()->addDays((int)($company->layaway_alert_days??5))])->count(),
                'expired_count' => (clone $layawayBase)->where('status',Layaway::STATUS_EXPIRED)->count(),
            ];

            $payableDays=(int)($company->payable_alert_days??5);
            $payableSummary=['pending_count'=>0,'pending_amount'=>0,'overdue_count'=>0,'overdue_amount'=>0,'upcoming_count'=>0,'upcoming_amount'=>0,'alert_days'=>$payableDays];
            if(auth()->user()->hasPermission('cuentas_pagar.ver',$company)){
                $row=AccountPayable::query()->forCompany($company->id)
                    ->whereNotIn('status',[AccountPayable::STATUS_PAID,AccountPayable::STATUS_CANCELLED])
                    ->where('balance_due','>',0)
                    ->selectRaw('COUNT(*) as pending_count, COALESCE(SUM(balance_due),0) as pending_amount, SUM(CASE WHEN due_date < ? THEN 1 ELSE 0 END) as overdue_count, COALESCE(SUM(CASE WHEN due_date < ? THEN balance_due ELSE 0 END),0) as overdue_amount, SUM(CASE WHEN due_date BETWEEN ? AND ? THEN 1 ELSE 0 END) as upcoming_count, COALESCE(SUM(CASE WHEN due_date BETWEEN ? AND ? THEN balance_due ELSE 0 END),0) as upcoming_amount',[today()->toDateString(),today()->toDateString(),today()->toDateString(),today()->addDays($payableDays)->toDateString(),today()->toDateString(),today()->addDays($payableDays)->toDateString()])->first();
                foreach(['pending_count','overdue_count','upcoming_count'] as $key)$payableSummary[$key]=(int)$row->{$key};
                foreach(['pending_amount','overdue_amount','upcoming_amount'] as $key)$payableSummary[$key]=(float)$row->{$key};
            }

            if (auth()->user()->hasPermission('dashboard.admin', $company)) {

                return view('dashboard.index', compact('creditSummary','layawaySummary','payableSummary'));

            }

            return view('dashboard.seller', compact('creditSummary','layawaySummary','payableSummary'));

        }

        // --- Modo BRANCH ESPECÍFICA: branchId definido ---
        $branchId = (int) $branchId;

        $base = AccountReceivable::query()->forCompany($company->id)->forBranch($branchId)
            ->whereNotIn('status',[AccountReceivable::STATUS_PAID,AccountReceivable::STATUS_CANCELLED])
            ->where('balance_due','>',0);
        $creditSummary = [
            'overdue_count' => (clone $base)->whereDate('due_date','<',today())->count(),
            'overdue_amount' => (float) (clone $base)->whereDate('due_date','<',today())->sum('balance_due'),
            'upcoming_count' => (clone $base)->whereBetween('due_date',[today(),today()->addDays($alertDays)])->count(),
            'upcoming_amount' => (float) (clone $base)->whereBetween('due_date',[today(),today()->addDays($alertDays)])->sum('balance_due'),
            'alert_days' => $alertDays,
        ];
        $layawayBase = Layaway::query()->forCompany($company->id)->forBranch($branchId);
        $layawaySummary = [
            'active_count' => (clone $layawayBase)->whereIn('status',[Layaway::STATUS_ACTIVE,Layaway::STATUS_PAID])->count(),
            'pending_amount' => (float)(clone $layawayBase)->where('status',Layaway::STATUS_ACTIVE)->sum('balance_due'),
            'upcoming_count' => (clone $layawayBase)->where('status',Layaway::STATUS_ACTIVE)->whereBetween('expires_at',[today(),today()->addDays((int)($company->layaway_alert_days??5))])->count(),
            'expired_count' => (clone $layawayBase)->where('status',Layaway::STATUS_EXPIRED)->count(),
        ];
        $payableDays=(int)($company->payable_alert_days??5);
        $payableSummary=['pending_count'=>0,'pending_amount'=>0,'overdue_count'=>0,'overdue_amount'=>0,'upcoming_count'=>0,'upcoming_amount'=>0,'alert_days'=>$payableDays];
        if(auth()->user()->hasPermission('cuentas_pagar.ver',$company)){
            $row=AccountPayable::query()->forCompany($company->id)->forBranch($branchId)
                ->whereNotIn('status',[AccountPayable::STATUS_PAID,AccountPayable::STATUS_CANCELLED])
                ->where('balance_due','>',0)
                ->selectRaw('COUNT(*) as pending_count, COALESCE(SUM(balance_due),0) as pending_amount, SUM(CASE WHEN due_date < ? THEN 1 ELSE 0 END) as overdue_count, COALESCE(SUM(CASE WHEN due_date < ? THEN balance_due ELSE 0 END),0) as overdue_amount, SUM(CASE WHEN due_date BETWEEN ? AND ? THEN 1 ELSE 0 END) as upcoming_count, COALESCE(SUM(CASE WHEN due_date BETWEEN ? AND ? THEN balance_due ELSE 0 END),0) as upcoming_amount',[today()->toDateString(),today()->toDateString(),today()->toDateString(),today()->addDays($payableDays)->toDateString(),today()->toDateString(),today()->addDays($payableDays)->toDateString()])->first();
            foreach(['pending_count','overdue_count','upcoming_count'] as $key)$payableSummary[$key]=(int)$row->{$key};
            foreach(['pending_amount','overdue_amount','upcoming_amount'] as $key)$payableSummary[$key]=(float)$row->{$key};
        }

        if (auth()->user()->hasPermission('dashboard.admin', $company)) {

            return view('dashboard.index', compact('creditSummary','layawaySummary','payableSummary'));

        }

        return view('dashboard.seller', compact('creditSummary','layawaySummary','payableSummary'));
    }
}