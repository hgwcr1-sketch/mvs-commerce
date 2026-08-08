<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBrandRequest;
use App\Http\Requests\UpdateBrandRequest;
use App\Models\Brand;

class BrandController extends Controller
{
    public function index()
{
    $companyId = session('active_company_id');
    $search = request('search');

    $marcas = Brand::where('company_id', $companyId)
        ->when($search, function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%");
        })
        ->orderBy('sort_order')
        ->orderBy('name')
        ->paginate(10)
        ->withQueryString();

    $totalMarcas = Brand::where('company_id', $companyId)->count();

    $marcasActivas = Brand::where('company_id', $companyId)
        ->where('is_active', true)
        ->count();

    $marcasInactivas = Brand::where('company_id', $companyId)
        ->where('is_active', false)
        ->count();

    return view('marcas.index', compact(
        'marcas',
        'search',
        'totalMarcas',
        'marcasActivas',
        'marcasInactivas'
    ));
}

    public function create()
    {
        return view('marcas.create');
    }

    public function store(StoreBrandRequest $request)
{
    $data = $request->validated();
    $data['company_id'] = session('active_company_id');

    Brand::create($data);

    return redirect()
        ->route('marcas.index')
        ->with('success', 'Marca creada correctamente.');
}

    public function edit(Brand $marca)
{
    $companyId = session('active_company_id');

    abort_unless($marca->company_id == $companyId, 404);

    return view('marcas.edit', compact('marca'));
}

    public function update(UpdateBrandRequest $request, Brand $marca)
{
    $companyId = session('active_company_id');

    abort_unless($marca->company_id == $companyId, 404);

    $marca->update($request->validated());

    return redirect()
        ->route('marcas.index')
        ->with('success', 'Marca actualizada correctamente.');
}

    public function destroy(Brand $marca)
{
    $companyId = session('active_company_id');

    abort_unless($marca->company_id == $companyId, 404);

    $marca->delete();

    return redirect()
        ->route('marcas.index')
        ->with('success', 'Marca eliminada correctamente.');
}

}