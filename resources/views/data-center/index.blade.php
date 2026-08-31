@extends('layouts.app')

@section('title', 'Centro de Datos')
@section('description', 'Entrada central para importar, exportar y consultar reportes.')

@section('content')
<div class="mx-auto max-w-6xl space-y-6" data-data-center-shell>
    <header>
        <p class="text-sm font-semibold uppercase tracking-wide text-amber-700">Administración de información</p>
        <h1 class="mt-1 text-2xl font-bold text-slate-800 sm:text-3xl">Centro de Datos</h1>
        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600 sm:text-base">Un solo punto para mover y consultar información sin duplicar las reglas de los módulos operativos.</p>
    </header>

    @include('data-center._navigation')

    <section aria-labelledby="data-center-options" class="grid grid-cols-1 gap-4 md:grid-cols-3">
        <h2 id="data-center-options" class="sr-only">Opciones del Centro de Datos</h2>

        @canany(['compras.crear', 'clientes.crear', 'productos.crear', 'inventario.ver'])
            <a href="{{ route('data-center.imports') }}" class="group flex min-h-44 flex-col rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-amber-300 hover:shadow-md sm:p-6">
                <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-emerald-100 text-xl text-emerald-700" aria-hidden="true">↓</span>
                <h2 class="mt-4 text-lg font-bold text-slate-800">Importar</h2>
                <p class="mt-2 flex-1 text-sm leading-6 text-slate-600">Acceda a los flujos de Clientes, Productos, Compras e Inventario.</p>
                <span class="mt-4 text-sm font-semibold text-amber-700 group-hover:text-amber-800">Ver capacidades →</span>
            </a>
        @endcanany

        @can('reportes.exportar')
            <a href="{{ route('data-center.exports') }}" class="group flex min-h-44 flex-col rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-amber-300 hover:shadow-md sm:p-6">
                <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-blue-100 text-xl text-blue-700" aria-hidden="true">↑</span>
                <h2 class="mt-4 text-lg font-bold text-slate-800">Exportar</h2>
                <p class="mt-2 flex-1 text-sm leading-6 text-slate-600">Espacio reservado para exportadores confiables y conciliables.</p>
                <span class="mt-4 text-sm font-semibold text-amber-700 group-hover:text-amber-800">Abrir espacio →</span>
            </a>
        @endcan

        @can('reportes.ver')
            <a href="{{ route('data-center.reports') }}" class="group flex min-h-44 flex-col rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-amber-300 hover:shadow-md sm:p-6">
                <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-violet-100 text-xl text-violet-700" aria-hidden="true">▥</span>
                <h2 class="mt-4 text-lg font-bold text-slate-800">Reportes</h2>
                <p class="mt-2 flex-1 text-sm leading-6 text-slate-600">Entrada al futuro Centro de Reportes, sin consultas paralelas.</p>
                <span class="mt-4 text-sm font-semibold text-amber-700 group-hover:text-amber-800">Abrir espacio →</span>
            </a>
        @endcan
    </section>
</div>
@endsection
