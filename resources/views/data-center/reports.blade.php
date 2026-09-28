@extends('layouts.app')

@section('title', 'Reportes — Centro de Datos')
@section('description', 'Centro de reportes operativos de MVS Commerce.')

@section('content')
<div class="mx-auto max-w-6xl space-y-6" data-report-center>
    <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-sm font-semibold uppercase tracking-wide text-amber-700">Centro de Datos</p>
            <h1 class="mt-1 text-2xl font-bold text-slate-800">Reportes</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">Información operativa basada en los datos reales de la empresa activa.</p>
        </div>
        <a href="{{ route('data-center.index') }}" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 sm:w-auto">Volver al Centro</a>
    </header>

    @include('data-center._navigation')

    @if($categories->isEmpty())
        <section class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">Tiene acceso a Reportes, pero necesita permiso de lectura sobre al menos un módulo operativo.</section>
    @else
        <section class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3" aria-label="Categorías de reportes">
            @foreach($categories as $key => $category)
                <a href="{{ route('data-center.reports.show', $key) }}" class="group flex min-h-48 flex-col rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-amber-300 hover:shadow-md" data-report-category="{{ $key }}">
                    <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-amber-100 font-bold text-amber-800" aria-hidden="true">↗</span>
                    <h2 class="mt-4 text-lg font-bold text-slate-800">{{ $category['label'] }}</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ $category['description'] }}</p>
                    <span class="mt-auto pt-4 text-sm font-semibold text-amber-700">Abrir reporte</span>
                </a>
            @endforeach
        </section>
    @endif
</div>
@endsection
