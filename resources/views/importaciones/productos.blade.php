@extends('layouts.app')

@section('title', 'Importar productos')
@section('description', 'Migración segura del catálogo de productos')

@section('content')
<div class="mx-auto max-w-4xl space-y-6" data-product-import>
    <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-sm font-semibold uppercase tracking-wide text-amber-700">Centro de Datos · P33</p>
            <h1 class="mt-1 text-2xl font-bold text-slate-800">Importar productos</h1>
            <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">Importa catálogo, precios y códigos para la empresa activa. No crea existencias ni movimientos de inventario.</p>
        </div>
        <a href="{{ route('data-center.imports') }}" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 sm:w-auto">Volver</a>
    </header>

    @if($errors->any())
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
            <p class="font-semibold">No se pudo procesar el archivo.</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="font-bold text-slate-800">1. Descargue la plantilla vigente</h2>
                <p class="mt-1 text-sm text-slate-600">Categoría, marca y unidad deben existir en la empresa. Separe códigos adicionales con |.</p>
            </div>
            <a href="{{ route('importaciones.productos.template') }}" class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-xl bg-slate-800 px-4 py-2 text-sm font-semibold text-white">Descargar plantilla Excel</a>
        </div>

        <form action="{{ route('importaciones.productos.preview') }}" method="POST" enctype="multipart/form-data" class="mt-6 space-y-4">
            @csrf
            <div>
                <label for="product_file" class="mb-2 block text-sm font-semibold text-slate-700">2. Archivo de productos</label>
                <input id="product_file" name="product_file" type="file" accept=".xlsx,.xls,.csv" required class="block min-h-11 w-full rounded-xl border border-slate-300 bg-white p-3 text-sm">
                <p class="mt-2 text-xs text-slate-500">Máximo 10 MB. XLSX, XLS o CSV. El preview no escribe datos.</p>
            </div>
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                Stock inicial, mínimos/máximos por sucursal y Kardex pertenecen a P36 y no forman parte de esta plantilla.
            </div>
            <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-amber-500 px-5 py-3 text-sm font-bold text-white sm:w-auto">Revisar archivo</button>
        </form>
    </section>
</div>
@endsection
