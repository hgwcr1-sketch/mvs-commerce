@extends('layouts.app')

@section('title', 'Importar clientes')
@section('description', 'Carga segura de clientes desde Excel o CSV')

@section('content')
<div class="mx-auto max-w-4xl space-y-6" data-customer-import>
    <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-sm font-semibold uppercase tracking-wide text-amber-700">Centro de Datos · P32</p>
            <h1 class="mt-1 text-2xl font-bold text-slate-800">Importar clientes</h1>
            <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">Revise todas las filas antes de confirmar. Los clientes pertenecen a la empresa activa y no requieren sucursal.</p>
        </div>
        <a href="{{ route('data-center.imports') }}" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 sm:w-auto">Volver</a>
    </header>

    @if($errors->any())
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
            <p class="font-semibold">No se pudo procesar el archivo.</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="font-bold text-slate-800">1. Use la plantilla vigente</h2>
                <p class="mt-1 text-sm text-slate-600">Tipos: individual/company; identificación: 01–05; nivel: normal/wholesale/a/b/c; fechas: AAAA-MM-DD.</p>
            </div>
            <a href="{{ route('importaciones.clientes.template') }}" class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-xl bg-slate-800 px-4 py-2 text-sm font-semibold text-white">Descargar plantilla Excel</a>
        </div>

        <form action="{{ route('importaciones.clientes.preview') }}" method="POST" enctype="multipart/form-data" class="mt-6 space-y-4">
            @csrf
            <div>
                <label for="customer_file" class="mb-2 block text-sm font-semibold text-slate-700">2. Archivo de clientes</label>
                <input id="customer_file" name="customer_file" type="file" accept=".xlsx,.xls,.csv" required class="block min-h-11 w-full rounded-xl border border-slate-300 bg-white p-3 text-sm">
                <p class="mt-2 text-xs text-slate-500">Máximo 10 MB. La vista previa no escribe datos.</p>
            </div>
            <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-amber-500 px-5 py-3 text-sm font-bold text-white sm:w-auto">Revisar archivo</button>
        </form>
    </section>
</div>
@endsection
