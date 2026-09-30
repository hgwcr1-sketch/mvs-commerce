<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 3C CobrOS — permiso dedicado cobros.anular.
 * Solo roles que ya operan portal del dueño o supervisión (nunca Cobrador).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('permissions')->where('name', 'cobros.anular')->exists()) {
            DB::table('permissions')->insert([
                'name' => 'cobros.anular',
                'label' => 'Anular cobros en CobrOS',
                'module' => 'CobrOS',
                'description' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $permissionId = DB::table('permissions')->where('name', 'cobros.anular')->value('id');
        if (! $permissionId) {
            return;
        }

        $roleIds = DB::table('roles')
            ->where('roles.is_active', true)
            ->join('permission_role', 'roles.id', '=', 'permission_role.role_id')
            ->join('permissions', 'permission_role.permission_id', '=', 'permissions.id')
            ->whereIn('permissions.name', ['cobros.portal_dueno', 'cobros.supervisar'])
            ->whereNotIn('roles.id', function ($query) use ($permissionId) {
                $query->select('role_id')->from('permission_role')->where('permission_id', $permissionId);
            })
            ->distinct()
            ->pluck('roles.id');

        foreach ($roleIds as $roleId) {
            DB::table('permission_role')->insert([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'cobros.anular')->value('id');
        if ($permissionId) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
