<?php

namespace App\Services\Notifications;

use App\Models\Company;
use App\Models\User;

/**
 * Catálogo de tipos de alerta del Centro de Notificaciones MVS.
 *
 * Cada tipo define:
 * - notification_permission: permiso de notificación necesario para recibir la
 *   alerta (string o array: basta con poseer uno de ellos).
 * - source_permissions: permisos del módulo origen que el usuario debe tener
 *   además del permiso de notificación. Una preferencia NUNCA concede acceso;
 *   solo filtra alertas dentro de lo que el usuario ya está autorizado a ver.
 * - label: nombre visible en UI.
 * - module: agrupación funcional.
 */
class AlertTypeRegistry
{
    public const TYPE_PURCHASE_VERIFICATION = 'purchase_verification';

    public const TYPE_TRANSFER_RECEIPT = 'transfer_receipt';

    public const TYPES = [
        self::TYPE_PURCHASE_VERIFICATION => [
            'label' => 'Verificación de compras',
            'module' => 'Compras',
            'notification_permission' => 'notificaciones.compras',
            'source_permissions' => ['compras.recepcion.verificar', 'compras.recepcion.asignar', 'compras.recepcion.resolver'],
        ],
        self::TYPE_TRANSFER_RECEIPT => [
            'label' => 'Recepción de traslados',
            'module' => 'Inventario',
            // El destino del traslado necesita poder VER o RECIBIR transferencias;
            // el origen (y flujos históricos) conserva inventario.transferir.
            'notification_permission' => ['notificaciones.inventario', 'notificaciones.traslados'],
            'source_permissions' => [
                'inventario.transferir',
                'inventario.transferencias.ver',
                'inventario.transferencias.recibir',
            ],
        ],
    ];

    public static function all(): array
    {
        return self::TYPES;
    }

    public static function forType(string $type): ?array
    {
        return self::TYPES[$type] ?? null;
    }

    /**
     * Primer permiso de notificación del tipo (compatibilidad: el tipo puede
     * definir varios permisos de notificación aceptados).
     */
    public static function notificationPermission(string $type): ?string
    {
        $permission = self::forType($type)['notification_permission'] ?? null;

        if (is_array($permission)) {
            return $permission[0] ?? null;
        }

        return $permission;
    }

    public static function sourcePermissions(string $type): array
    {
        return self::forType($type)['source_permissions'] ?? [];
    }

    public static function label(string $type): string
    {
        return self::forType($type)['label'] ?? $type;
    }

    public static function module(string $type): ?string
    {
        return self::forType($type)['module'] ?? null;
    }

    /**
     * Verifica si un tipo de alerta requiere el permiso de notificación
     * y al menos uno de los permisos del módulo origen.
     */
    public static function isAuthorizedFor(User $user, Company $company, string $type): bool
    {
        $definition = self::forType($type);
        if ($definition === null) {
            return false;
        }

        $notificationPermissions = (array) ($definition['notification_permission'] ?? []);
        $hasNotificationPermission = false;
        foreach ($notificationPermissions as $permission) {
            if ($user->hasPermission($permission, $company)) {
                $hasNotificationPermission = true;
                break;
            }
        }

        if (! $hasNotificationPermission) {
            return false;
        }

        foreach ($definition['source_permissions'] as $permission) {
            if ($user->hasPermission($permission, $company)) {
                return true;
            }
        }

        return false;
    }
}
