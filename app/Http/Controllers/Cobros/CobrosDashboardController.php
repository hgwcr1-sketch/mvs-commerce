<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class CobrosDashboardController extends Controller
{
    public function index(Request $request): View
    {
        $company = \App\Models\Company::find(session('active_company_id'));

        return view('cobros.dashboard', [
            'company' => $company,
        ]);
    }
}
