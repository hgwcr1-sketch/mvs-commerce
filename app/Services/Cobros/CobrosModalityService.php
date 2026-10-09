<?php

namespace App\Services\Cobros;

use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * BLOQUE 13-2A — asignación y cambio de modalidades CobrOS.
 *
 * Reglas:
 *  - El catálogo (CobrosModalityCatalog) es la única fuente de permisos.
 *  - La modalidad vive en company_user.cobros_modality (por membresía).
 *  - role_id SOLO se escribe cuando el rol actual no cubre los permisos de la
 *    modalidad; en ese caso se crea/reutiliza un rol combinado con la UNIÓN de
 *    permisos. Nunca se quitan permisos ni se sobrescribe el rol de un
 *    miembro que ya cumple la modalidad (incluye roles super admin).
 *  - No modifica clientes, créditos, rutas, licencias ni historial.
 *  - No requiere selección manual de roles desde Panel Maestro.
 */
class CobrosModalityService
{
    /** Modalidad vigente de la membresía; null si no tiene modalidad. */
    public function currentModality(Company $company, User $user): ?string
    {
        $membership = $this->membership($company, $user);
        if ($membership === null) {
            return null;
        }

        $stored = $membership->cobros_modality;
        if ($stored !== null && CobrosModalityCatalog::has($stored)) {
            return $stored;
        }

        // Compatibilidad con roles de modalidad pura preexistentes.
        $role = $membership->role_id
            ? Role::where('company_id', $company->id)->find($membership->role_id)
            : null;

        if ($role !== null) {
            foreach (CobrosModalityCatalog::keys() as $key) {
                if ($role->name === CobrosModalityCatalog::roleName($key)) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * Rol de la modalidad para la empresa (idempotente). Solo AGREGA
     * permisos del catálogo a ese rol; jamás los remueve.
     */
    public function ensureRole(Company $company, string $modality): Role
    {
        $this->assertValidModality($modality);

        return DB::transaction(function () use ($company, $modality) {
            $role = Role::firstOrCreate(
                ['company_id' => $company->id, 'name' => CobrosModalityCatalog::roleName($modality)],
                [
                    'description' => CobrosModalityCatalog::definition($modality)['description'],
                    'is_active' => true,
                ]
            );

            $this->grantPermissions($role, CobrosModalityCatalog::permissions($modality));

            return $role;
        });
    }

    /**
     * Asigna o cambia la modalidad de la membresía conservando clientes,
     * créditos, rutas e historial (no se tocan esas entidades).
     *
     * @return array{role: Role, role_changed: bool, modality: string}
     */
    public function assign(Company $company, User $user, string $modality): array
    {
        $this->assertValidModality($modality);

        return DB::transaction(function () use ($company, $user, $modality) {
            $membership = $this->membership($company, $user, true);
            if ($membership === null) {
                throw ValidationException::withMessages([
                    'modality' => 'El usuario no pertenece a esta empresa.',
                ]);
            }

            $currentRole = $membership->role_id
                ? Role::where('company_id', $company->id)->find($membership->role_id)
                : null;

            $targetRole = $currentRole;
            $roleChanged = false;

            if ($currentRole === null) {
                $targetRole = $this->ensureRole($company, $modality);
                $roleChanged = true;
            } elseif (! $this->covers($currentRole, $modality)) {
                $targetRole = $this->combinedRole($company, $currentRole, $modality);
                $roleChanged = true;
            }

            $update = ['cobros_modality' => $modality, 'updated_at' => now()];
            if ($roleChanged && $targetRole !== null) {
                $update['role_id'] = $targetRole->id;
            }

            DB::table('company_user')->where('id', $membership->id)->update($update);

            return [
                'role' => $targetRole->fresh(),
                'role_changed' => $roleChanged,
                'modality' => $modality,
            ];
        });
    }

    /** El rol actual ya garantiza todos los permisos de la modalidad. */
    private function covers(Role $role, string $modality): bool
    {
        if (! $role->is_active) {
            return false;
        }

        // Los super admin heredan todos los permisos activos: no se toca role_id.
        if ($role->is_super_admin) {
            return true;
        }

        $granted = $role->permissions()
            ->where('permissions.is_active', true)
            ->pluck('permissions.name')
            ->all();

        return array_diff(CobrosModalityCatalog::permissions($modality), $granted) === [];
    }

    /**
     * Rol combinado = UNIÓN del rol actual + permisos de la modalidad.
     * El nombre mantiene el rol base ("Cajero + Prestamista-Cobrador") para
     * reutilizar el mismo rol entre miembros con la misma combinación.
     */
    private function combinedRole(Company $company, Role $current, string $modality): Role
    {
        $base = explode(' + ', $current->name)[0];
        $name = mb_substr($base.' + '.CobrosModalityCatalog::label($modality), 0, 100);

        $role = Role::firstOrCreate(
            ['company_id' => $company->id, 'name' => $name],
            ['description' => 'Rol combinado (unión de permisos, nunca revoca).', 'is_active' => true]
        );

        // Unión: permisos actuales + permisos de la modalidad, solo agregar.
        $catalogIds = collect(CobrosModalityCatalog::permissions($modality))
            ->map(fn (string $name) => Permission::firstOrCreate(
                ['name' => $name],
                ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]
            )->id);

        $permissionIds = $current->permissions()->pluck('permissions.id')
            ->merge($catalogIds)
            ->unique()
            ->values();

        $role->permissions()->syncWithoutDetaching($permissionIds->all());

        return $role;
    }

    /** @param list<string> $permissionNames */
    private function grantPermissions(Role $role, array $permissionNames): void
    {
        foreach ($permissionNames as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]
            );

            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
    }

    private function membership(Company $company, User $user, bool $lock = false): ?object
    {
        $query = DB::table('company_user')
            ->where('company_id', $company->id)
            ->where('user_id', $user->id);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function assertValidModality(string $modality): void
    {
        if (! CobrosModalityCatalog::has($modality)) {
            throw ValidationException::withMessages([
                'modality' => 'Modalidad desconocida: '.$modality,
            ]);
        }
    }
}
