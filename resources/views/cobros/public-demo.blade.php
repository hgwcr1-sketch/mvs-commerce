<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Pruebe MVS CobrOS: recorrido guiado o exploración libre con datos ficticios.">
    <meta name="theme-color" content="#D4AF37">
    <title>DEMO | MVS CobrOS</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-black text-white antialiased">
    @include('cobros.public-header', ['showLogin' => false])

    <main>
        {{-- PRE-19B-5D — página DEMO pública (mobile-first, negro/blanco/dorado) --}}
        <section class="mx-auto max-w-5xl px-4 pt-8 sm:px-6 sm:pt-12" aria-label="Bienvenida DEMO">
            <p class="text-xs font-bold uppercase tracking-widest text-primary">MVS CobrOS · DEMO</p>
            <h1 class="mt-3 text-3xl font-black tracking-tight sm:text-5xl">Elegí cómo querés probar el <span class="text-primary">DEMO</span></h1>
            <p class="mx-auto mt-3 max-w-2xl text-base leading-6 text-slate-300 sm:text-lg sm:leading-7">
                Conocé el sistema a tu manera.
            </p>
        </section>

        <section class="mx-auto max-w-5xl px-4 py-8 sm:px-6 sm:py-10" aria-label="Opciones de DEMO">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                {{-- 1. Recorrido guiado --}}
                <article class="flex flex-col overflow-hidden rounded-2xl bg-white text-black">
                    <img
                        src="{{ asset('images/cobros/welcome/03_ilustracion_demo_guiado_ruta.webp') }}"
                        alt="Ilustración de una ruta de cobro con puntos y banderita"
                        class="h-44 w-full object-cover"
                        loading="lazy"
                        decoding="async"
                    >
                    <div class="flex flex-1 flex-col p-5 sm:p-6">
                        <div class="flex items-start justify-between gap-3">
                            <h2 class="text-xl font-bold">Recorrido guiado</h2>
                            <span class="rounded-full bg-primary px-3 py-1 text-xs font-bold uppercase tracking-widest text-black">Recomendado</span>
                        </div>
                        <p class="mt-2 text-sm leading-6 text-slate-600">Te llevamos paso a paso: bienvenida, registro, modalidad, pago simulado y activación.</p>
                        <div class="mt-auto flex items-center justify-between pt-5">
                            <span class="text-xs font-bold uppercase tracking-widest text-slate-500">Simulador en preparación</span>
                            <span class="inline-flex h-11 w-11 items-center justify-center rounded-xl border border-primary text-xl font-bold text-primary" aria-hidden="true">→</span>
                        </div>
                    </div>
                </article>

                {{-- 2. Exploración libre --}}
                <article class="flex flex-col overflow-hidden rounded-2xl bg-white text-black">
                    <img
                        src="{{ asset('images/cobros/welcome/04_demo_libre_laptop.webp') }}"
                        alt="Laptop con el panel de control de MVS CobrOS"
                        class="h-44 w-full object-cover"
                        loading="lazy"
                        decoding="async"
                    >
                    <div class="flex flex-1 flex-col p-5 sm:p-6">
                        <h2 class="text-xl font-bold">Exploración libre</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-600">Entrá directo al sistema demo y conocé las pantallas a tu ritmo.</p>
                        <div class="mt-auto flex items-center justify-between pt-5">
                            <span class="text-xs font-bold uppercase tracking-widest text-slate-500">Simulador en preparación</span>
                            <span class="inline-flex h-11 w-11 items-center justify-center rounded-xl border border-primary text-xl font-bold text-primary" aria-hidden="true">→</span>
                        </div>
                    </div>
                </article>
            </div>

            {{-- Tarjeta informativa --}}
            <div class="mt-4 rounded-2xl border border-primary p-5 sm:p-6" role="note">
                <h2 class="text-lg font-bold">Cada visitante inicia desde cero</h2>
                <p class="mt-2 text-sm leading-6 text-slate-300">
                    El futuro DEMO utilizará sesiones independientes y datos ficticios: nadie ve información real,
                    ningún cobro afecta a un cliente y ninguna cuenta creada en el DEMO será operativa.
                </p>
            </div>

            @include('cobros.country-cta')

            <div class="mt-6">
                <a href="{{ route('cobros.landing') }}" class="inline-flex min-h-11 items-center rounded-xl border border-white px-4 py-3 text-sm font-bold transition hover:bg-white hover:text-black">← Volver a bienvenida</a>
            </div>
        </section>
    </main>

    <footer class="border-t border-white py-6">
        <p class="mx-auto max-w-5xl px-4 text-center text-xs text-slate-400 sm:px-6">MVS CobrOS · Profesional por dentro. Sencillo por fuera.</p>
    </footer>
</body>
</html>
