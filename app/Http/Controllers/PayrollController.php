<?php

namespace App\Http\Controllers;

use App\Services\PayrollService;
use Illuminate\View\View;

class PayrollController extends Controller
{
    public function __construct(
        private PayrollService $service,
    ) {}

    public function index(): View
    {
        $payrolls = $this->service->list();

        return view('planilla.planillas.index', compact('payrolls'));
    }
}