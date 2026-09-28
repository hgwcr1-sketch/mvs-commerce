<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCompanyRequest;
use App\Http\Requests\UpdateCompanyRequest;
use App\Models\Canton;
use App\Models\Company;
use App\Models\Country;
use App\Models\District;
use App\Models\Province;
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
        if (request()->user()->isPlatformAdmin()) {
            return redirect()->route('platform.index');
        }

        $countries = Country::where('is_active', true)
            ->orderBy('name')
            ->get();
        $countryId = old('country_id', $countries->firstWhere('is_default', true)?->id);
        $provinceId = old('province_id');
        $cantonId = old('canton_id');

        return view('empresa.create', [

            'company' => new Company,

            'countries' => $countries,

            'provinces' => Province::where('is_active', true)
                ->when($countryId, fn ($query) => $query->where('country_id', $countryId))
                ->when(! $countryId, fn ($query) => $query->whereRaw('1 = 0'))
                ->orderBy('name')
                ->get(),

            'cantons' => Canton::where('is_active', true)
                ->when($provinceId, fn ($query) => $query->where('province_id', $provinceId))
                ->when(! $provinceId, fn ($query) => $query->whereRaw('1 = 0'))
                ->orderBy('name')
                ->get(),

            'districts' => District::where('is_active', true)
                ->when($cantonId, fn ($query) => $query->where('canton_id', $cantonId))
                ->when(! $cantonId, fn ($query) => $query->whereRaw('1 = 0'))
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

        if ($user->isPlatformAdmin()) {
            return redirect()->route('platform.index');
        }

        $isOnboarding = ! $user->companies()->exists();
        $pendingCommercialCompany = $user->ownedCompanies()->doesntHave('branches')->first();

        if (! $isOnboarding && ! $pendingCommercialCompany && ! $user->canCreateCompany()) {
            return redirect()
                ->route('empresa.index')
                ->with('error', 'No tiene cupos disponibles para crear una nueva empresa.');
        }

        $data = $request->validated();
        $branchName = $data['branch_name'];
        $branchCode = $data['branch_code'];
        unset($data['branch_name'], $data['branch_code']);

        if ($request->hasFile('logo')) {
            $data['logo'] = $request->file('logo')->store('companies', 'public');
        }

        $company = $pendingCommercialCompany
            ? app(CompanyProvisioner::class)->completeTenantOnboarding($user, $pendingCommercialCompany, $data, $branchName, $branchCode)
            : app(CompanyProvisioner::class)->provision($user, $data, $branchName, $branchCode);

        if ($isOnboarding || $pendingCommercialCompany) {
            $branch = $company->branches()->orderBy('id')->firstOrFail();
            $request->session()->put([
                'active_company_id' => $company->id,
                'active_branch_id' => $branch->id,
            ]);

            return redirect()
                ->route('dashboard')
                ->with('success', 'Empresa y primera sucursal creadas correctamente.');
        }

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
