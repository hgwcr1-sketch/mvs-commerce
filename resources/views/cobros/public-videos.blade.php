<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Videos explicativos de MVS CobrOS: aprenda de forma rápida y sencilla.">
    <meta name="theme-color" content="#D4AF37">
    <title>Videos | MVS CobrOS</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-black text-white antialiased">
    @include('cobros.public-header', ['showLogin' => false])

    <main>
        {{-- PRE-19B-5D — página Videos pública (mobile-first, negro/blanco/dorado) --}}
        <section class="mx-auto max-w-5xl px-4 pt-8 sm:px-6 sm:pt-12" aria-label="Bienvenida videos">
            <p class="text-xs font-bold uppercase tracking-widest text-primary">MVS CobrOS · Aprendizaje</p>
            <h1 class="mt-3 text-3xl font-black tracking-tight sm:text-5xl">Videos <span class="text-primary">explicativos</span></h1>
            <p class="mx-auto mt-3 max-w-2xl text-base leading-6 text-slate-300 sm:text-lg sm:leading-7">
                Aprendé de forma rápida y sencilla.
            </p>
        </section>

        <section class="mx-auto max-w-5xl px-4 py-8 sm:px-6 sm:py-10" aria-label="Catálogo de videos">
            {{-- Buscador funcional (GET ?q= sobre el catálogo del servidor) --}}
            <form method="GET" action="{{ route('cobros.videos') }}" role="search" class="flex flex-col gap-3 sm:flex-row">
                <label class="flex-1">
                    <span class="sr-only">Buscar videos</span>
                    <input
                        type="search"
                        name="q"
                        value="{{ $query }}"
                        placeholder="Buscar videos…"
                        maxlength="80"
                        class="min-h-11 w-full rounded-xl border border-white bg-black px-4 py-3 text-white outline-none placeholder:text-slate-500 focus:border-primary"
                    >
                </label>
                <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-primary px-5 py-3 font-bold text-black transition hover:bg-primary-hover hover:text-white">Buscar</button>
            </form>

            @if ($query !== '')
                <p class="mt-3 text-sm text-slate-300">
                    {{ count($videos) }} resultado(s) para «{{ $query }}».
                    <a href="{{ route('cobros.videos') }}" class="font-bold text-primary underline">Limpiar búsqueda</a>
                </p>
            @endif

            <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                @forelse ($videos as $video)
                    <article class="flex flex-col overflow-hidden rounded-2xl bg-white text-black">
                        <div class="relative">
                            <img
                                src="{{ asset($video['image']) }}"
                                alt="Miniatura del video: {{ $video['title'] }}"
                                class="h-44 w-full object-cover"
                                loading="lazy"
                                decoding="async"
                            >
                            {{-- Botón visual de reproducción (sin contenido ficticio) --}}
                            <span class="pointer-events-none absolute inset-0 flex items-center justify-center" aria-hidden="true">
                                <span class="inline-flex h-14 w-14 items-center justify-center rounded-full bg-primary text-2xl font-bold text-black">▶</span>
                            </span>
                        </div>
                        <div class="flex flex-1 flex-col p-5 sm:p-6">
                            <h2 class="text-xl font-bold">{{ $video['title'] }}</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-600">{{ $video['description'] }}</p>
                            <div class="mt-auto pt-5">
                                <span class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-black px-4 py-3 text-sm font-bold" aria-disabled="true">Próximamente</span>
                            </div>
                        </div>
                    </article>
                @empty
                    <div class="rounded-2xl border border-primary p-5 sm:col-span-2 sm:p-6" role="note">
                        <p class="font-bold">Sin resultados</p>
                        <p class="mt-2 text-sm leading-6 text-slate-300">No encontramos videos para «{{ $query }}». Pruebe con otro término o <a href="{{ route('cobros.videos') }}" class="font-bold text-primary underline">limpie la búsqueda</a>.</p>
                    </div>
                @endforelse
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
