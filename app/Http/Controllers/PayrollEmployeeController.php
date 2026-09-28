<?php

namespace App\Http\Controllers;

use App\Services\PayrollEmployeeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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

    public function store(Request $request): RedirectResponse
    {
        try {
            $this->service->create($request->all());

            return redirect()->route('planilla.empleados.index')
                ->with('success', 'Empleado creado correctamente.');
        } catch (ValidationException $e) {
            return redirect()->back()
                ->withErrors($e->validator)
                ->withInput();
        }
    }
}
