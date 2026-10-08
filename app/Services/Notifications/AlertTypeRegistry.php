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

    public const TYPE_COBROS_PAYMENT_REGISTERED = 'cobros.payment_registered';

    public const TYPE_COBROS_PAYMENT_ANNULLED = 'cobros.payment_annulled';

    public const TYPE_COBROS_PROMISE_OVERDUE = 'cobros.promise_overdue';

    public const TYPE_COBROS_CUSTOMER_NOT_FOUND = 'cobros.customer_not_found';

    public const TYPE_COBROS_CUSTODY_PENDING = 'cobros.custody_pending';

    public const TYPE_COBROS_LICENSE_EXPIRING = 'cobros.license_expiring';

    public const TYPE_COBROS_CREDIT_REQUEST = 'cobros.credit_request';

    public const TYPES = [
        self::TYPE_PURCHASE_VERIFICATION => [
            'label' => 'Verificación de compras',
            'module' => 'Compras',
            'notification_permission' => 'notificaciones.compras',
            'source_permissions' => ['compras.recepcion.verificar', 'compras.recepcion.asignar', 'compras.recepcion.resolver'],
        ],
        // Fase 5A CobrOS — canal CobrOS del Centro de Notificaciones.
        // Solo roles CobrOS (dueño/supervisor); una preferencia nunca concede acceso.
        self::TYPE_COBROS_PAYMENT_REGISTERED => [
            'label' => 'Cobro registrado (CobrOS)',
            'module' => 'CobrOS',
            'notification_permission' => 'notificaciones.cobros',
            'source_permissions' => ['cobros.portal_dueno', 'cobros.supervisar'],
        ],
        self::TYPE_COBROS_PAYMENT_ANNULLED => [
            'label' => 'Cobro anulado (CobrOS)',
            'module' => 'CobrOS',
            'notification_permission' => 'notificaciones.cobros',
            'source_permissions' => ['cobros.portal_dueno', 'cobros.supervisar'],
        ],
        self::TYPE_COBROS_PROMISE_OVERDUE => [
            'label' => 'Promesa de pago vencida (CobrOS)',
            'module' => 'CobrOS',
            'notification_permission' => 'notificaciones.cobros',
            'source_permissions' => ['cobros.portal_dueno', 'cobros.supervisar'],
        ],
        self::TYPE_COBROS_CUSTOMER_NOT_FOUND => [
            'label' => 'Cliente no localizado (CobrOS)',
            'module' => 'CobrOS',
            'notification_permission' => 'notificaciones.cobros',
            'source_permissions' => ['cobros.portal_dueno', 'cobros.supervisar'],
        ],
        self::TYPE_COBROS_CUSTODY_PENDING => [
            'label' => 'Custodia pendiente (CobrOS)',
            'module' => 'CobrOS',
            'notification_permission' => 'notificaciones.cobros',
            'source_permissions' => ['cobros.portal_dueno', 'cobros.supervisar'],
        ],
        self::TYPE_COBROS_LICENSE_EXPIRING => [
            'label' => 'Licencia próxima a vencer (CobrOS)',
            'module' => 'CobrOS',
            'notification_permission' => 'notificaciones.cobros',
            'source_permissions' => ['cobros.portal_dueno', 'cobros.supervisar'],
        ],
        // BLOQUE 8 — campana CobrOS: nueva solicitud y corrección/reenvío.
        // notification_permission usa un permiso del módulo CobrOS (NO
        // notificaciones.*): el gate del Core exige el módulo "notifications"
        // contratado, que los tenants CobrOS no tienen habilitado → 0
        // destinatarios. Con 'cobros.acceder' el gate respeta el módulo real
        // (cobros) y el aislamiento sigue por source_permissions + destinatario.
        self::TYPE_COBROS_CREDIT_REQUEST => [
            'label' => 'Solicitud de crédito (CobrOS)',
            'module' => 'CobrOS',
            'notification_permission' => 'cobros.acceder',
            'source_permissions' => ['cobros.portal_dueno'],
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
