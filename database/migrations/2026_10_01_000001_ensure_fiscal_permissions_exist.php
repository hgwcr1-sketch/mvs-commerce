<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Garantiza que los permisos del portal fiscal existan en la base destino.
 *
 * Los permisos se crean en PermissionSeeder, que no forma parte del
 * procedimiento de despliegue (solo `migrate --force`). Sin esta migración, una
 * empresa con Facturación Electrónica habilitada no ve el acceso porque
 * `fiscal.ver` no existe en la base y ningún rol puede heredarlo.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        ['name' => 'fiscal.ver', 'label' => 'Ver portal fiscal'],
        ['name' => 'fiscal.editar', 'label' => 'Configurar conexión fiscal'],
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission['name']],
                [
                    'label' => $permission['label'],
                    'module' => 'Facturación Electrónica',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            $id = DB::table('permissions')->where('name', $permission['name'])->value('id');

            if ($id === null) {
                continue;
            }

            $assigned = DB::table('permission_role')->where('permission_id', $id)->exists();

            if ($assigned) {
                continue;
            }

            DB::table('permissions')->where('id', $id)->delete();
        }
    }
};
