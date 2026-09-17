<?php

namespace App\Services\Notifications;

use App\Models\Company;
use App\Models\User;

/**
 * Catálogo de tipos de alerta del Centro de Notificaciones MVS.
 *
 * Cada tipo define:
 * - notification_permission: permiso necesario para recibir la alerta.
 * - source_permissions: permisos del módulo origen que el usuario debe tener
 *   además del permiso de notificación. Una preferencia NUNCA concede acceso;
 *   solo filtra alertas dentro de lo que el usuario ya está autorizado a ver.
 * - label: nombre visible en UI.
 * - module: agrupación funcional.
 */
class AlertTypeRegistry
{
    public const TYPE_PURCHASE_VERIFICATION = 'purchase_verification';

    public const TYPES = [
        self::TYPE_PURCHASE_VERIFICATION => [
            'label' => 'Verificación de compras',
            'module' => 'Compras',
            'notification_permission' => 'notificaciones.compras',
            'source_permissions' => ['compras.recepcion.verificar', 'compras.recepcion.asignar', 'compras.recepcion.resolver'],
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

    public static function notificationPermission(string $type): ?string
    {
        return self::forType($type)['notification_permission'] ?? null;
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

        if (! $user->hasPermission($definition['notification_permission'], $company)) {
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
