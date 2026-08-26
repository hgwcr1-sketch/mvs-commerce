@extends('layouts.app')

@section('title', 'Reportes — Centro de Datos')
@section('description', 'Entrada base al futuro Centro de Reportes.')

@section('content')
<div class="mx-auto max-w-6xl space-y-6" data-data-center-reports>
    <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div><p class="text-sm font-semibold uppercase tracking-wide text-amber-700">Centro de Datos</p><h1 class="mt-1 text-2xl font-bold text-slate-800">Reportes</h1><p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">Entrada al futuro Centro de Reportes operativos.</p></div>
        <a href="{{ route('data-center.index') }}" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 sm:w-auto">Volver al Centro</a>
    </header>
    @include('data-center._navigation')
    <section class="rounded-2xl border border-dashed border-slate-300 bg-white p-6 text-center sm:p-10" aria-labelledby="reports-status">
        <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-violet-100 text-xl text-violet-700" aria-hidden="true">▥</span>
        <h2 id="reports-status" class="mt-4 text-lg font-bold text-slate-800">Centro de Reportes en preparación</h2>
        <p class="mx-auto mt-2 max-w-xl text-sm leading-6 text-slate-600">Los reportes esenciales corresponden a D10 y reutilizarán las consultas de Ventas, Caja, Inventario, Compras, Clientes y Fidelización.</p>
    </section>
</div>
@endsection
