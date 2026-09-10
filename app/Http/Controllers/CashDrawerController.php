<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CashDrawerController extends Controller
{
    public function receipt(string $type, Request $request): View
    {
        abort_unless(in_array($type, ['opening', 'closing'], true), 400);

        $companyId = (int) session('active_company_id');
        $branchId = (int) session('active_branch_id');

        if (! $companyId) {
            abort(403);
        }

        $company = Company::findOrFail($companyId);
        $branch = Branch::findOrFail($branchId);

        if ($type === 'opening') {
            abort_unless($request->user()->hasPermission('caja.abrir', $company), 403);

            $cashRegisterId = (int) $request->query('cash_register_id');

            $register = CashRegister::where('id', $cashRegisterId)
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('is_active', true)
                ->first();

            abort_unless($register, 403);

            $userName = $request->user()->name ?? '';

            return view('cash.drawer-receipt', [
                'company' => $company,
                'branch' => $branch,
                'register' => $register,
                'user_name' => $userName,
                'type' => 'opening',
                'date_time' => now()->tz($company->timezone)->format('d/m/Y H:i'),
            ]);
        }

        if ($type === 'closing') {
            abort_unless($request->user()->hasPermission('caja.cerrar', $company), 403);

            $cashSessionId = (int) $request->query('cash_session_id');

            $session = CashSession::where('id', $cashSessionId)
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('status', '!=', CashSession::STATUS_CLOSED)
                ->first();

            abort_unless($session, 403);

            $register = $session->cashRegister;

            $userName = $session->openedBy?->name ?? $request->user()->name ?? '';

            return view('cash.drawer-receipt', [
                'company' => $company,
                'branch' => $branch,
                'session' => $session,
                'register' => $register,
                'user_name' => $userName,
                'type' => 'closing',
                'date_time' => now()->tz($company->timezone)->format('d/m/Y H:i'),
            ]);
        }

        abort(400);
    }
}
