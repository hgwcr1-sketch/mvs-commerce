<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\ControlCenterService;
use Illuminate\Http\Request;

class ControlCenterController extends Controller
{
    public function index(Request $request, ControlCenterService $service)
    {
        $company = Company::query()->findOrFail((int) session('active_company_id'));
        abort_unless($request->user()->hasPermission('dashboard.admin', $company), 403);

        $branchId = session('active_branch_id') ? (int) session('active_branch_id') : null;

        $data = $service->forCompany($company, $branchId);

        $timezone = $company->timezone ?? 'America/Costa_Rica';
        $data['generated_at'] = now()->timezone($timezone)->format('d/m/Y H:i');
        $data['timezone'] = $timezone;

        return view('control-center.index', compact('data'));
    }
}
