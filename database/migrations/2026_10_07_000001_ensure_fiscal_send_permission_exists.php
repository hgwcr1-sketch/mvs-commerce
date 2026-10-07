<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Garantiza que el permiso de envío/reenvío de comprobantes exista en la base destino.
 *
 * Separa el envío de comprobantes (fiscal.enviar_comprobante) de la
 * configuración fiscal sensible (fiscal.editar). El permiso se crea en
 * PermissionSeeder, que no forma parte del procedimiento de despliegue
 * (solo `migrate --force`). Los roles Administrador (is_super_admin)
 * heredan todos los permisos activos; los demás roles lo reciben solo si
 * se les asigna desde Roles/Permisos.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->updateOrInsert(
            ['name' => 'fiscal.enviar_comprobante'],
            [
                'label' => 'Enviar/reenviar comprobantes',
                'module' => 'Facturación Electrónica',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('name', 'fiscal.enviar_comprobante')->value('id');

        if ($id === null) {
            return;
        }

        if (DB::table('permission_role')->where('permission_id', $id)->exists()) {
            DB::table('permissions')->where('id', $id)->update(['is_active' => false]);

            return;
        }

        DB::table('permissions')->where('id', $id)->delete();
    }
};
