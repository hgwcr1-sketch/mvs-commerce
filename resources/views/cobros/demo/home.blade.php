<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Demo individual de MVS CobrOS: explore con datos de ejemplo en su propio espacio aislado.">
    <meta name="theme-color" content="#D4AF37">
    <title>MVS CobrOS | Su demo personal</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-black text-white antialiased">
    @include('cobros.public-header')

    <main>
        <section class="mx-auto max-w-5xl px-4 py-12 text-center sm:px-6">
            <p class="text-xs font-bold uppercase tracking-widest text-primary">Demo personal · datos de ejemplo</p>
            <h1 class="mt-4 text-3xl font-black sm:text-5xl">Su espacio DEMO está listo</h1>
            <p class="mx-auto mt-5 max-w-2xl text-lg leading-7 text-slate-300">Este es un entorno de práctica privado con datos ficticios. Nada de lo que haga aquí afecta información real ni requiere registro.</p>
            <p class="mt-4 text-sm text-slate-400">Su sesión se conserva mientras no expire. Puede volver atrás y reanudar cuando quiera.</p>
        </section>

        <section class="mx-auto max-w-5xl px-4 pb-6 sm:px-6" aria-label="Opciones de recorrido">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <article class="flex h-full flex-col rounded-2xl border border-primary bg-white p-5 text-black sm:p-6">
                    <img src="{{ asset('images/cobros/welcome/03_ilustracion_demo_guiado_ruta.webp') }}"
                         alt="Ilustración de una ruta de cobro con puntos y banderita"
                         class="mb-5 w-full rounded-xl object-cover object-center aspect-[16/10] sm:aspect-[16/9] lg:aspect-[4/3]" loading="lazy" width="800" height="600">
                    <p class="text-xs font-bold uppercase tracking-widest text-primary">Paso a paso</p>
                    <h2 class="mt-2 text-xl font-bold">Recorrido guiado</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">Le acompañamos por las pantallas clave de MVS CobrOS en un orden sencillo, explicando cada función.</p>
                    <div class="mt-auto pt-5">
                        <form method="POST" action="{{ route('cobros.demo.mode') }}">
                            @csrf
                            <input type="hidden" name="mode" value="guiado">
                            <button type="submit"
                                class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-primary px-4 py-3 text-sm font-bold text-black transition hover:bg-primary-hover hover:text-white">
                                Comenzar recorrido
                            </button>
                        </form>
                    </div>
                </article>

                <article class="flex h-full flex-col rounded-2xl border border-white bg-white p-5 text-black sm:p-6">
                    <img src="{{ asset('images/cobros/welcome/04_demo_libre_laptop.webp') }}"
                         alt="Laptop con el panel de control de MVS CobrOS"
                         class="mb-5 w-full rounded-xl object-cover object-center aspect-[16/10] sm:aspect-[16/9] lg:aspect-[4/3]" loading="lazy" width="800" height="600">
                    <p class="text-xs font-bold uppercase tracking-widest text-black">A su ritmo</p>
                    <h2 class="mt-2 text-xl font-bold">Exploración libre</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">Navegue sin guion por cartera, rutas, cobros y reportes con datos de ejemplo, como si fuera un usuario real.</p>
                    <div class="mt-auto pt-5">
                        <form method="POST" action="{{ route('cobros.demo.mode') }}">
                            @csrf
                            <input type="hidden" name="mode" value="libre">
                            <button type="submit" @if(($mode ?? null) === 'libre') aria-current="true" @endif
                                class="inline-flex min-h-11 w-full items-center justify-center rounded-xl px-4 py-3 text-sm font-bold transition @if(($mode ?? null) === 'libre') bg-primary-hover text-white @else bg-primary text-black hover:bg-primary-hover hover:text-white @endif">
                                @if(($mode ?? null) === 'libre') Explorando… @else Explorar libremente @endif
                            </button>
                        </form>
                    </div>
                </article>
            </div>
        </section>

        {{-- INTEGRACIÓN-05: exploración libre solo si el DEMO está habilitado
             (config + entorno permitido); la ruta responde 404 si no. --}}
        @if (($mode ?? null) === 'libre' && \App\Services\Cobros\CobrosDemoAccess::enabled() && \Illuminate\Support\Facades\Route::has('cobros.demo.free.enter'))
            <section class="mx-auto max-w-5xl px-4 pb-6 sm:px-6" aria-label="Modalidad de exploración">
                <div class="rounded-2xl border border-primary bg-white p-5 text-black sm:p-6">
                    <p class="text-xs font-bold uppercase tracking-widest text-primary">Exploración libre</p>
                    <h2 class="mt-2 text-xl font-bold">¿Cómo quiere explorar?</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">Elija la modalidad que más se parezca a su negocio. Entrará directamente al entorno con datos de ejemplo, sin registro ni pago.</p>
                    <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                        @foreach ([
                            'prestamista' => ['Prestamista', 'Gestione su propia cartera de clientes y cobros.'],
                            'cobrador_independiente' => ['Cobrador independiente', 'Cobre rutas propias con clientes de varios prestamistas.'],
                            'cobrador_vinculado' => ['Cobrador vinculado', 'Opere como cobrador designado por un prestamista.'],
                        ] as $key => [$label, $desc])
                            <form method="POST" action="{{ route('cobros.demo.free.enter') }}">
                                @csrf
                                <input type="hidden" name="modality" value="{{ $key }}">
                                <button type="submit" class="inline-flex min-h-11 w-full flex-col items-center justify-center rounded-xl border border-black px-4 py-3 text-sm font-bold transition hover:bg-black hover:text-white">
                                    <span>{{ $label }}</span>
                                    <span class="mt-1 text-xs font-normal text-slate-600">{{ $desc }}</span>
                                </button>
                            </form>
                        @endforeach
                    </div>
                </div>
            </section>
        @endif

        <section class="mx-auto max-w-5xl px-4 pb-10 sm:px-6" aria-label="Aviso de sesión">
            <div class="rounded-2xl border border-white p-5 sm:p-6">
                <p class="text-sm leading-6 text-slate-300">Su sesión DEMO vence aproximadamente el <span class="font-semibold text-primary">{{ $expiresAt?->format('d/m/Y H:i') }}</span>. Al vencer, este espacio desaparecerá y podrá iniciar uno nuevo desde la bienvenida.</p>
            </div>
        </section>
    </main>

    <footer class="border-t border-white py-6">
        <p class="mx-auto max-w-5xl px-4 text-center text-xs text-slate-400 sm:px-6">MVS CobrOS · Profesional por dentro. Sencillo por fuera.</p>
    </footer>
</body>
</html>
