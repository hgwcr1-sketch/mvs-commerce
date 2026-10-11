@extends('cobros.layout')

@section('title', 'Academia MVS CobrOS — MVS CobrOS')
@section('description', 'Capacitación de MVS CobrOS por modalidad: prestamista administrador, cobrador independiente y cobrador vinculado.')

@section('content')
{{-- BLOQUE 21A-2: listado de las TRES rutas de capacitación de la Academia. --}}
<div class="mx-auto max-w-5xl space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Academia MVS CobrOS</h1>
            <p class="mt-1 break-words text-sm text-slate-500">{{ $company->trade_name }}</p>
        </div>
        <a href="{{ auth()->user()->hasPermission('cobros.portal_dueno', $company) ? route('cobros.owner.dashboard') : (auth()->user()->hasPermission('cobros.cobrar', $company) ? route('cobros.collector.index') : route('cobros.academia.index')) }}"
           class="inline-flex min-h-[44px] items-center gap-2 text-sm font-semibold text-slate-700 hover:text-slate-900">
            ← VOLVER
        </a>
    </div>

    <p class="rounded-xl border border-slate-200 bg-white p-3 text-sm text-slate-600">
        Elegí tu modalidad para ver el temario de capacitación. El material en preparación se marca como
        <strong class="text-slate-900">Próximamente</strong>.
    </p>

    @if($currentModality === null)
        <p class="rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800" role="status">
            Aún no se detecta una modalidad de CobrOS para tu cuenta en esta empresa. Podés recorrer las tres rutas
            de capacitación y revisar cuál coincide con tu trabajo.
        </p>
    @endif

    <section aria-label="Rutas de capacitación" class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
        @foreach($tracks as $track)
            <article id="track-{{ $track['key'] }}"
                     class="flex flex-col rounded-xl border {{ $currentTrack === $track['key'] ? 'border-primary' : 'border-slate-200' }} bg-white p-4 shadow-sm">
                <div class="flex items-start justify-between gap-2">
                    <h2 class="text-lg font-semibold text-slate-900">{{ $track['title'] }}</h2>
                    @if($currentTrack === $track['key'])
                        <span class="shrink-0 rounded-full bg-primary px-2 py-1 text-xs font-bold text-white">Tu modalidad</span>
                    @endif
                </div>
                <p class="mt-2 flex-1 text-sm text-slate-600">{{ $track['description'] }}</p>
                <p class="mt-3 text-xs font-medium uppercase tracking-wide text-slate-500">
                    {{ count($track['lessons']) }} lecciones · {{ \App\Services\Cobros\AcademiaCatalog::hasVideo($track) ? 'video disponible' : 'videos próximamente' }}
                </p>
                <a href="{{ route('cobros.academia.show', ['track' => $track['key']]) }}"
                   class="mt-3 inline-flex min-h-[44px] items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover focus:outline-none focus:ring-2 focus:ring-primary">
                    Ver temario
                </a>
            </article>
        @endforeach
    </section>
</div>
@endsection
