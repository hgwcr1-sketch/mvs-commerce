<?php

namespace App\Http\Controllers;

use App\Services\PayrollEmployeeService;
use Illuminate\View\View;

class PayrollEmployeeController extends Controller
{
    public function __construct(
        private PayrollEmployeeService $service,
    ) {}

    public function index(): View
    {
        $employees = $this->service->list();

        return view('planilla.empleados.index', compact('employees'));
    }
}
