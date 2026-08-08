<?php

namespace App\Http\Controllers;
use App\Models\Role; use App\Models\Permission;

use Illuminate\Http\Request;

class RoleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
{
    $companyId = session('active_company_id');

    $roles = Role::where('company_id', $companyId)
        ->withCount(['users', 'permissions'])
        ->orderBy('name')
        ->get();

    return view('roles.index', compact('roles'));
}

    /**
     * Show the form for creating a new resource.
     */
    public function create()
{
    $permissions = Permission::where('is_active', true)
        ->orderBy('module')
        ->orderBy('label')
        ->get()
        ->groupBy('module');

    return view('roles.create', compact('permissions'));
}

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
{
    $companyId = session('active_company_id');

    $validated = $request->validate([
        'name' => [
            'required',
            'string',
            'max:100',
        ],
        'description' => [
            'nullable',
            'string',
            'max:255',
        ],
        'is_active' => [
            'nullable',
            'boolean',
        ],
        'permissions' => [
            'nullable',
            'array',
        ],
        'permissions.*' => [
            'integer',
            'exists:permissions,id',
        ],
    ], [
        'name.required' => 'El nombre del rol es obligatorio.',
        'name.max' => 'El nombre del rol no puede superar los 100 caracteres.',
        'description.max' => 'La descripción no puede superar los 255 caracteres.',
        'permissions.array' => 'Los permisos seleccionados no son válidos.',
        'permissions.*.exists' => 'Uno de los permisos seleccionados no es válido.',
    ]);

    $exists = Role::where('company_id', $companyId)
        ->where('name', $validated['name'])
        ->exists();

    if ($exists) {
        return back()
            ->withInput()
            ->withErrors([
                'name' => 'Ya existe un rol con este nombre en la empresa.',
            ]);
    }

    $role = Role::create([
        'company_id' => $companyId,
        'name' => $validated['name'],
        'description' => $validated['description'] ?? null,
        'is_active' => $request->boolean('is_active'),
    ]);

    $role->permissions()->sync(
        $validated['permissions'] ?? []
    );

    return redirect()
        ->route('roles.index')
        ->with('success', 'Rol creado correctamente.');
}

    /**
     * Display the specified resource.
     */
    /**
 * Display the specified resource.
 */
public function show(string $id)
{
    $companyId = session('active_company_id');

    $role = Role::where('company_id', $companyId)
        ->with([
            'permissions' => function ($query) {
                $query->orderBy('module')
                    ->orderBy('label');
            },
            'users',
        ])
        ->findOrFail($id);

    $permissionsByModule = $role->permissions
        ->groupBy('module');

    return view('roles.show', compact(
        'role',
        'permissionsByModule'
    ));
}

    /**
 * Show the form for editing the specified resource.
 */
public function edit(string $id)
{
    $companyId = session('active_company_id');

    $role = Role::where('company_id', $companyId)
        ->with('permissions')
        ->findOrFail($id);

    $permissions = Permission::where('is_active', true)
        ->orderBy('module')
        ->orderBy('label')
        ->get()
        ->groupBy('module');

    $selectedPermissions = $role->permissions
        ->pluck('id')
        ->toArray();

    return view('roles.edit', compact(
        'role',
        'permissions',
        'selectedPermissions'
    ));
}

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
{
    $companyId = session('active_company_id');

    $role = Role::where('company_id', $companyId)
        ->findOrFail($id);

    $validated = $request->validate([
        'name' => [
            'required',
            'string',
            'max:100',
        ],
        'description' => [
            'nullable',
            'string',
            'max:255',
        ],
        'is_active' => [
            'nullable',
            'boolean',
        ],
        'permissions' => [
            'nullable',
            'array',
        ],
        'permissions.*' => [
            'integer',
            'exists:permissions,id',
        ],
    ], [
        'name.required' => 'El nombre del rol es obligatorio.',
        'name.max' => 'El nombre del rol no puede superar los 100 caracteres.',
        'description.max' => 'La descripción no puede superar los 255 caracteres.',
        'permissions.array' => 'Los permisos seleccionados no son válidos.',
        'permissions.*.exists' => 'Uno de los permisos seleccionados no es válido.',
    ]);

    $exists = Role::where('company_id', $companyId)
        ->where('name', $validated['name'])
        ->where('id', '!=', $role->id)
        ->exists();

    if ($exists) {
        return back()
            ->withInput()
            ->withErrors([
                'name' => 'Ya existe otro rol con este nombre en la empresa.',
            ]);
    }

    $role->update([
        'name' => $validated['name'],
        'description' => $validated['description'] ?? null,
        'is_active' => $request->boolean('is_active'),
    ]);

    $role->permissions()->sync(
        $validated['permissions'] ?? []
    );

    return redirect()
        ->route('roles.index')
        ->with('success', 'Rol actualizado correctamente.');
}

    /**
     * Remove the specified resource from storage.
     */
    /**
 * Remove the specified resource from storage.
 */
public function destroy(string $id)
{
    $companyId = session('active_company_id');

    $role = Role::where('company_id', $companyId)
        ->withCount('users')
        ->findOrFail($id);

    if ($role->users_count > 0) {
        return redirect()
            ->route('roles.index')
            ->with(
                'error',
                'No se puede eliminar este rol porque tiene usuarios asignados.'
            );
    }

    $role->permissions()->detach();

    $role->delete();

    return redirect()
        ->route('roles.index')
        ->with('success', 'Rol eliminado correctamente.');
}
}
