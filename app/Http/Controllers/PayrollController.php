<?php

namespace App\Http\Controllers;

use App\Services\PayrollService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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

    public function create(): View
    {
        return view('planilla.planillas.create');
    }

    public function store(Request $request): RedirectResponse
    {
        try {
            $this->service->create($request->all());

            return redirect()->route('planilla.planillas.index')
                ->with('success', 'Planilla creada correctamente.');
        } catch (ValidationException $e) {
            return redirect()->back()
                ->withErrors($e->validator)
                ->withInput();
        }
    }
}
