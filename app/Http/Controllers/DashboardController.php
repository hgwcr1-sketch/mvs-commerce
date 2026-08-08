<?php

namespace App\Http\Controllers;

use App\Models\Company;

class DashboardController extends Controller
{
    public function index()
    {
        $company = Company::find(session('active_company_id'));

        if (auth()->user()->hasPermission('dashboard.admin', $company)) {

            return view('dashboard.index');

        }

        return view('dashboard.seller');
    }
}