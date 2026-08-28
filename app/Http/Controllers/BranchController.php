<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Company;
use App\Services\CompanyLicenseService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BranchController extends Controller
{
    public function index()
    {
        $companyId = session('active_company_id');

        $branches = Branch::where('company_id', $companyId)
            ->orderBy('name')
            ->get();

        return view('branches.index', compact('branches'));
    }

    public function create()
    {
        return view('branches.create');
    }

    public function store(Request $request, CompanyLicenseService $licenses)
    {
        $companyId = session('active_company_id');
        $licenses->assertCapacity(Company::findOrFail($companyId), 'branches');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('branches')->where(
                    fn ($query) => $query->where('company_id', $companyId)
                ),
            ],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        $validated['company_id'] = $companyId;
        $validated['is_active'] = true;

        $branch = Branch::create($validated);
        $request->user()->branches()->syncWithoutDetaching([$branch->id]);

        return redirect()
            ->route('branches.index')
            ->with('success', 'Sucursal creada correctamente.');
    }

    public function edit(Branch $branch)
    {
        abort_unless(
            $branch->company_id == session('active_company_id'),
            403
        );

        return view('branches.edit', compact('branch'));
    }

    public function update(Request $request, Branch $branch)
    {
        abort_unless(
            $branch->company_id == session('active_company_id'),
            403
        );

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('branches')
                    ->where(
                        fn ($query) => $query->where(
                            'company_id',
                            $branch->company_id
                        )
                    )
                    ->ignore($branch->id),
            ],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
            'receipt_format' => ['sometimes', Rule::in(['80mm', '58mm', 'letter'])],
            'receipt_auto_print' => ['nullable', 'boolean'],
        ]);

        $validated['is_active'] = $request->boolean('is_active');
        $validated['receipt_auto_print'] = $request->boolean('receipt_auto_print');

        $branch->update($validated);

        return redirect()
            ->route('branches.index')
            ->with('success', 'Sucursal actualizada correctamente.');
    }

    public function destroy(Branch $branch)
    {
        abort_unless(
            $branch->company_id == session('active_company_id'),
            403
        );

        $branch->delete();

        return redirect()
            ->route('branches.index')
            ->with('success', 'Sucursal eliminada correctamente.');
    }
}
