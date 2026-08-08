<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCompanyRequest;
use App\Http\Requests\UpdateCompanyRequest;
use App\Models\Company;
use App\Models\Country;
use App\Models\Province;
use App\Models\Canton;
use App\Models\District;
use App\Services\CompanyProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CompanyController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
{
    $user = request()->user();

    $companies = $user->ownedCompanies()
        ->latest()
        ->paginate(10);

    $allowedCompanies = $user->companyAllowance?->allowed_companies ?? 0;

    $usedCompanies = $user->ownedCompanies()->count();

    $availableCompanies = $user->availableCompanySlots();

    return view('empresa.index', compact(
        'companies',
        'allowedCompanies',
        'usedCompanies',
        'availableCompanies'
    ));
}

    /**
     * Show the form for creating a new resource.
     */
    public function create()
{
    return view('empresa.create', [

        'company' => new Company(),

        'countries' => Country::where('is_active', true)
            ->orderBy('name')
            ->get(),

        'provinces' => Province::where('is_active', true)
            ->orderBy('name')
            ->get(),

        'cantons' => Canton::where('is_active', true)
            ->orderBy('name')
            ->get(),

        'districts' => District::where('is_active', true)
            ->orderBy('name')
            ->get(),

    ]);
}
    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreCompanyRequest $request)
{
    $user = $request->user();

    if (!$user->canCreateCompany()) {
        return redirect()
            ->route('empresa.index')
            ->with('error', 'No tiene cupos disponibles para crear una nueva empresa.');
    }

    $data = $request->validated();

    if ($request->hasFile('logo')) {
        $data['logo'] = $request->file('logo')->store('companies', 'public');
    }

    app(CompanyProvisioner::class)->provision($user, $data);

    return redirect()
        ->route('empresa.index')
        ->with('success', 'Empresa creada correctamente.');
}

    /**
     * Display the specified resource.
     */
    public function show(string $id)
{
    $user = request()->user();

    $company = $user->ownedCompanies()
        ->with([
            'owner',
            'country',
            'province',
            'canton',
            'district',
        ])
        ->findOrFail($id);

    return view('empresa.show', compact('company'));
}

public function edit(string $id)
{
    $user = request()->user();

    $company = $user->ownedCompanies()
        ->findOrFail($id);

    return view('empresa.edit', [

        'company' => $company,

        'countries' => Country::where('is_active', true)
            ->orderBy('name')
            ->get(),

        'provinces' => Province::where('is_active', true)
            ->orderBy('name')
            ->get(),

        'cantons' => Canton::where('is_active', true)
            ->orderBy('name')
            ->get(),

        'districts' => District::where('is_active', true)
            ->orderBy('name')
            ->get(),

    ]);
}

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateCompanyRequest $request, string $id)
{
    $user = $request->user();

    $company = $user->ownedCompanies()
        ->findOrFail($id);

    $data = $request->validated();

    DB::transaction(function () use ($request, $company, &$data) {

        if ($request->hasFile('logo')) {

            if ($company->logo) {
                Storage::disk('public')->delete($company->logo);
            }

            $data['logo'] = $request->file('logo')
                ->store('companies', 'public');
        }

        $company->update($data);
    });

    return redirect()
        ->route('empresa.show', $company)
        ->with('success', 'Empresa actualizada correctamente.');
}

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
