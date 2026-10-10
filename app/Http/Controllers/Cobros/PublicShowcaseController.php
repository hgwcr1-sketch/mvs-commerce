<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cobros;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * PRE-19B-5D — páginas públicas DEMO y Videos.
 * Sin motor de simulador: ambos estados muestran «Simulador en preparación» /
 * «Próximamente». Rutas propias de CobrOS, listas para conectar Academy luego.
 */
final class PublicShowcaseController
{
    /** Catálogo de videos: miniaturas aprobadas, sin duración ni enlaces ficticios. */
    private const VIDEOS = [
        [
            'id' => 'inicio-rapido',
            'title' => 'Inicio rápido',
            'description' => 'Descubra en minutos lo esencial de MVS CobrOS: cartera, cobros y rutas en un solo lugar.',
            'image' => 'images/cobros/welcome/05_video_inicio_rapido.webp',
        ],
        [
            'id' => 'registro-modalidades',
            'title' => 'Registro y modalidades',
            'description' => 'Cómo crear una cuenta y elegir entre prestamista, cobrador independiente o trabajo para un prestamista.',
            'image' => 'images/cobros/welcome/06_video_registro_modalidades.webp',
        ],
        [
            'id' => 'cobros-dia',
            'title' => 'Cobros del día',
            'description' => 'Gestione el cobro diario de sus rutas con comprobante, referencia y seguimiento en tiempo real.',
            'image' => 'images/cobros/welcome/07_video_cobros_dia.webp',
        ],
        [
            'id' => 'pagos-membresia',
            'title' => 'Pagos y membresía',
            'description' => 'Planes, métodos de pago y cómo mantener su membresía activa sin interrumpir la operación.',
            'image' => 'images/cobros/welcome/08_video_pagos_membresia.webp',
        ],
    ];

    public function demo(): View
    {
        return view('cobros.public-demo');
    }

    public function videos(Request $request): View
    {
        $query = trim((string) $request->query('q', ''));

        $videos = array_values(array_filter(self::VIDEOS, static function (array $video) use ($query): bool {
            if ($query === '') {
                return true;
            }

            $haystack = mb_strtolower($video['title'].' '.$video['description']);

            return mb_strpos($haystack, mb_strtolower($query)) !== false;
        }));

        return view('cobros.public-videos', [
            'videos' => $videos,
            'query' => $query,
        ]);
    }
}
