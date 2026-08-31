@extends('layouts.app')

@section('title', 'Importar — Centro de Datos')
@section('description', 'Acceso a las capacidades de importación existentes.')

@section('content')
<div class="mx-auto max-w-6xl space-y-6" data-data-center-imports>
    <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-sm font-semibold uppercase tracking-wide text-amber-700">Centro de Datos</p>
            <h1 class="mt-1 text-2xl font-bold text-slate-800">Importar</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">Estas opciones abren los flujos que ya existen. Cada módulo conserva sus validaciones, permisos y reglas.</p>
        </div>
        <a href="{{ route('data-center.index') }}" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 sm:w-auto">Volver al Centro</a>
    </header>

    @include('data-center._navigation')

    <section aria-labelledby="existing-imports" class="space-y-3">
        <div>
            <h2 id="existing-imports" class="text-lg font-bold text-slate-800">Capacidades disponibles</h2>
            <p class="mt-1 text-sm text-slate-500">Cada flujo conserva las reglas y permisos de su módulo.</p>
        </div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            @can('inventario.ajustar')
                <article class="flex flex-col rounded-2xl border border-cyan-200 bg-cyan-50 p-5 shadow-sm">
                    <span class="w-fit rounded-full bg-cyan-200 px-3 py-1 text-xs font-semibold text-cyan-900">P36 completo</span>
                    <h3 class="mt-3 text-lg font-bold text-slate-800">Migración de inventario</h3>
                    <p class="mt-2 flex-1 text-sm leading-6 text-slate-600">Saldo inicial y Kardex histórico conciliado, trazable e idempotente por origen.</p>
                    <a data-existing-import="inventory-migration" href="{{ route('importaciones.inventario-migracion') }}" class="mt-5 inline-flex min-h-11 items-center justify-center rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white">Migrar inventario</a>
                </article>
            @endcan
            @can('ventas.crear')
                <article class="flex flex-col rounded-2xl border border-violet-200 bg-violet-50 p-5 shadow-sm">
                    <span class="w-fit rounded-full bg-violet-200 px-3 py-1 text-xs font-semibold text-violet-900">P34/P35 completo</span>
                    <h3 class="mt-3 text-lg font-bold text-slate-800">Ventas históricas</h3>
                    <p class="mt-2 flex-1 text-sm leading-6 text-slate-600">Encabezados y líneas para reportes, sin caja, inventario, pagos, CxC ni fidelización.</p>
                    <a data-existing-import="historical-sales" href="{{ route('importaciones.ventas-historicas') }}" class="mt-5 inline-flex min-h-11 items-center justify-center rounded-xl bg-violet-700 px-4 py-2 text-sm font-semibold text-white">Importar ventas</a>
                </article>
            @endcan
            @can('productos.crear')
                <article class="flex flex-col rounded-2xl border border-blue-200 bg-blue-50 p-5 shadow-sm">
                    <span class="w-fit rounded-full bg-blue-200 px-3 py-1 text-xs font-semibold text-blue-900">P33 completo</span>
                    <h3 class="mt-3 text-lg font-bold text-slate-800">Productos Excel</h3>
                    <p class="mt-2 flex-1 text-sm leading-6 text-slate-600">Catálogo, precios y códigos con vista previa; no modifica existencias ni Kardex.</p>
                    <a data-existing-import="products" href="{{ route('importaciones.productos') }}" class="mt-5 inline-flex min-h-11 items-center justify-center rounded-xl bg-blue-700 px-4 py-2 text-sm font-semibold text-white">Importar productos</a>
                </article>
            @endcan

            @can('clientes.crear')
                <article class="flex flex-col rounded-2xl border border-emerald-200 bg-emerald-50 p-5 shadow-sm">
                    <span class="w-fit rounded-full bg-emerald-200 px-3 py-1 text-xs font-semibold text-emerald-900">P32 completo</span>
                    <h3 class="mt-3 text-lg font-bold text-slate-800">Clientes Excel</h3>
                    <p class="mt-2 flex-1 text-sm leading-6 text-slate-600">Plantilla, vista previa, validación por fila y confirmación atómica para la empresa activa.</p>
                    <a data-existing-import="customers" href="{{ route('importaciones.clientes') }}" class="mt-5 inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-4 py-2 text-sm font-semibold text-white">Importar clientes</a>
                </article>
            @endcan

            @can('compras.crear')
                @can('compras.ver')
                    <article class="flex flex-col rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <span class="w-fit rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-800">Existente</span>
                        <h3 class="mt-3 text-lg font-bold text-slate-800">Compras Excel</h3>
                        <p class="mt-2 flex-1 text-sm leading-6 text-slate-600">Carga, revisión y confirmación desde la pantalla actual de Compras.</p>
                        <a data-existing-import="purchase-excel" href="{{ route('compras.index') }}" class="mt-5 inline-flex min-h-11 items-center justify-center rounded-xl bg-slate-800 px-4 py-2 text-sm font-semibold text-white">Ir a Compras</a>
                    </article>
                @endcan

                <article class="flex flex-col rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <span class="w-fit rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-800">Existente</span>
                    <h3 class="mt-3 text-lg font-bold text-slate-800">Compras XML</h3>
                    <p class="mt-2 flex-1 text-sm leading-6 text-slate-600">Inicia el flujo XML actual y continúa en la misma revisión de Compras.</p>
                    <a data-existing-import="purchase-xml" href="{{ route('compras.import.xml.create') }}" class="mt-5 inline-flex min-h-11 items-center justify-center rounded-xl bg-slate-800 px-4 py-2 text-sm font-semibold text-white">Importar XML</a>
                </article>
            @endcan

            @can('inventario.ver')
                <article class="flex flex-col rounded-2xl border border-amber-200 bg-amber-50 p-5 shadow-sm">
                    <span class="w-fit rounded-full bg-amber-200 px-3 py-1 text-xs font-semibold text-amber-900">Prototipo existente</span>
                    <h3 class="mt-3 text-lg font-bold text-slate-800">Inventario</h3>
                    <p class="mt-2 flex-1 text-sm leading-6 text-slate-600">Acceso al flujo actual. Su blindaje corresponde a D03; no use datos reales MYM todavía.</p>
                    <a data-existing-import="inventory" href="{{ route('importaciones.inventario') }}" class="mt-5 inline-flex min-h-11 items-center justify-center rounded-xl border border-amber-400 bg-white px-4 py-2 text-sm font-semibold text-amber-900">Abrir Inventario</a>
                </article>
            @endcan
        </div>
    </section>
</div>
@endsection
