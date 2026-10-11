<?php

namespace App\Services\Cobros;

use InvalidArgumentException;

/**
 * BLOQUE 21A-2 — catálogo de capacitación de la Academia MVS CobrOS.
 *
 * ÚNICA fuente de verdad de las TRES rutas de formación que se muestran en la
 * Academia. Es una proyección de presentación sobre CobrosModalityCatalog: NO
 * crea, modifica ni elimina modalidades de negocio; solo agrupa las claves
 * internas existentes para que una persona encuentre su material.
 *
 * Las lecciones son temario declarado. Todas las videos sin producir declaran
 * `video_url => null` y la interfaz debe mostrar "Próximamente" sin ofrecer un
 * reproductor para contenido inexistente. Esta clase NO inventa URLs de video.
 */
final class AcademiaCatalog
{
    public const TRACK_PRESTAMISTA = 'prestamista-administrador';
    public const TRACK_INDEPENDIENTE = 'cobrador-independiente';
    public const TRACK_VINCULADO = 'cobrador-vinculado';

    /** @return list<string> Claves visibles de la Academia (exactamente tres). */
    public static function keys(): array
    {
        return [
            self::TRACK_PRESTAMISTA,
            self::TRACK_INDEPENDIENTE,
            self::TRACK_VINCULADO,
        ];
    }

    public static function has(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }

    /**
     * Ruta de capacitación de una modalidad interna (null si no está cubierta).
     *
     * El agrupamiento es de PRESENTACIÓN y no altera el catálogo de negocio:
     *  - prestamista administrador → solo Prestamista (administrativa, no cobra).
     *  - cobrador vinculado → Cobrador vinculado.
     *  - cobrador independiente → el resto de modalidades que cobran por sí mismas,
     *    incluyendo al prestamista que también cobra (prestamista-cobrador) y al
     *    cobrador de varios prestamistas.
     */
    public static function trackForModality(?string $modality): ?string
    {
        if ($modality === null) {
            return null;
        }

        $grouping = [
            CobrosModalityCatalog::PRESTAMISTA => self::TRACK_PRESTAMISTA,
            CobrosModalityCatalog::COBRADOR_VINCULADO => self::TRACK_VINCULADO,
            CobrosModalityCatalog::COBRADOR_INDEPENDIENTE => self::TRACK_INDEPENDIENTE,
            CobrosModalityCatalog::PRESTAMISTA_COBRADOR => self::TRACK_INDEPENDIENTE,
            CobrosModalityCatalog::COBRADOR_MULTIPRESTAMISTA => self::TRACK_INDEPENDIENTE,
        ];

        return $grouping[$modality] ?? null;
    }

    /**
     * @return array{key: string, title: string, summary: string, description: string,
     *     modality_keys: list<string>,
     *     lessons: list<array{title: string, description: string, duration: ?string, video_url: ?string}>}
     */
    public static function track(string $key): array
    {
        $tracks = self::tracks();

        if (! array_key_exists($key, $tracks)) {
            throw new InvalidArgumentException("Ruta de capacitación desconocida: {$key}");
        }

        return $tracks[$key];
    }

    /**
     * @return array<string, array{key: string, title: string, summary: string,
     *     description: string, modality_keys: list<string>,
     *     lessons: list<array{title: string, description: string, duration: ?string, video_url: ?string}>}>
     */
    public static function tracks(): array
    {
        return [
            self::TRACK_PRESTAMISTA => [
                'key' => self::TRACK_PRESTAMISTA,
                'title' => 'Prestamista administrador',
                'summary' => 'Administra cartera, créditos, rutas y resultados sin registrar cobros.',
                'description' => 'Recorrido del portal del dueño: acceso, cobradores, cartera, aprobación de créditos y reportes.',
                'modality_keys' => [CobrosModalityCatalog::PRESTAMISTA],
                'lessons' => [
                    [
                        'title' => 'Acceso y panel del prestamista',
                        'description' => 'Entrada por /cobros/login, resolución de la empresa activa y lectura del panel de cartera.',
                        'duration' => null,
                        'video_url' => null,
                    ],
                    [
                        'title' => 'Rutas, cobradores y licencias',
                        'description' => 'Alta de rutas, gestión de cobradores y estado de licencias por ruta.',
                        'duration' => null,
                        'video_url' => null,
                    ],
                    [
                        'title' => 'Asignación de cartera',
                        'description' => 'Asignar y reasignar clientes a una ruta, con trazabilidad de la asignación.',
                        'duration' => null,
                        'video_url' => null,
                    ],
                    [
                        'title' => 'Créditos: definir, aprobar y desembolsar',
                        'description' => 'Condiciones del crédito, aprobación, entrega con comprobante y reportes del período.',
                        'duration' => null,
                        'video_url' => null,
                    ],
                ],
            ],
            self::TRACK_INDEPENDIENTE => [
                'key' => self::TRACK_INDEPENDIENTE,
                'title' => 'Cobrador independiente',
                'summary' => 'Cobra por cuenta propia; incluye al prestamista que también cobra.',
                'description' => 'Operación diaria del cobrador: selección de ruta, cartera, registro de cobros y cierre.',
                'modality_keys' => [
                    CobrosModalityCatalog::COBRADOR_INDEPENDIENTE,
                    CobrosModalityCatalog::PRESTAMISTA_COBRADOR,
                    CobrosModalityCatalog::COBRADOR_MULTIPRESTAMISTA,
                ],
                'lessons' => [
                    [
                        'title' => 'Acceso y selección de ruta',
                        'description' => 'Inicio de sesión, elección de la ruta del día y cambio de ruta sin cerrar sesión.',
                        'duration' => null,
                        'video_url' => null,
                    ],
                    [
                        'title' => 'Mi cartera y mis clientes',
                        'description' => 'Consulta de clientes asignados, ficha del cliente y alta de cliente en cartera.',
                        'duration' => null,
                        'video_url' => null,
                    ],
                    [
                        'title' => 'Registrar un cobro y emitir recibo',
                        'description' => 'Cobro parcial o total con confirmación en servidor e impresión del recibo.',
                        'duration' => null,
                        'video_url' => null,
                    ],
                    [
                        'title' => 'Cierre del día',
                        'description' => 'Revisión de cobros del día, custodia y cierre operativo.',
                        'duration' => null,
                        'video_url' => null,
                    ],
                ],
            ],
            self::TRACK_VINCULADO => [
                'key' => self::TRACK_VINCULADO,
                'title' => 'Cobrador vinculado a prestamista',
                'summary' => 'Cobra sobre las rutas que un prestamista le asigna.',
                'description' => 'Entrada por invitación, cartera delegada por el prestamista y cobros dentro de la ruta asignada.',
                'modality_keys' => [CobrosModalityCatalog::COBRADOR_VINCULADO],
                'lessons' => [
                    [
                        'title' => 'Invitación y primer acceso',
                        'description' => 'Registro con la invitación del prestamista y creación de la única cuenta del cobrador.',
                        'duration' => null,
                        'video_url' => null,
                    ],
                    [
                        'title' => 'Cartera asignada por el prestamista',
                        'description' => 'Clientes y créditos que el prestamista asigna a su ruta; alcance de lo que puede consultar.',
                        'duration' => null,
                        'video_url' => null,
                    ],
                    [
                        'title' => 'Cobrar dentro de la ruta',
                        'description' => 'Registro de cobros con la ruta activa, permisos y validaciones de la empresa.',
                        'duration' => null,
                        'video_url' => null,
                    ],
                    [
                        'title' => 'Recibos, historial y cierre',
                        'description' => 'Consulta de recibos propios, historial de cobros y cierre del día.',
                        'duration' => null,
                        'video_url' => null,
                    ],
                ],
            ],
        ];
    }

    /** true si alguna lección de la ruta ya tiene video publicado. */
    public static function hasVideo(array $track): bool
    {
        foreach ($track['lessons'] as $lesson) {
            if (! empty($lesson['video_url'])) {
                return true;
            }
        }

        return false;
    }
}
