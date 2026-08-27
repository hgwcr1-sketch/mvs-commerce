<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyLicenseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    /**
     * Mostrar listado de usuarios.
     */
    public function index()
    {
        $companyId = session('active_company_id');

        $search = request('search');

        $users = User::whereHas('companies', function ($query) use ($companyId) {
            $query->where('companies.id', $companyId);
        })
            ->with([
            'companies' => function ($query) use ($companyId) {
                $query->where('companies.id', $companyId)
                    ->withPivot('role_id');
            },
        ])
            ->when($search, function ($query) use ($search) {

                $query->where(function ($query) use ($search) {

                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");

                });

            })
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();
        $roleIds = $users->getCollection()
            ->map(function ($user) {
                return $user->companies->first()?->pivot->role_id;
            })
            ->filter()
            ->unique();

        $rolesById = Role::whereIn('id', $roleIds)
            ->get()
            ->keyBy('id');

        $users->getCollection()->each(function ($user) use ($rolesById) {

            $roleId = $user->companies->first()?->pivot->role_id;

            $user->current_company_role = $roleId
                ? $rolesById->get($roleId)
                : null;
        });

        return view('users.index', compact('users'));
    }

    /**
     * Formulario para crear usuario.
     */
    public function create()
    {
        $companyId = session('active_company_id');

        $usuario = new User;

        $roles = Role::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $branches = Branch::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $selectedBranchIds = [];

        return view('users.create', compact(
            'usuario',
            'roles',
            'branches',
            'selectedBranchIds'
        ));
    }

    /**
     * Guardar usuario.
     */
    public function store(StoreUserRequest $request, CompanyLicenseService $licenses)
    {
        $companyId = session('active_company_id');
        $licenses->assertCapacity(Company::findOrFail($companyId), 'users');

        $data = $request->validated();

        $branchIds = $data['branches'];

        unset($data['branches']);

        /**
         * Verificar que el rol seleccionado pertenece
         * a la empresa activa.
         */
        $role = Role::where('company_id', $companyId)
            ->where('is_active', true)
            ->findOrFail($data['role_id']);

        $roleId = $role->id;

        unset($data['role_id']);

        /**
         * Buscar si la cuenta ya existe globalmente
         * utilizando el correo electrónico.
         */
        $user = User::where('email', $data['email'])->first();

        if ($user) {

            /**
             * Si ya pertenece a esta empresa,
             * no crear ni duplicar la relación.
             */
            $alreadyInCompany = $user->companies()
                ->where('companies.id', $companyId)
                ->exists();

            if ($alreadyInCompany) {

                return back()
                    ->withInput()
                    ->withErrors([
                        'email' => 'Este usuario ya pertenece a la empresa activa.',
                    ]);
            }

            /**
             * La cuenta existe, pero no pertenece
             * a esta empresa.
             *
             * Conservamos sus datos globales y contraseña.
             * Solamente le damos acceso a esta empresa.
             */
            $user->companies()->attach($companyId, [
                'role_id' => $roleId,
            ]);
            $user->branches()->syncWithoutDetaching($branchIds);

            return redirect()
                ->route('usuarios.index')
                ->with(
                    'success',
                    'Usuario existente agregado a la empresa correctamente.'
                );
        }

        /**
         * Si no existe globalmente, crear una cuenta nueva.
         */
        if ($request->hasFile('photo')) {
            $data['photo'] = $request->file('photo')->store('users', 'public');
        }

        $data['password'] = Hash::make($data['password']);

        $user = User::create($data);

        /**
         * Vincular la nueva cuenta con
         * la empresa activa y su rol.
         */
        $user->companies()->attach($companyId, [
            'role_id' => $roleId,
        ]);
        $user->branches()->syncWithoutDetaching($branchIds);

        return redirect()
            ->route('usuarios.index')
            ->with('success', 'Usuario creado correctamente.');
    }

    /**
     * Mostrar usuario.
     */
    public function show(User $usuario)
    {
        $companyId = session('active_company_id');

        /**
         * Verificar que el usuario pertenece
         * a la empresa activa.
         */
        $company = $usuario->companies()
            ->where('companies.id', $companyId)
            ->firstOrFail();

        /**
         * Obtener el rol que tiene el usuario
         * dentro de la empresa activa.
         */
        $role = Role::where('company_id', $companyId)
            ->find($company->pivot->role_id);

        return view('users.show', compact(
            'usuario',
            'role'
        ));
    }

    /**
     * Formulario para editar usuario.
     */
    public function edit(User $usuario)
    {
        $companyId = session('active_company_id');

        $company = $usuario->companies()
            ->where('companies.id', $companyId)
            ->firstOrFail();

        $roles = Role::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $branches = Branch::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $selectedRoleId = $company->pivot->role_id;

        $selectedBranchIds = $usuario->branches()
            ->where('branches.company_id', $companyId)
            ->pluck('branches.id')
            ->toArray();

        return view('users.edit', compact(
            'usuario',
            'roles',
            'branches',
            'selectedRoleId',
            'selectedBranchIds'
        ));
    }

    /**
     * Actualizar usuario.
     */
    public function update(UpdateUserRequest $request, User $usuario)
    {
        $companyId = session('active_company_id');

        $usuario->companies()
            ->where('companies.id', $companyId)
            ->firstOrFail();

        $data = $request->validated();

        $belongsToMultipleCompanies = $usuario->companies()->count() > 1;

        if ($belongsToMultipleCompanies) {
            $this->ensureGlobalUserDataIsUnchanged($request, $usuario);
        }

        $branchIds = $data['branches'];

        unset($data['branches']);

        $role = Role::where('company_id', $companyId)
            ->where('is_active', true)
            ->findOrFail($data['role_id']);

        $roleId = $role->id;

        unset($data['role_id']);

        $currentRole = $usuario->roleInCompany(
            Company::findOrFail($companyId)
        );

        if (
            $currentRole?->name === 'Administrador'
            && (
                $role->id !== $currentRole->id
                || ! $request->boolean('is_active')
            )
            && $this->activeAdministratorCount($companyId) <= 1
        ) {
            throw ValidationException::withMessages([
                'role_id' => 'No puede dejar sin acceso al último Administrador activo de la empresa.',
            ]);
        }

        if ($belongsToMultipleCompanies) {
            $data = [];
        }

        if ($request->hasFile('photo')) {

            if ($usuario->photo) {
                Storage::disk('public')->delete($usuario->photo);
            }

            $data['photo'] = $request->file('photo')->store('users', 'public');
        }

        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        DB::transaction(function () use (
            $usuario,
            $data,
            $companyId,
            $roleId,
            $branchIds,
        ) {
            if ($data !== []) {
                $usuario->update($data);
            }

            $usuario->companies()->updateExistingPivot(
                $companyId,
                ['role_id' => $roleId]
            );

            $companyBranchIds = Branch::where('company_id', $companyId)
                ->pluck('id');

            $usuario->branches()->detach($companyBranchIds);
            $usuario->branches()->attach($branchIds);
        });

        return redirect()
            ->route('usuarios.index')
            ->with('success', 'Usuario actualizado correctamente.');
    }

    /**
     * Eliminar usuario.
     */
    /**
     * Eliminar usuario.
     */
    public function destroy(User $usuario)
    {
        $companyId = session('active_company_id');

        /**
         * Verificar que el usuario pertenece
         * a la empresa activa.
         */
        $usuario->companies()
            ->where('companies.id', $companyId)
            ->firstOrFail();

        /**
         * Impedir que el usuario actualmente conectado
         * se elimine a sí mismo.
         */
        if ($usuario->id === auth()->id()) {

            return redirect()
                ->route('usuarios.index')
                ->with(
                    'error',
                    'No puede eliminar su propio usuario mientras está utilizando el sistema.'
                );
        }

        $role = $usuario->roleInCompany(
            Company::findOrFail($companyId)
        );

        if (
            $role?->name === 'Administrador'
            && $this->activeAdministratorCount($companyId) <= 1
        ) {
            return redirect()
                ->route('usuarios.index')
                ->with(
                    'error',
                    'No puede retirar al último Administrador activo de la empresa.'
                );
        }

        /**
         * Quitar solamente la relación con la empresa activa.
         *
         * NO eliminamos el registro global de users,
         * porque el mismo usuario podría pertenecer
         * a otras empresas.
         */
        DB::transaction(function () use ($usuario, $companyId) {
            $companyBranchIds = Branch::where('company_id', $companyId)
                ->pluck('id');

            $usuario->branches()->detach($companyBranchIds);
            $usuario->companies()->detach($companyId);
        });

        return redirect()
            ->route('usuarios.index')
            ->with(
                'success',
                'Usuario eliminado de la empresa correctamente.'
            );
    }

    private function ensureGlobalUserDataIsUnchanged(
        UpdateUserRequest $request,
        User $user
    ): void {
        $changed = collect(['name', 'email', 'phone', 'is_active'])
            ->contains(function (string $field) use ($request, $user) {
                return (string) $request->input($field) !== (string) $user->{$field};
            });

        if (
            $changed
            || $request->filled('password')
            || $request->hasFile('photo')
        ) {
            throw ValidationException::withMessages([
                'user' => 'Los datos globales de un usuario multiempresa no pueden modificarse desde una empresa individual.',
            ]);
        }
    }

    private function activeAdministratorCount(int $companyId): int
    {
        return User::query()
            ->where('users.is_active', true)
            ->whereHas('roles', function ($query) use ($companyId) {
                $query->where('roles.company_id', $companyId)
                    ->where('roles.name', 'Administrador')
                    ->where('roles.is_active', true);
            })
            ->count();
    }
}
