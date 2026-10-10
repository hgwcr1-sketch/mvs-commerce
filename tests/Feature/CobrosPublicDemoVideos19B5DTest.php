<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CobrosPublicDemoVideos19B5DTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_page_is_public_and_shows_both_options_with_preparation_state(): void
    {
        $response = $this->get(route('cobros.demo'));

        $response->assertOk()
            ->assertViewIs('cobros.public-demo')
            ->assertSee('Elegí cómo querés probar el')
            ->assertSee('Conocé el sistema a tu manera')
            ->assertSee('Recorrido guiado')
            ->assertSee('Exploración libre')
            ->assertSee('Recomendado')
            ->assertSee('Te llevamos paso a paso: bienvenida, registro, modalidad, pago simulado y activación')
            ->assertSee('Entrá directo al sistema demo y conocé las pantallas a tu ritmo')
            ->assertSee('Cada visitante inicia desde cero')
            ->assertSee(asset('images/cobros/welcome/03_ilustracion_demo_guiado_ruta.webp'), false)
            ->assertSee(asset('images/cobros/welcome/04_demo_libre_laptop.webp'), false)
            ->assertSee('logo-mvs-cobros.png')
            ->assertSee(route('cobros.landing'), false);

        $this->assertGuest();

        $this->assertSame(2, substr_count($response->getContent(), 'Simulador en preparación'), 'Ambas opciones indican que el simulador aún no existe.');
    }

    public function test_demo_page_includes_country_cta_component(): void
    {
        $this->get(route('cobros.demo'))
            ->assertOk()
            ->assertSee('¡Queremos llegar hasta vos!')
            ->assertSee('mailto:ventas@mvscommerce.com', false);
    }

    public function test_videos_page_is_public_and_shows_four_catalog_cards(): void
    {
        $response = $this->get(route('cobros.videos'));

        $response->assertOk()
            ->assertViewIs('cobros.public-videos')
            ->assertSee('Videos')
            ->assertSee('explicativos')
            ->assertSee('Aprendé de forma rápida y sencilla')
            ->assertSee('Inicio rápido')
            ->assertSee('Registro y modalidades')
            ->assertSee('Cobros del día')
            ->assertSee('Pagos y membresía')
            ->assertSee(asset('images/cobros/welcome/05_video_inicio_rapido.webp'), false)
            ->assertSee(asset('images/cobros/welcome/06_video_registro_modalidades.webp'), false)
            ->assertSee(asset('images/cobros/welcome/07_video_cobros_dia.webp'), false)
            ->assertSee(asset('images/cobros/welcome/08_video_pagos_membresia.webp'), false)
            ->assertSee('logo-mvs-cobros.png')
            ->assertSee(route('cobros.landing'), false);

        $this->assertGuest();

        // Sin contenido ficticio: todo el catálogo está «Próximamente».
        $this->assertSame(4, substr_count($response->getContent(), 'Próximamente'));
    }

    public function test_videos_search_filters_catalog_functionally(): void
    {
        $this->get(route('cobros.videos', ['q' => 'cobros del']))
            ->assertOk()
            ->assertSee('Cobros del día')
            ->assertDontSee('Inicio rápido')
            ->assertDontSee('Registro y modalidades')
            ->assertDontSee('Pagos y membresía');

        $this->get(route('cobros.videos', ['q' => 'pagos']))
            ->assertOk()
            ->assertSee('Pagos y membresía')
            ->assertDontSee('Cobros del día');

        $this->get(route('cobros.videos', ['q' => 'inexistente-xyz']))
            ->assertOk()
            ->assertSee('Sin resultados');
    }

    public function test_welcome_links_to_demo_and_videos_public_pages(): void
    {
        $this->get(route('cobros.landing'))
            ->assertOk()
            ->assertSee('href="'.route('cobros.demo').'"', false)
            ->assertSee('href="'.route('cobros.videos').'"', false);
    }
}
