@extends('cobros.layout')

@section('title', $track['title'].' — Academia MVS CobrOS')
@section('description', $track['description'])

@section('content')
{{-- BLOQUE 21A-2: temario de UNA ruta de capacitación (solo lectura). --}}
<div class="mx-auto max-w-4xl space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-primary-hover">Academia MVS CobrOS</p>
            <h1 class="text-2xl font-bold text-slate-900">{{ $track['title'] }}</h1>
            <p class="mt-1 break-words text-sm text-slate-500">{{ $company->trade_name }}</p>
        </div>
        <a href="{{ route('cobros.academia.index') }}"
           class="inline-flex min-h-[44px] items-center gap-2 text-sm font-semibold text-slate-700 hover:text-slate-900">
            ← VOLVER
        </a>
    </div>

    <p class="rounded-xl border border-slate-200 bg-white p-3 text-sm text-slate-600">{{ $track['summary'] }}</p>

    @if($currentTrack === $track['key'])
        <p role="status" class="rounded-xl border border-primary bg-amber-50 p-3 text-sm font-semibold text-slate-900">
            Esta ruta corresponde a tu modalidad actual.
        </p>
    @endif

    <section aria-label="Lecciones" class="space-y-3">
        @foreach($track['lessons'] as $index => $lesson)
            <article id="lesson-{{ $index + 1 }}"
                     class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <h2 class="text-base font-semibold text-slate-900">
                        <span class="mr-2 inline-flex h-6 w-6 items-center justify-center rounded-full bg-primary text-xs font-bold text-white">{{ $index + 1 }}</span>
                        {{ $lesson['title'] }}
                    </h2>
                    @if(! empty($lesson['duration']))
                        <span class="shrink-0 rounded-full bg-slate-100 px-2 py-1 text-xs font-medium text-slate-600">{{ $lesson['duration'] }}</span>
                    @endif
                </div>

                <p class="mt-2 text-sm text-slate-600">{{ $lesson['description'] }}</p>

                @if(! empty($lesson['video_url']))
                    {{-- Reproductor HTML5 listo: solo se renderiza cuando existe un video publicado. --}}
                    <video class="mt-3 w-full rounded-lg bg-black" controls preload="none"
                           poster="{{ $lesson['poster_url'] ?? '' }}" playsinline>
                        <source src="{{ $lesson['video_url'] }}" type="video/mp4">
                        Tu navegador no reproduce video HTML5.
                    </video>
                @else
                    <p class="mt-3 flex flex-wrap items-center gap-2">
                        <span class="rounded-full border border-amber-300 bg-amber-50 px-3 py-1 text-xs font-bold uppercase tracking-wide text-amber-800">Próximamente</span>
                        <span class="text-xs text-slate-500">Video en preparación. Todavía no hay contenido para reproducir.</span>
                    </p>
                @endif
            </article>
        @endforeach
    </section>

    <nav aria-label="Otras rutas de capacitación" class="flex flex-wrap gap-2">
        @foreach($tracks as $other)
            @if($other['key'] !== $track['key'])
                <a href="{{ route('cobros.academia.show', ['track' => $other['key']]) }}"
                   class="inline-flex min-h-[44px] items-center rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:border-primary hover:text-slate-900">
                    {{ $other['title'] }}
                </a>
            @endif
        @endforeach
    </nav>
</div>
@endsection
