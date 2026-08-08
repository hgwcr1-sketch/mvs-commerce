<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Models\Country;
use App\Models\Province;
use App\Models\Canton;
use App\Models\District;
use Illuminate\Http\Request;
use App\Http\Requests\StoreSupplierRequest;
use App\Http\Requests\UpdateSupplierRequest;

class SupplierController extends Controller
{
    /**
     * Mostrar listado de proveedores.
     */
    public function index(Request $request)
    {
        $companyId = session('active_company_id');

        $search = $request->search;
        $status = $request->status;
        $type = $request->type;

        $suppliers = Supplier::query()
            ->where('company_id', $companyId)

            ->when($search, function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('commercial_name', 'like', "%{$search}%")
                        ->orWhere('identification', 'like', "%{$search}%")
                        ->orWhere('contact_name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('mobile', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })

            ->when($status !== null && $status !== '', function ($query) use ($status) {
                $query->where('is_active', $status);
            })

            ->when($type, function ($query) use ($type) {
                $query->where('supplier_type', $type);
            })

            ->latest()
            ->paginate(15)
            ->withQueryString();

        $stats = [
            'total' => Supplier::where('company_id', $companyId)->count(),

            'active' => Supplier::where('company_id', $companyId)
                ->where('is_active', true)
                ->count(),

            'companies' => Supplier::where('company_id', $companyId)
                ->where('supplier_type', 'company')
                ->count(),

            'individuals' => Supplier::where('company_id', $companyId)
                ->where('supplier_type', 'individual')
                ->count(),
        ];

        return view('proveedores.index', compact(
            'suppliers',
            'stats',
            'search',
            'status',
            'type'
        ));
    }

    /**
     * Mostrar formulario de creación.
     */
    public function create()
    {
        return view('proveedores.create', [
            'supplier' => new Supplier(),

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
     * Guardar proveedor.
     */
   public function store(StoreSupplierRequest $request)
{
    $data = $request->validated();

    $data['company_id'] = session('active_company_id');
    $data['is_active'] = $request->boolean('is_active');

    $supplier = Supplier::create($data);

    if ($request->expectsJson()) {
        return response()->json([
            'id' => $supplier->id,
            'name' => $supplier->name,
            'commercial_name' => $supplier->commercial_name,
            'identification' => $supplier->identification,
            'phone' => $supplier->phone,
            'mobile' => $supplier->mobile,
            'email' => $supplier->email,
        ], 201);
    }

    return redirect()
        ->route('proveedores.index')
        ->with('success', 'Proveedor registrado correctamente.');
}

    /**
     * Mostrar proveedor.
     */
    public function show(Supplier $proveedore)
    {
        $this->ensureSupplierBelongsToActiveCompany($proveedore);

        $proveedore->load([
            'country',
            'province',
            'canton',
            'district',
        ]);

        return view('proveedores.show', [
            'supplier' => $proveedore,
        ]);
    }

    /**
     * Mostrar formulario de edición.
     */
    public function edit(Supplier $proveedore)
    {
        $this->ensureSupplierBelongsToActiveCompany($proveedore);

        return view('proveedores.edit', [
            'supplier' => $proveedore,

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
     * Actualizar proveedor.
     */
    public function update(UpdateSupplierRequest $request, Supplier $proveedore)
{
    $this->ensureSupplierBelongsToActiveCompany($proveedore);

    $data = $request->validated();

    $data['is_active'] = $request->boolean('is_active');

    $proveedore->update($data);

    return redirect()
        ->route('proveedores.index')
        ->with('success', 'Proveedor actualizado correctamente.');
}

public function toggleStatus(Supplier $proveedore)
{
    $this->ensureSupplierBelongsToActiveCompany($proveedore);

    $proveedore->update([
        'is_active' => !$proveedore->is_active,
    ]);

    return redirect()
        ->route('proveedores.index')
        ->with('success', 'Estado del proveedor actualizado correctamente.');
}

    /**
     * Eliminar proveedor.
     */
    public function destroy(Supplier $proveedore)
    {
        $this->ensureSupplierBelongsToActiveCompany($proveedore);

        $proveedore->delete();

        return redirect()
            ->route('proveedores.index')
            ->with('success', 'Proveedor eliminado correctamente.');
    }

    /**
     * Verificar que el proveedor pertenece a la empresa activa.
     */
    private function ensureSupplierBelongsToActiveCompany(Supplier $supplier): void
    {
        abort_unless(
            (int) $supplier->company_id === (int) session('active_company_id'),
            404
        );
    }

    public function search(Request $request)
{
    $companyId = session('active_company_id');
    $search = $request->get('search');

    if (!$search) {
        return response()->json([]);
    }

    $suppliers = Supplier::query()
        ->where('company_id', $companyId)
        ->where(function ($query) use ($search) {
            $query->where('name', 'like', "%{$search}%")
                ->orWhere('commercial_name', 'like', "%{$search}%")
                ->orWhere('identification', 'like', "%{$search}%")
                ->orWhere('contact_name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('mobile', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%");
        })
        ->orderBy('name')
        ->limit(8)
        ->get([
            'id',
            'name',
            'commercial_name',
            'identification',
            'phone',
            'mobile',
            'email',
        ]);

    return response()->json($suppliers);
}
}