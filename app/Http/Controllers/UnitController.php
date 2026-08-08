<?php

namespace App\Http\Controllers;

use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UnitController extends Controller
{
    public function index()
{
    $companyId = session('active_company_id');
    $search = request('search');

    $units = Unit::where('company_id', $companyId)
        ->when($search, function ($query) use ($search) {
            $query->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('abbreviation', 'like', "%{$search}%");
            });
        })
        ->orderBy('sort_order')
        ->orderBy('name')
        ->paginate(10)
        ->withQueryString();

    return view('unidades.index', compact('units', 'search'));
}

    public function create()
    {
        return view('unidades.create');
    }

    public function store(Request $request)
{
    $companyId = session('active_company_id');

    $data = $request->validate([
        'name' => [
            'required',
            'string',
            'max:100',
            Rule::unique('units', 'name')
                ->where('company_id', $companyId),
        ],

        'abbreviation' => [
            'required',
            'string',
            'max:20',
            Rule::unique('units', 'abbreviation')
                ->where('company_id', $companyId),
        ],

        'allows_decimals' => [
            'required',
            'boolean',
        ],

        'sort_order' => [
            'nullable',
            'integer',
            'min:0',
        ],

        'is_active' => [
            'required',
            'boolean',
        ],
    ]);

    $data['company_id'] = $companyId;
    $data['slug'] = Str::slug($data['name']);
    $data['sort_order'] = $data['sort_order'] ?? 0;

    Unit::create($data);

    return redirect()
        ->route('unidades.index')
        ->with('success', 'Unidad de medida creada correctamente.');
}

    public function edit(Unit $unidade)
{
    $companyId = session('active_company_id');

    abort_unless($unidade->company_id == $companyId, 404);

    $unit = $unidade;

    return view('unidades.edit', compact('unit'));
}

    public function update(Request $request, Unit $unidade)
{
    $companyId = session('active_company_id');

    abort_unless($unidade->company_id == $companyId, 404);

    $unit = $unidade;

    $data = $request->validate([
        'name' => [
            'required',
            'string',
            'max:100',
            Rule::unique('units', 'name')
                ->where('company_id', $companyId)
                ->ignore($unit->id),
        ],

        'abbreviation' => [
            'required',
            'string',
            'max:20',
            Rule::unique('units', 'abbreviation')
                ->where('company_id', $companyId)
                ->ignore($unit->id),
        ],

        'allows_decimals' => [
            'required',
            'boolean',
        ],

        'sort_order' => [
            'nullable',
            'integer',
            'min:0',
        ],

        'is_active' => [
            'required',
            'boolean',
        ],
    ]);

    $data['slug'] = Str::slug($data['name']);
    $data['sort_order'] = $data['sort_order'] ?? 0;

    $unit->update($data);

    return redirect()
        ->route('unidades.index')
        ->with('success', 'Unidad de medida actualizada correctamente.');
}

    public function destroy(Unit $unidade)
{
    $companyId = session('active_company_id');

    abort_unless($unidade->company_id == $companyId, 404);

    if ($unidade->products()->exists()) {
        return redirect()
            ->route('unidades.index')
            ->with('error', 'No se puede eliminar porque tiene productos asociados.');
    }

    $unidade->delete();

    return redirect()
        ->route('unidades.index')
        ->with('success', 'Unidad de medida eliminada correctamente.');
}
}