<?php

namespace App\Services\Cobros;

use InvalidArgumentException;

/**
 * BLOQUE 13-2A — catálogo central de modalidades CobrOS.
 *
 * ÚNICA fuente de verdad de las cinco modalidades automáticas y sus permisos
 * predefinidos. Ninguna modalidad exige selección manual de roles desde el
 * Panel Maestro: los permisos se resuelven por servicio (CobrosModalityService).
 *
 * Los permisos usados existen en PermissionSeeder; las reglas de negocio de
 * cobros.asignar / cobros.anular / notificaciones.cobros (solo roles con
 * portal_dueno o supervisar) se respetan en cada conjunto.
 */
final class CobrosModalityCatalog
{
    public const PRESTAMISTA = 'prestamista';
    public const COBRADOR_VINCULADO = 'cobrador_vinculado';
    public const PRESTAMISTA_COBRADOR = 'prestamista_cobrador';
    public const COBRADOR_INDEPENDIENTE = 'cobrador_independiente';
    public const COBRADOR_MULTIPRESTAMISTA = 'cobrador_multiprestamista';

    /** @return list<string> Claves exactas de las cinco modalidades. */
    public static function keys(): array
    {
        return [
            self::PRESTAMISTA,
            self::COBRADOR_VINCULADO,
            self::PRESTAMISTA_COBRADOR,
            self::COBRADOR_INDEPENDIENTE,
            self::COBRADOR_MULTIPRESTAMISTA,
        ];
    }

    public static function has(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }

    /**
     * @return array{label: string, role_name: string, description: string,
     *     permissions: list<string>, administrative: bool, collects: bool,
     *     uses_route_context: bool, multiple_lenders: bool}
     */
    public static function definition(string $key): array
    {
        $definitions = self::all();

        if (! array_key_exists($key, $definitions)) {
            throw new InvalidArgumentException("Modalidad CobrOS desconocida: {$key}");
        }

        return $definitions[$key];
    }

    /** @return list<string> */
    public static function permissions(string $key): array
    {
        return self::definition($key)['permissions'];
    }

    public static function roleName(string $key): string
    {
        return self::definition($key)['role_name'];
    }

    public static function label(string $key): string
    {
        return self::definition($key)['label'];
    }

    /** Conserva funciones administrativas (portal del dueño). */
    public static function isAdministrative(string $key): bool
    {
        return self::definition($key)['administrative'];
    }

    /** Puede registrar cobros (cobros.cobrar). */
    public static function collects(string $key): bool
    {
        return self::definition($key)['collects'];
    }

    /** Al cobrar exige contexto de ruta activa (CobrosRouteContext). */
    public static function usesRouteContext(string $key): bool
    {
        return self::definition($key)['uses_route_context'];
    }

    /** Opera para varios prestamistas (varias empresas/membresías). */
    public static function allowsMultipleLenders(string $key): bool
    {
        return self::definition($key)['multiple_lenders'];
    }

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        $prestamistaPermissions = [
            'cobros.acceder',
            'cobros.portal_dueno',
            'cobros.asignar',
            'cobros.anular',
            'notificaciones.cobros',
        ];

        return [
            self::PRESTAMISTA => [
                'label' => 'Prestamista',
                'role_name' => 'Prestamista',
                'description' => 'Administra cartera, aprueba créditos y supervisa; no registra cobros.',
                'permissions' => $prestamistaPermissions,
                'administrative' => true,
                'collects' => false,
                'uses_route_context' => false,
                'multiple_lenders' => false,
            ],
            self::COBRADOR_VINCULADO => [
                'label' => 'Cobrador vinculado',
                'role_name' => 'Cobrador vinculado',
                'description' => 'Cobra en CobrOS sobre rutas asignadas de su empresa; sin funciones administrativas.',
                'permissions' => ['cobros.acceder', 'cobros.cobrar'],
                'administrative' => false,
                'collects' => true,
                'uses_route_context' => true,
                'multiple_lenders' => false,
            ],
            self::PRESTAMISTA_COBRADOR => [
                'label' => 'Prestamista-cobrador',
                'role_name' => 'Prestamista-Cobrador',
                'description' => 'Conserva funciones administrativas y cobra usando contexto de ruta activa.',
                'permissions' => array_merge($prestamistaPermissions, ['cobros.cobrar']),
                'administrative' => true,
                'collects' => true,
                'uses_route_context' => true,
                'multiple_lenders' => false,
            ],
            self::COBRADOR_INDEPENDIENTE => [
                'label' => 'Cobrador independiente',
                'role_name' => 'Cobrador independiente',
                'description' => 'Cobra su cartera asignada sin vínculo administrativo con el prestamista.',
                'permissions' => ['cobros.acceder', 'cobros.cobrar'],
                'administrative' => false,
                'collects' => true,
                'uses_route_context' => true,
                'multiple_lenders' => false,
            ],
            self::COBRADOR_MULTIPRESTAMISTA => [
                'label' => 'Cobrador multiprestamista',
                'role_name' => 'Cobrador multiprestamista',
                'description' => 'Cobra para varios prestamistas: cada empresa conserva su propia membresía y modalidad.',
                'permissions' => ['cobros.acceder', 'cobros.cobrar'],
                'administrative' => false,
                'collects' => true,
                'uses_route_context' => true,
                'multiple_lenders' => true,
            ],
        ];
    }
}
