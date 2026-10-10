<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="MVS CobrOS: cobranza profesional para prestamistas y cobradores. Regístrese, pruebe la demo o vea los videos.">
    <meta name="theme-color" content="#D4AF37">
    <title>MVS CobrOS | Cobranza profesional</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-black text-white antialiased">
    @include('cobros.public-header', ['showLogin' => false])

    <main>
        {{-- PRE-19B-5A — Bienvenida pública (mobile-first, negro/blanco/dorado) --}}
        <section class="mx-auto max-w-5xl px-4 pt-8 text-center sm:px-6 sm:pt-12" aria-label="Bienvenida">
            <p class="text-xs font-bold uppercase tracking-widest text-primary">Cobranza profesional</p>
            <h1 class="mt-3 text-3xl font-black tracking-tight sm:text-5xl">Bienvenidos a <span class="text-primary">MVS CobrOS</span></h1>
            <p class="mx-auto mt-3 max-w-2xl text-base leading-6 text-slate-300 sm:text-lg sm:leading-7">
                Tu gestión de cobros, más sencilla.
            </p>

            {{-- Imagen protagonista aprobada (LEEME): laptop de bienvenida.
                 Sin deformaciones: ancho fluido + alto automático (proporción
                 original 1448×1086 conservada). --}}
            <div class="mt-8 flex justify-center">
                <img
                    src="{{ asset('images/cobros/welcome/02_hero_bienvenida_laptop.webp') }}"
                    alt="MVS CobrOS en una laptop: panel de cobranza con cartera, cobros y rutas"
                    class="h-auto w-full max-w-2xl rounded-2xl"
                    width="1448"
                    height="1086"
                    loading="eager"
                    decoding="async"
                >
            </div>
        </section>

        <section class="mx-auto max-w-5xl px-4 py-8 sm:px-6 sm:py-10" x-data="{ modalityOpen: false }" aria-label="Accesos principales">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {{-- 1. Iniciar sesión --}}
                <article class="flex flex-col rounded-2xl border border-primary bg-white p-5 text-black sm:p-6">
                    <p class="text-xs font-bold uppercase tracking-widest text-primary">¿Ya tiene cuenta?</p>
                    <h2 class="mt-2 text-xl font-bold">Iniciar sesión</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">Acceda con su usuario y contraseña al panel de cobranza.</p>
                    <div class="mt-auto pt-5">
                        <a href="{{ route('cobros.login') }}" class="inline-flex min-h-14 w-full items-center justify-center rounded-xl bg-primary px-4 py-4 text-base font-bold text-black transition hover:bg-primary-hover hover:text-white">Iniciar sesión</a>
                    </div>
                </article>

                {{-- 2. Crear cuenta (abre selección de 3 modalidades) --}}
                <article class="flex flex-col rounded-2xl bg-white p-5 text-black sm:p-6">
                    <p class="text-xs font-bold uppercase tracking-widest text-black">Empiece aquí</p>
                    <h2 class="mt-2 text-xl font-bold">Crear cuenta</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">Elija cómo va a usar CobrOS y cree su espacio de trabajo paso a paso.</p>
                    <div class="mt-auto pt-5">
                        <button type="button" @click="modalityOpen = true" class="inline-flex min-h-14 w-full items-center justify-center rounded-xl bg-primary px-4 py-4 text-base font-bold text-black transition hover:bg-primary-hover hover:text-white">Crear cuenta</button>
                    </div>
                </article>

                {{-- 3. Probar DEMO → página pública PRE-19B-5D --}}
                <article class="flex flex-col rounded-2xl border border-white bg-white p-5 text-black sm:p-6">
                    <p class="text-xs font-bold uppercase tracking-widest text-black">Conozca el producto</p>
                    <h2 class="mt-2 text-xl font-bold">Probar DEMO</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">Recorra MVS CobrOS con datos de ejemplo, sin tocar información real.</p>
                    <div class="mt-auto pt-5">
                        <a href="{{ route('cobros.demo') }}" class="inline-flex min-h-14 w-full items-center justify-center rounded-xl bg-primary px-4 py-4 text-base font-bold text-black transition hover:bg-primary-hover hover:text-white">Probar DEMO</a>
                    </div>
                </article>

                {{-- 4. Ver videos explicativos → página pública PRE-19B-5D --}}
                <article class="flex flex-col rounded-2xl border border-white bg-white p-5 text-black sm:p-6">
                    <p class="text-xs font-bold uppercase tracking-widest text-black">Aprenda a su ritmo</p>
                    <h2 class="mt-2 text-xl font-bold">Ver videos explicativos</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">Videos cortos para dominar cada módulo de la plataforma.</p>
                    <div class="mt-auto pt-5">
                        <a href="{{ route('cobros.videos') }}" class="inline-flex min-h-14 w-full items-center justify-center rounded-xl bg-primary px-4 py-4 text-base font-bold text-black transition hover:bg-primary-hover hover:text-white">Ver videos</a>
                    </div>
                </article>
            </div>

            {{-- Selección de modalidad (solo 3) → registro existente --}}
            <div x-cloak x-show="modalityOpen" class="fixed inset-0 z-50 flex items-end justify-center sm:items-center" role="dialog" aria-modal="true" aria-labelledby="modality-title">
                <div class="absolute inset-0 bg-black/70" @click="modalityOpen = false" aria-hidden="true"></div>
                <div class="relative z-10 max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-t-2xl bg-white p-5 text-black sm:rounded-2xl sm:p-6">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-widest text-primary">Crear cuenta</p>
                            <h2 id="modality-title" class="mt-1 text-xl font-bold">Elija su modalidad</h2>
                            <p class="mt-1 text-sm text-slate-600">Así lo configuraremos al registrarlo. Podrá operar según el rol que elija.</p>
                        </div>
                        <button type="button" @click="modalityOpen = false" aria-label="Cerrar" class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-black text-lg font-bold">✕</button>
                    </div>

                    <ul class="mt-4 space-y-3">
                        <li>
                            <a href="{{ route('cobros.register', ['modality' => 'prestamista']) }}" class="flex min-h-14 items-center justify-between rounded-xl border border-black px-4 py-3 font-semibold transition hover:bg-black hover:text-white">
                                <span>Prestamista</span>
                                <span aria-hidden="true">→</span>
                            </a>
                        </li>
                        <li>
                            <a href="{{ route('cobros.register', ['modality' => 'cobrador_independiente']) }}" class="flex min-h-14 items-center justify-between rounded-xl border border-black px-4 py-3 font-semibold transition hover:bg-black hover:text-white">
                                <span>Cobrador independiente</span>
                                <span aria-hidden="true">→</span>
                            </a>
                        </li>
                        <li>
                            <a href="{{ route('cobros.register', ['modality' => 'cobrador_vinculado']) }}" class="flex min-h-14 items-center justify-between rounded-xl border border-black px-4 py-3 font-semibold transition hover:bg-black hover:text-white">
                                <span>Trabajo para un prestamista</span>
                                <span aria-hidden="true">→</span>
                            </a>
                        </li>
                    </ul>

                    <p class="mt-4 text-xs leading-5 text-slate-500">Si llegó por un enlace de referido o de un prestamista, se conserva automáticamente durante el registro.</p>
                </div>
            </div>
        </section>

        <section class="mx-auto max-w-5xl px-4 pb-10 sm:px-6" aria-label="Países disponibles">
            <div class="rounded-2xl border border-primary p-5 sm:p-6">
                <h2 class="text-lg font-bold">Países disponibles</h2>
                <div class="mt-4 flex flex-wrap gap-3">
                    @forelse(($countries ?? []) as $country)
                        <span class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-primary px-4 py-2 font-semibold">
                            {{ $country['name'] }}
                            <span class="text-xl leading-none" aria-hidden="true">{{ $country['flag'] }}</span>
                        </span>
                    @empty
                        <span class="text-sm text-slate-300">Países en preparación.</span>
                    @endforelse
                </div>
                <p class="mt-4 text-sm leading-6 text-slate-300">¿Su país aún no está en la lista? Los demás países podrán solicitar su habilitación próximamente.</p>
                @include('cobros.country-cta')
            </div>
        </section>
    </main>

    <footer class="border-t border-white py-6">
        <p class="mx-auto max-w-5xl px-4 text-center text-xs text-slate-400 sm:px-6">MVS CobrOS · Profesional por dentro. Sencillo por fuera.</p>
    </footer>
</body>
</html>
