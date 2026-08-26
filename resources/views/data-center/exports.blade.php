@extends('layouts.app')

@section('title', 'Exportar — Centro de Datos')
@section('description', 'Espacio base para futuras exportaciones de datos.')

@section('content')
<div class="mx-auto max-w-6xl space-y-6" data-data-center-exports>
    <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div><p class="text-sm font-semibold uppercase tracking-wide text-amber-700">Centro de Datos</p><h1 class="mt-1 text-2xl font-bold text-slate-800">Exportar</h1><p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">Aquí se reunirán los exportadores esenciales definidos para D09.</p></div>
        <a href="{{ route('data-center.index') }}" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 sm:w-auto">Volver al Centro</a>
    </header>
    @include('data-center._navigation')
    <section class="rounded-2xl border border-dashed border-slate-300 bg-white p-6 text-center sm:p-10" aria-labelledby="exports-status">
        <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-blue-100 text-xl text-blue-700" aria-hidden="true">↑</span>
        <h2 id="exports-status" class="mt-4 text-lg font-bold text-slate-800">Espacio preparado</h2>
        <p class="mx-auto mt-2 max-w-xl text-sm leading-6 text-slate-600">D02 no genera archivos de negocio. Los exportadores se implementarán en D09 con columnas estables y aislamiento por empresa y sucursal.</p>
    </section>
</div>
@endsection
