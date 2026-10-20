<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Cobros\MaestroReferralReader;
use Illuminate\Http\Request;

class ReferralController extends Controller
{
    public function index(Request $request, MaestroReferralReader $reader)
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $data = $reader->read($request->user(), $company);

        return view('cobros.referrals.index', compact('company', 'data'));
    }
}
